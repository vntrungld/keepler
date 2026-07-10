# SP3 — Gmail Auto-Detection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a signed-in user connect Gmail, scan for known subscription providers rule-based, review detected candidates, and bulk-import them (creating new subscriptions or marking cancelled ones) — all scoped to the user.

**Architecture:** A `config/providers.php` catalog drives detection. Framework-free-ish PHP units (`ProviderMatcher`, `ReceiptParser`) do pure matching/parsing; `GmailClient` wraps the Gmail REST API over Laravel's HTTP client (mockable with `Http::fake()`) and refreshes tokens; `SubscriptionScanner` orchestrates them into candidate rows with dedup actions. `GmailController` handles the OAuth connect/callback/disconnect plus scan/import; a Vue `ScanResults` page reviews and imports.

**Tech Stack:** Laravel 13, Laravel Socialite (Google), Laravel HTTP client, Inertia/Vue 3, PHPUnit (class style), SQLite (dev).

## Global Constraints

- **Tests use PHPUnit class style** (`extends Tests\TestCase`), NOT Pest.
- Gmail scope is `https://www.googleapis.com/auth/gmail.readonly`, Testing mode (no prod verification in scope).
- Gmail tokens live on `users`: `gmail_access_token`, `gmail_refresh_token` (both `encrypted` cast **and** in the model's `#[Hidden]` — they must never reach the Inertia-shared `auth.user`), `gmail_token_expires_at` (`datetime`).
- Provider catalog is `config/providers.php`; each provider `key` matches the SP2 brand-color catalog keys (netflix, spotify, youtube, chatgpt, openai, adobe, apple, google, amazon, prime, disney).
- Scan is **on-demand** (button); no background/scheduled scan and no Pub/Sub webhook in SP3 (that is SP4/SP5 per spec §11b).
- Extraction is **best-effort**; uncertain fields are `null` and the user always confirms before saving.
- Email intent is classified `payment` vs `cancellation`; latest email per provider+cycle wins.
- Dedup actions: `create` (new), `skip` (payment already tracked / cancellation with no match), `update_status` (cancellation matching an existing non-cancelled sub → set `cancelled`).
- All reads/writes scoped to the authenticated user; reuse SP1 CRUD validation rules for imported subscriptions.
- Do NOT modify existing SP1/SP2 migrations — add NEW migrations for schema changes.
- Remove `user_id` from `Subscription::$fillable` (import uses the `subscriptions()` relation, which sets the FK directly); add `provider_key` to `$fillable`.
- `@` alias = `resources/js`. Do NOT stage `app/Support/CLAUDE.md` (auto-generated clutter).

---

## File Structure

**Create:**
- `database/migrations/2026_07_10_000001_add_gmail_tokens_to_users_table.php`
- `database/migrations/2026_07_10_000002_add_provider_key_to_subscriptions_table.php`
- `config/providers.php` — provider catalog.
- `app/Support/Gmail/ProviderMatcher.php` — sender/subject → provider key.
- `app/Support/Gmail/ReceiptParser.php` — email → {intent, amount, currency, cycle, renewal, confidence}.
- `app/Support/Gmail/GmailClient.php` — Gmail REST wrapper + token refresh.
- `app/Support/Gmail/SubscriptionScanner.php` — orchestrator → candidates + dedup actions.
- `app/Http/Controllers/GmailController.php` — connect/callback/disconnect/scan/import.
- `app/Http/Requests/GmailImportRequest.php` — validate import payload.
- `resources/js/Pages/Gmail/ScanResults.vue` — review + import UI.
- Test files under `tests/Unit/Gmail/` and `tests/Feature/Gmail/`.

**Modify:**
- `app/Models/User.php` — token casts + hidden + `hasGmailConnected()`.
- `app/Models/Subscription.php` — `$fillable` (drop `user_id`, add `provider_key`).
- `routes/web.php` — Gmail routes.
- `resources/js/Pages/Dashboard.vue` — Connect/Scan/Disconnect buttons.

---

## Task 1: Schema + model changes (tokens, provider_key, fillable cleanup)

**Files:**
- Create: `database/migrations/2026_07_10_000001_add_gmail_tokens_to_users_table.php`
- Create: `database/migrations/2026_07_10_000002_add_provider_key_to_subscriptions_table.php`
- Modify: `app/Models/User.php`, `app/Models/Subscription.php`
- Test: `tests/Feature/Gmail/GmailModelTest.php`

**Interfaces:**
- Consumes: existing `User`, `Subscription` models.
- Produces:
  - `users.gmail_access_token`, `users.gmail_refresh_token` (text, nullable, `encrypted` cast, `#[Hidden]`), `users.gmail_token_expires_at` (timestamp nullable, `datetime` cast).
  - `User::hasGmailConnected(): bool`.
  - `subscriptions.provider_key` (string nullable, indexed, in `$fillable`); `user_id` removed from `$fillable`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Gmail/GmailModelTest.php`:

```php
<?php

namespace Tests\Feature\Gmail;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GmailModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_gmail_tokens_are_encrypted_at_rest_and_hidden_from_array(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'gmail_access_token' => 'access-123',
            'gmail_refresh_token' => 'refresh-456',
            'gmail_token_expires_at' => now()->addHour(),
        ])->save();

        // Raw DB value is not the plaintext (encrypted at rest).
        $raw = DB::table('users')->where('id', $user->id)->value('gmail_refresh_token');
        $this->assertNotSame('refresh-456', $raw);

        // Cast decrypts on read.
        $this->assertSame('refresh-456', $user->fresh()->gmail_refresh_token);

        // Never leaks to serialized output (Inertia shares auth.user).
        $array = $user->fresh()->toArray();
        $this->assertArrayNotHasKey('gmail_access_token', $array);
        $this->assertArrayNotHasKey('gmail_refresh_token', $array);
    }

    public function test_has_gmail_connected_reflects_refresh_token_presence(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->hasGmailConnected());

        $user->forceFill(['gmail_refresh_token' => 'refresh-456'])->save();
        $this->assertTrue($user->fresh()->hasGmailConnected());
    }

    public function test_provider_key_is_mass_assignable_but_user_id_is_not(): void
    {
        $user = User::factory()->create();
        $sub = $user->subscriptions()->create([
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => now()->addDays(10)->toDateString(),
            'status' => 'active',
            'provider_key' => 'netflix',
        ]);
        $this->assertSame('netflix', $sub->provider_key);
        $this->assertSame($user->id, $sub->user_id);

        // user_id is guarded: a raw mass-assign attempt must not set it.
        $rogue = new Subscription(['user_id' => 999]);
        $this->assertNull($rogue->user_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GmailModelTest`
Expected: FAIL — no `gmail_*` columns / `hasGmailConnected` undefined / `provider_key` column missing.

- [ ] **Step 3: Create the users migration**

Create `database/migrations/2026_07_10_000001_add_gmail_tokens_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('gmail_access_token')->nullable();
            $table->text('gmail_refresh_token')->nullable();
            $table->timestamp('gmail_token_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gmail_access_token', 'gmail_refresh_token', 'gmail_token_expires_at']);
        });
    }
};
```

- [ ] **Step 4: Create the subscriptions migration**

Create `database/migrations/2026_07_10_000002_add_provider_key_to_subscriptions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('provider_key')->nullable()->index()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('provider_key');
        });
    }
};
```

- [ ] **Step 5: Update the User model**

In `app/Models/User.php`: add the three token attributes to `#[Hidden]`, and add the casts + helper.

Change the Hidden attribute:

```php
#[Hidden(['password', 'remember_token', 'gmail_access_token', 'gmail_refresh_token'])]
```

In the `casts()` method, add the three casts to the returned array:

```php
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'gmail_access_token' => 'encrypted',
            'gmail_refresh_token' => 'encrypted',
            'gmail_token_expires_at' => 'datetime',
        ];
    }
```

Add the helper method to the class body (after the `subscriptions()` relation):

```php
    public function hasGmailConnected(): bool
    {
        return ! empty($this->gmail_refresh_token);
    }
```

- [ ] **Step 6: Update the Subscription model fillable**

In `app/Models/Subscription.php`, replace the `$fillable` array — remove `'user_id'`, add `'provider_key'`:

```php
    protected $fillable = [
        'name',
        'provider_key',
        'amount',
        'currency',
        'billing_cycle',
        'next_renewal_date',
        'status',
        'cancel_url',
        'notes',
    ];
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=GmailModelTest`
Expected: PASS — 3 tests green.

Then run the full suite to confirm no regression (factory sets `user_id` in an unguarded factory context, so existing tests still pass):

Run: `php artisan test`
Expected: PASS — full suite green.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_10_000001_add_gmail_tokens_to_users_table.php database/migrations/2026_07_10_000002_add_provider_key_to_subscriptions_table.php app/Models/User.php app/Models/Subscription.php tests/Feature/Gmail/GmailModelTest.php
git commit -m "Update: add Gmail token columns and subscription provider_key

Add encrypted, hidden Gmail token columns to users (with
hasGmailConnected helper) and a provider_key to subscriptions via new
migrations. Remove user_id from Subscription::\$fillable (imports use
the subscriptions() relation) and add provider_key.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 2: Provider catalog + ProviderMatcher

**Files:**
- Create: `config/providers.php`
- Create: `app/Support/Gmail/ProviderMatcher.php`
- Test: `tests/Unit/Gmail/ProviderMatcherTest.php`

**Interfaces:**
- Consumes: `config('providers')`.
- Produces:
  - `config('providers')`: `array<string key, array{name, sender_domains[], payment_keywords[], cancellation_keywords[], amount_regex, default_currency, default_cycle, cancel_url}>`.
  - `ProviderMatcher::match(string $from, string $subject): ?string` — returns the provider key or null. Static, pure, reads the catalog.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Gmail/ProviderMatcherTest.php`:

```php
<?php

namespace Tests\Unit\Gmail;

use App\Support\Gmail\ProviderMatcher;
use Tests\TestCase;

class ProviderMatcherTest extends TestCase
{
    public function test_matches_by_sender_domain_case_insensitively(): void
    {
        $this->assertSame('netflix', ProviderMatcher::match('info@members.netflix.com', 'Your receipt'));
        $this->assertSame('netflix', ProviderMatcher::match('INFO@NETFLIX.COM', 'RECEIPT'));
        $this->assertSame('spotify', ProviderMatcher::match('no-reply@spotify.com', 'Payment'));
    }

    public function test_returns_null_when_no_domain_matches(): void
    {
        $this->assertNull(ProviderMatcher::match('billing@randomservice.io', 'Your invoice'));
    }

    public function test_extracts_domain_from_display_name_form(): void
    {
        $this->assertSame('netflix', ProviderMatcher::match('Netflix <info@netflix.com>', 'Receipt'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProviderMatcherTest`
Expected: FAIL — class/config not found.

- [ ] **Step 3: Create the catalog config**

Create `config/providers.php`:

```php
<?php

// Subscription-provider catalog for rule-based Gmail detection.
// Each key MUST match the SP2 brand-color catalog key so imported
// subscriptions render with the right planet color.
return [
    'netflix' => [
        'name' => 'Netflix',
        'sender_domains' => ['netflix.com', 'members.netflix.com'],
        'payment_keywords' => ['receipt', 'payment', 'hóa đơn', 'gia hạn'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'membership ended', 'đã hủy'],
        'amount_regex' => '/(?:US)?\$\s?([0-9][0-9.,]*)/',
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://www.netflix.com/cancelplan',
    ],
    'spotify' => [
        'name' => 'Spotify',
        'sender_domains' => ['spotify.com'],
        'payment_keywords' => ['receipt', 'payment', 'premium'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'subscription ended'],
        'amount_regex' => '/(?:US)?\$\s?([0-9][0-9.,]*)/',
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://www.spotify.com/account/subscription/',
    ],
    'youtube' => [
        'name' => 'YouTube Premium',
        'sender_domains' => ['youtube.com', 'google.com'],
        'payment_keywords' => ['receipt', 'payment', 'youtube premium'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'membership paused'],
        'amount_regex' => '/(?:US)?\$\s?([0-9][0-9.,]*)/',
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://www.youtube.com/paid_memberships',
    ],
    'chatgpt' => [
        'name' => 'ChatGPT Plus',
        'sender_domains' => ['openai.com', 'stripe.com'],
        'payment_keywords' => ['receipt', 'payment', 'chatgpt'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'subscription ended'],
        'amount_regex' => '/(?:US)?\$\s?([0-9][0-9.,]*)/',
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://chatgpt.com/#settings',
    ],
    'google' => [
        'name' => 'Google One',
        'sender_domains' => ['google.com', 'payments.google.com'],
        'payment_keywords' => ['google one', 'receipt', 'payment'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'membership ended'],
        'amount_regex' => '/(?:US)?\$\s?([0-9][0-9.,]*)/',
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://one.google.com/',
    ],
    'adobe' => [
        'name' => 'Adobe',
        'sender_domains' => ['adobe.com', 'mail.adobe.com'],
        'payment_keywords' => ['receipt', 'invoice', 'payment'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'plan cancelled'],
        'amount_regex' => '/(?:US)?\$\s?([0-9][0-9.,]*)/',
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://account.adobe.com/plans',
    ],
    'apple' => [
        'name' => 'Apple',
        'sender_domains' => ['apple.com', 'email.apple.com'],
        'payment_keywords' => ['receipt', 'your invoice', 'subscription'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'subscription ended'],
        'amount_regex' => '/(?:US)?\$\s?([0-9][0-9.,]*)/',
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://apps.apple.com/account/subscriptions',
    ],
    'amazon' => [
        'name' => 'Amazon Prime',
        'sender_domains' => ['amazon.com', 'primevideo.com'],
        'payment_keywords' => ['prime', 'receipt', 'payment', 'membership'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'membership ended'],
        'amount_regex' => '/(?:US)?\$\s?([0-9][0-9.,]*)/',
        'default_currency' => 'USD',
        'default_cycle' => 'yearly',
        'cancel_url' => 'https://www.amazon.com/gp/primecentral',
    ],
    'disney' => [
        'name' => 'Disney+',
        'sender_domains' => ['disneyplus.com', 'mail.disneyplus.com'],
        'payment_keywords' => ['receipt', 'payment', 'subscription'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'subscription ended'],
        'amount_regex' => '/(?:US)?\$\s?([0-9][0-9.,]*)/',
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://www.disneyplus.com/account/subscription',
    ],
];
```

- [ ] **Step 4: Create ProviderMatcher**

Create `app/Support/Gmail/ProviderMatcher.php`:

```php
<?php

namespace App\Support\Gmail;

class ProviderMatcher
{
    /**
     * Return the provider key whose sender domain appears in the From header,
     * or null if none match.
     */
    public static function match(string $from, string $subject): ?string
    {
        $haystack = strtolower($from);

        foreach (config('providers') as $key => $provider) {
            foreach ($provider['sender_domains'] as $domain) {
                if (str_contains($haystack, '@'.strtolower($domain))
                    || str_contains($haystack, '.'.strtolower($domain))
                    || str_contains($haystack, strtolower($domain).'>')
                    || str_ends_with($haystack, strtolower($domain))) {
                    return $key;
                }
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=ProviderMatcherTest`
Expected: PASS — 3 tests green.

- [ ] **Step 6: Commit**

```bash
git add config/providers.php app/Support/Gmail/ProviderMatcher.php tests/Unit/Gmail/ProviderMatcherTest.php
git commit -m "Update: add provider catalog and Gmail ProviderMatcher

Add config/providers.php (9 providers keyed to the SP2 color catalog)
and a pure ProviderMatcher that resolves a From header to a provider
key by sender domain.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 3: ReceiptParser

**Files:**
- Create: `app/Support/Gmail/ReceiptParser.php`
- Test: `tests/Unit/Gmail/ReceiptParserTest.php`

**Interfaces:**
- Consumes: a provider config array (one catalog entry).
- Produces:
  - `ReceiptParser::parse(array $provider, string $subject, string $body, string $emailDate): array` returning
    `['intent' => 'payment'|'cancellation', 'amount' => ?float, 'currency' => ?string, 'billing_cycle' => 'monthly'|'yearly', 'next_renewal_date' => ?string, 'confidence' => array<string,bool>]`.
  - `ReceiptParser::normalizeAmount(string $raw): ?float` (helper, tested directly).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Gmail/ReceiptParserTest.php`:

```php
<?php

namespace Tests\Unit\Gmail;

use App\Support\Gmail\ReceiptParser;
use Tests\TestCase;

class ReceiptParserTest extends TestCase
{
    private array $netflix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->netflix = config('providers.netflix');
    }

    public function test_normalize_amount_handles_common_formats(): void
    {
        $this->assertSame(12.99, ReceiptParser::normalizeAmount('12.99'));
        $this->assertSame(1234.56, ReceiptParser::normalizeAmount('1,234.56'));
        $this->assertSame(260000.0, ReceiptParser::normalizeAmount('260.000'));
        $this->assertSame(9.99, ReceiptParser::normalizeAmount('9,99'));
    }

    public function test_parses_a_payment_receipt(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Netflix receipt',
            "Thanks for your payment.\nAmount charged: $12.99\nYour plan renews monthly.",
            '2026-07-01',
        );

        $this->assertSame('payment', $result['intent']);
        $this->assertSame(12.99, $result['amount']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame('monthly', $result['billing_cycle']);
        // monthly renewal inferred one month past the email date
        $this->assertSame('2026-08-01', $result['next_renewal_date']);
        $this->assertTrue($result['confidence']['amount']);
    }

    public function test_detects_a_cancellation_email(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Netflix membership has been cancelled',
            "Your membership ended. We're sorry to see you go.",
            '2026-07-05',
        );

        $this->assertSame('cancellation', $result['intent']);
    }

    public function test_infers_yearly_cycle_from_keywords(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your annual plan receipt',
            "You were charged $129.99 for your yearly subscription.",
            '2026-07-01',
        );

        $this->assertSame('yearly', $result['billing_cycle']);
        $this->assertSame('2027-07-01', $result['next_renewal_date']);
    }

    public function test_amount_is_null_and_flagged_low_confidence_when_absent(): void
    {
        $result = ReceiptParser::parse(
            $this->netflix,
            'Your Netflix update',
            'Here is some news about your account with no price at all.',
            '2026-07-01',
        );

        $this->assertNull($result['amount']);
        $this->assertFalse($result['confidence']['amount']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ReceiptParserTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement ReceiptParser**

Create `app/Support/Gmail/ReceiptParser.php`:

```php
<?php

namespace App\Support\Gmail;

use Illuminate\Support\Carbon;

class ReceiptParser
{
    /**
     * Best-effort extraction of a subscription candidate's fields from one email.
     *
     * @param  array  $provider  one catalog entry from config('providers')
     * @return array{intent:string,amount:?float,currency:?string,billing_cycle:string,next_renewal_date:?string,confidence:array<string,bool>}
     */
    public static function parse(array $provider, string $subject, string $body, string $emailDate): array
    {
        $text = strtolower($subject."\n".$body);

        $intent = self::matchesAny($text, $provider['cancellation_keywords'] ?? [])
            ? 'cancellation'
            : 'payment';

        $amount = null;
        if (preg_match($provider['amount_regex'], $subject."\n".$body, $m)) {
            $amount = self::normalizeAmount($m[1]);
        }

        $currency = $amount !== null ? ($provider['default_currency'] ?? null) : null;

        $isYearly = self::matchesAny($text, ['year', 'yearly', 'annual', 'annually', 'năm']);
        $cycle = $isYearly ? 'yearly' : ($provider['default_cycle'] ?? 'monthly');

        $renewal = null;
        if ($intent === 'payment') {
            $base = Carbon::parse($emailDate);
            $renewal = ($cycle === 'yearly' ? $base->copy()->addYear() : $base->copy()->addMonth())
                ->toDateString();
        }

        return [
            'intent' => $intent,
            'amount' => $amount,
            'currency' => $currency,
            'billing_cycle' => $cycle,
            'next_renewal_date' => $renewal,
            'confidence' => [
                'amount' => $amount !== null,
                'renewal' => $renewal !== null,
            ],
        ];
    }

    /**
     * Parse a human-formatted money string into a float, or null if unparseable.
     * Handles "12.99", "1,234.56", "260.000" (thousands), "9,99" (comma decimal).
     */
    public static function normalizeAmount(string $raw): ?float
    {
        $s = preg_replace('/[^0-9.,]/', '', $raw);
        if ($s === '' || $s === null) {
            return null;
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // The separator that appears last is the decimal separator.
            $decimal = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $s = str_replace($thousands, '', $s);
            $s = str_replace($decimal, '.', $s);
        } elseif ($hasComma) {
            // Comma decimal only if exactly two digits follow the last comma.
            $s = preg_match('/,\d{2}$/', $s) ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        } elseif ($hasDot) {
            // Dot is thousands if 3 digits follow and none are a 2-digit cents tail.
            if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
                $s = str_replace('.', '', $s);
            }
        }

        return is_numeric($s) ? (float) $s : null;
    }

    private static function matchesAny(string $haystackLower, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystackLower, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ReceiptParserTest`
Expected: PASS — 5 tests green.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Gmail/ReceiptParser.php tests/Unit/Gmail/ReceiptParserTest.php
git commit -m "Update: add best-effort Gmail ReceiptParser

Parse one email into a subscription candidate: payment vs cancellation
intent, amount (multi-format normalization), currency, monthly/yearly
cycle, and inferred next renewal date, with per-field confidence flags.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 4: GmailClient (HTTP wrapper + token refresh)

**Files:**
- Create: `app/Support/Gmail/GmailClient.php`
- Test: `tests/Feature/Gmail/GmailClientTest.php`

**Interfaces:**
- Consumes: a `User` with Gmail tokens; `config('services.google')` for client id/secret.
- Produces:
  - `new GmailClient(User $user)`.
  - `listMessageIds(string $query, int $max = 100): array` — array of message id strings.
  - `getMessage(string $id): array` — `['from' => string, 'subject' => string, 'date' => string(Y-m-d), 'body' => string]`.
  - Refreshes the access token via Google's token endpoint when expired, persisting the new token to the user. Throws `\RuntimeException` on API failure.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Gmail/GmailClientTest.php`:

```php
<?php

namespace Tests\Feature\Gmail;

use App\Models\User;
use App\Support\Gmail\GmailClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailClientTest extends TestCase
{
    use RefreshDatabase;

    private function connectedUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'gmail_access_token' => 'valid-access',
            'gmail_refresh_token' => 'refresh-token',
            'gmail_token_expires_at' => now()->addHour(),
        ])->save();

        return $user->fresh();
    }

    public function test_list_message_ids_returns_ids(): void
    {
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response([
                'messages' => [['id' => 'a1'], ['id' => 'b2']],
            ]),
        ]);

        $ids = (new GmailClient($this->connectedUser()))->listMessageIds('from:(netflix.com)', 50);

        $this->assertSame(['a1', 'b2'], $ids);
    }

    public function test_get_message_decodes_headers_and_body(): void
    {
        $body = rtrim(strtr(base64_encode("Amount charged: \$12.99"), '+/', '-_'), '=');
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/a1*' => Http::response([
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'info@netflix.com'],
                        ['name' => 'Subject', 'value' => 'Your receipt'],
                        ['name' => 'Date', 'value' => 'Wed, 01 Jul 2026 10:00:00 +0000'],
                    ],
                    'mimeType' => 'text/plain',
                    'body' => ['data' => $body],
                ],
            ]),
        ]);

        $msg = (new GmailClient($this->connectedUser()))->getMessage('a1');

        $this->assertSame('info@netflix.com', $msg['from']);
        $this->assertSame('Your receipt', $msg['subject']);
        $this->assertSame('2026-07-01', $msg['date']);
        $this->assertStringContainsString('12.99', $msg['body']);
    }

    public function test_refreshes_expired_access_token_before_calling(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'gmail_access_token' => 'stale',
            'gmail_refresh_token' => 'refresh-token',
            'gmail_token_expires_at' => now()->subMinute(), // expired
        ])->save();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access', 'expires_in' => 3600,
            ]),
            'gmail.googleapis.com/*' => Http::response(['messages' => []]),
        ]);

        (new GmailClient($user->fresh()))->listMessageIds('from:(netflix.com)');

        $this->assertSame('fresh-access', $user->fresh()->gmail_access_token);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'oauth2.googleapis.com/token'));
    }

    public function test_throws_on_api_error(): void
    {
        Http::fake(['gmail.googleapis.com/*' => Http::response(['error' => 'boom'], 401)]);

        $this->expectException(\RuntimeException::class);
        (new GmailClient($this->connectedUser()))->listMessageIds('from:(netflix.com)');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GmailClientTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement GmailClient**

Create `app/Support/Gmail/GmailClient.php`:

```php
<?php

namespace App\Support\Gmail;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class GmailClient
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(private User $user)
    {
    }

    /** @return string[] */
    public function listMessageIds(string $query, int $max = 100): array
    {
        $response = $this->authorized()->get(self::BASE.'/messages', [
            'q' => $query,
            'maxResults' => $max,
        ]);

        $this->assertOk($response);

        return collect($response->json('messages', []))
            ->pluck('id')
            ->all();
    }

    /** @return array{from:string,subject:string,date:string,body:string} */
    public function getMessage(string $id): array
    {
        $response = $this->authorized()->get(self::BASE.'/messages/'.$id, ['format' => 'full']);
        $this->assertOk($response);

        $payload = $response->json('payload', []);
        $headers = collect($payload['headers'] ?? [])
            ->mapWithKeys(fn ($h) => [strtolower($h['name']) => $h['value']]);

        $rawDate = $headers['date'] ?? null;

        return [
            'from' => $headers['from'] ?? '',
            'subject' => $headers['subject'] ?? '',
            'date' => $rawDate ? Carbon::parse($rawDate)->toDateString() : Carbon::now()->toDateString(),
            'body' => $this->extractBody($payload),
        ];
    }

    private function extractBody(array $payload): string
    {
        if (! empty($payload['body']['data'])) {
            return $this->decode($payload['body']['data']);
        }

        // Prefer text/plain, then any part with body data (recursively).
        foreach (($payload['parts'] ?? []) as $part) {
            if (($part['mimeType'] ?? '') === 'text/plain' && ! empty($part['body']['data'])) {
                return $this->decode($part['body']['data']);
            }
        }
        foreach (($payload['parts'] ?? []) as $part) {
            $nested = $this->extractBody($part);
            if ($nested !== '') {
                return $nested;
            }
        }

        return '';
    }

    private function decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }

    private function authorized(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->freshAccessToken());
    }

    private function freshAccessToken(): string
    {
        $expiry = $this->user->gmail_token_expires_at;
        if ($expiry && $expiry->isFuture()) {
            return $this->user->gmail_access_token;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $this->user->gmail_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Gmail token refresh failed: '.$response->status());
        }

        $this->user->forceFill([
            'gmail_access_token' => $response->json('access_token'),
            'gmail_token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ])->save();

        return $this->user->gmail_access_token;
    }

    private function assertOk(\Illuminate\Http\Client\Response $response): void
    {
        if ($response->failed()) {
            throw new \RuntimeException('Gmail API error: '.$response->status());
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=GmailClientTest`
Expected: PASS — 4 tests green.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Gmail/GmailClient.php tests/Feature/Gmail/GmailClientTest.php
git commit -m "Update: add GmailClient wrapper over the Gmail REST API

Wrap Gmail messages.list/get over the Laravel HTTP client with
base64url body extraction and automatic access-token refresh via the
Google token endpoint (persisted to the user). Errors raise
RuntimeException. Fully covered with Http::fake.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 5: SubscriptionScanner (orchestrator + dedup)

**Files:**
- Create: `app/Support/Gmail/SubscriptionScanner.php`
- Test: `tests/Feature/Gmail/SubscriptionScannerTest.php`

**Interfaces:**
- Consumes: `GmailClient`, `ProviderMatcher`, `ReceiptParser`, the `User`'s existing subscriptions.
- Produces:
  - `new SubscriptionScanner(GmailClient $client)`.
  - `scan(User $user): array` — a list of candidate arrays:
    `['provider_key','name','intent','amount'=>?float,'currency'=>?string,'billing_cycle','next_renewal_date'=>?string,'cancel_url','confidence','source_email_id','action','duplicate_of'=>?int]`
    where `action` ∈ `create|skip|update_status`, deduped against existing subscriptions by `provider_key`+`billing_cycle`, latest email per group winning.
  - `buildQuery(): string` (public, tested) — the `from:(...) newer_than:1y` query from the catalog domains.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Gmail/SubscriptionScannerTest.php`:

```php
<?php

namespace Tests\Feature\Gmail;

use App\Models\Subscription;
use App\Models\User;
use App\Support\Gmail\GmailClient;
use App\Support\Gmail\SubscriptionScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionScannerTest extends TestCase
{
    use RefreshDatabase;

    /** Build a scanner whose GmailClient is stubbed to return the given messages. */
    private function scannerReturning(array $messagesById): SubscriptionScanner
    {
        $client = new class($messagesById) extends GmailClient {
            public function __construct(private array $messages)
            {
            }

            public function listMessageIds(string $query, int $max = 100): array
            {
                return array_keys($this->messages);
            }

            public function getMessage(string $id): array
            {
                return $this->messages[$id];
            }
        };

        return new SubscriptionScanner($client);
    }

    public function test_payment_email_becomes_a_create_candidate(): void
    {
        $user = User::factory()->create();
        $scanner = $this->scannerReturning([
            'm1' => [
                'from' => 'info@netflix.com',
                'subject' => 'Your Netflix receipt',
                'date' => '2026-07-01',
                'body' => 'You were charged $12.99 this month.',
            ],
        ]);

        $candidates = $scanner->scan($user);

        $this->assertCount(1, $candidates);
        $this->assertSame('netflix', $candidates[0]['provider_key']);
        $this->assertSame('create', $candidates[0]['action']);
        $this->assertSame(12.99, $candidates[0]['amount']);
    }

    public function test_payment_for_existing_subscription_is_skipped_as_duplicate(): void
    {
        $user = User::factory()->create();
        $existing = $user->subscriptions()->create([
            'name' => 'Netflix', 'provider_key' => 'netflix', 'amount' => 12.99,
            'currency' => 'USD', 'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-07-20', 'status' => 'active',
        ]);

        $scanner = $this->scannerReturning([
            'm1' => [
                'from' => 'info@netflix.com', 'subject' => 'Your Netflix receipt',
                'date' => '2026-07-01', 'body' => 'Charged $12.99 monthly.',
            ],
        ]);

        $candidates = $scanner->scan($user);

        $this->assertSame('skip', $candidates[0]['action']);
        $this->assertSame($existing->id, $candidates[0]['duplicate_of']);
    }

    public function test_cancellation_matching_active_sub_becomes_update_status(): void
    {
        $user = User::factory()->create();
        $existing = $user->subscriptions()->create([
            'name' => 'Netflix', 'provider_key' => 'netflix', 'amount' => 12.99,
            'currency' => 'USD', 'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-07-20', 'status' => 'active',
        ]);

        $scanner = $this->scannerReturning([
            'm1' => [
                'from' => 'info@netflix.com',
                'subject' => 'Your Netflix membership has been cancelled',
                'date' => '2026-07-10', 'body' => 'Your membership ended.',
            ],
        ]);

        $candidates = $scanner->scan($user);

        $this->assertSame('update_status', $candidates[0]['action']);
        $this->assertSame($existing->id, $candidates[0]['duplicate_of']);
    }

    public function test_cancellation_without_a_matching_sub_is_skipped(): void
    {
        $user = User::factory()->create();
        $scanner = $this->scannerReturning([
            'm1' => [
                'from' => 'info@netflix.com',
                'subject' => 'Your Netflix membership has been cancelled',
                'date' => '2026-07-10', 'body' => 'Your membership ended.',
            ],
        ]);

        $candidates = $scanner->scan($user);

        $this->assertSame('skip', $candidates[0]['action']);
        $this->assertNull($candidates[0]['duplicate_of']);
    }

    public function test_latest_email_per_provider_and_cycle_wins(): void
    {
        $user = User::factory()->create();
        $scanner = $this->scannerReturning([
            'older' => [
                'from' => 'info@netflix.com', 'subject' => 'receipt',
                'date' => '2026-05-01', 'body' => 'Charged $12.99 monthly.',
            ],
            'newer' => [
                'from' => 'info@netflix.com', 'subject' => 'membership cancelled',
                'date' => '2026-07-01', 'body' => 'Your membership ended.',
            ],
        ]);

        $candidates = $scanner->scan($user);

        $this->assertCount(1, $candidates);
        $this->assertSame('cancellation', $candidates[0]['intent']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SubscriptionScannerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement SubscriptionScanner**

Create `app/Support/Gmail/SubscriptionScanner.php`:

```php
<?php

namespace App\Support\Gmail;

use App\Models\User;

class SubscriptionScanner
{
    private const MAX_MESSAGES = 100;

    public function __construct(private GmailClient $client)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function scan(User $user): array
    {
        $ids = $this->client->listMessageIds($this->buildQuery(), self::MAX_MESSAGES);

        // Parse each message into a raw candidate keyed by provider+cycle,
        // keeping only the latest email per group.
        $byGroup = [];
        foreach ($ids as $id) {
            $msg = $this->client->getMessage($id);
            $key = ProviderMatcher::match($msg['from'], $msg['subject']);
            if ($key === null) {
                continue;
            }

            $provider = config("providers.$key");
            $parsed = ReceiptParser::parse($provider, $msg['subject'], $msg['body'], $msg['date']);
            $group = $key.'|'.$parsed['billing_cycle'];

            if (! isset($byGroup[$group]) || $msg['date'] > $byGroup[$group]['date']) {
                $byGroup[$group] = [
                    'provider_key' => $key,
                    'name' => $provider['name'],
                    'cancel_url' => $provider['cancel_url'] ?? null,
                    'date' => $msg['date'],
                    'source_email_id' => $id,
                    'parsed' => $parsed,
                ];
            }
        }

        return $this->applyDedup($user, array_values($byGroup));
    }

    public function buildQuery(): string
    {
        $domains = collect(config('providers'))
            ->flatMap(fn ($p) => $p['sender_domains'])
            ->unique()
            ->implode(' OR ');

        return "from:($domains) newer_than:1y";
    }

    /** @return array<int,array<string,mixed>> */
    private function applyDedup(User $user, array $groups): array
    {
        $existing = $user->subscriptions()
            ->whereNotNull('provider_key')
            ->get()
            ->keyBy(fn ($s) => $s->provider_key.'|'.$s->billing_cycle);

        $candidates = [];
        foreach ($groups as $g) {
            $parsed = $g['parsed'];
            $groupKey = $g['provider_key'].'|'.$parsed['billing_cycle'];
            $match = $existing->get($groupKey);
            $matchActive = $match && $match->status !== 'cancelled';

            if ($parsed['intent'] === 'cancellation') {
                $action = $matchActive ? 'update_status' : 'skip';
            } else {
                $action = $matchActive ? 'skip' : 'create';
            }

            $candidates[] = [
                'provider_key' => $g['provider_key'],
                'name' => $g['name'],
                'intent' => $parsed['intent'],
                'amount' => $parsed['amount'],
                'currency' => $parsed['currency'],
                'billing_cycle' => $parsed['billing_cycle'],
                'next_renewal_date' => $parsed['next_renewal_date'],
                'cancel_url' => $g['cancel_url'],
                'confidence' => $parsed['confidence'],
                'source_email_id' => $g['source_email_id'],
                'action' => $action,
                'duplicate_of' => $match?->id,
            ];
        }

        return $candidates;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=SubscriptionScannerTest`
Expected: PASS — 5 tests green.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Gmail/SubscriptionScanner.php tests/Feature/Gmail/SubscriptionScannerTest.php
git commit -m "Update: add SubscriptionScanner orchestrating Gmail detection

Combine GmailClient + ProviderMatcher + ReceiptParser into candidate
rows: group by provider+cycle (latest email wins) and assign a dedup
action (create / skip / update_status) against the user's existing
subscriptions.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 6: GmailController connect / callback / disconnect + routes

**Files:**
- Create: `app/Http/Controllers/GmailController.php` (connect/callback/disconnect only in this task)
- Modify: `routes/web.php`
- Test: `tests/Feature/Gmail/GmailConnectTest.php`

**Interfaces:**
- Consumes: Laravel Socialite `google` driver; `User` token columns (Task 1).
- Produces routes (all `auth`): `GET /gmail/connect` (`gmail.connect`), `GET /gmail/callback` (`gmail.callback`), `DELETE /gmail/disconnect` (`gmail.disconnect`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Gmail/GmailConnectTest.php`:

```php
<?php

namespace Tests\Feature\Gmail;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GmailConnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_connect(): void
    {
        $this->get('/gmail/connect')->assertRedirect(route('login'));
    }

    public function test_callback_stores_encrypted_tokens(): void
    {
        $user = User::factory()->create();

        $socialiteUser = (new SocialiteUser)->setRaw([]);
        $socialiteUser->token = 'access-abc';
        $socialiteUser->refreshToken = 'refresh-xyz';
        $socialiteUser->expiresIn = 3600;

        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->actingAs($user)->get('/gmail/callback')->assertRedirect(route('dashboard'));

        $fresh = $user->fresh();
        $this->assertSame('access-abc', $fresh->gmail_access_token);
        $this->assertSame('refresh-xyz', $fresh->gmail_refresh_token);
        $this->assertTrue($fresh->hasGmailConnected());

        // Encrypted at rest.
        $raw = DB::table('users')->where('id', $user->id)->value('gmail_refresh_token');
        $this->assertNotSame('refresh-xyz', $raw);
    }

    public function test_disconnect_clears_tokens(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'gmail_access_token' => 'a', 'gmail_refresh_token' => 'r',
            'gmail_token_expires_at' => now()->addHour(),
        ])->save();

        $this->actingAs($user)->delete('/gmail/disconnect')->assertRedirect();

        $this->assertFalse($user->fresh()->hasGmailConnected());
        $this->assertNull($user->fresh()->gmail_access_token);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GmailConnectTest`
Expected: FAIL — routes/controller undefined.

- [ ] **Step 3: Create the controller (connect/callback/disconnect)**

Create `app/Http/Controllers/GmailController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class GmailController extends Controller
{
    private const GMAIL_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    public function connect()
    {
        return Socialite::driver('google')
            ->scopes([self::GMAIL_SCOPE])
            ->redirectUrl(route('gmail.callback'))
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function callback(Request $request)
    {
        $googleUser = Socialite::driver('google')
            ->redirectUrl(route('gmail.callback'))
            ->user();

        $attributes = [
            'gmail_access_token' => $googleUser->token,
            'gmail_token_expires_at' => now()->addSeconds((int) ($googleUser->expiresIn ?? 3600)),
        ];

        // Google only returns a refresh token on the first offline consent;
        // keep the existing one if this consent didn't include a new one.
        if (! empty($googleUser->refreshToken)) {
            $attributes['gmail_refresh_token'] = $googleUser->refreshToken;
        }

        $request->user()->forceFill($attributes)->save();

        return redirect()->route('dashboard');
    }

    public function disconnect(Request $request)
    {
        $request->user()->forceFill([
            'gmail_access_token' => null,
            'gmail_refresh_token' => null,
            'gmail_token_expires_at' => null,
        ])->save();

        return redirect()->back();
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\GmailController;
```

Inside the existing `Route::middleware('auth')->group(function () { ... });` block (the one that holds the profile + subscriptions routes), add:

```php
    Route::get('/gmail/connect', [GmailController::class, 'connect'])->name('gmail.connect');
    Route::get('/gmail/callback', [GmailController::class, 'callback'])->name('gmail.callback');
    Route::delete('/gmail/disconnect', [GmailController::class, 'disconnect'])->name('gmail.disconnect');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=GmailConnectTest`
Expected: PASS — 3 tests green.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/GmailController.php routes/web.php tests/Feature/Gmail/GmailConnectTest.php
git commit -m "Update: add Gmail connect/callback/disconnect OAuth flow

Add a separate Socialite consent (gmail.readonly, offline, forced
consent) that stores encrypted access/refresh tokens on the user, and
a disconnect action that clears them. Keeps the existing refresh token
when a re-consent omits one.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 7: Scan + import controller actions + request + routes

**Files:**
- Modify: `app/Http/Controllers/GmailController.php` (add `scan`, `import`)
- Create: `app/Http/Requests/GmailImportRequest.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Gmail/GmailScanImportTest.php`

**Interfaces:**
- Consumes: `SubscriptionScanner` (Task 5), `GmailClient` (Task 4), the user's subscriptions.
- Produces routes (all `auth`): `GET /gmail/scan` (`gmail.scan`) → Inertia `Gmail/ScanResults` with `candidates`; `POST /gmail/import` (`gmail.import`).
- Import payload: `{ items: [{ action, provider_key, name, amount, currency, billing_cycle, next_renewal_date, cancel_url, duplicate_of }] }` — only ticked items are posted.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Gmail/GmailScanImportTest.php`:

```php
<?php

namespace Tests\Feature\Gmail;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GmailScanImportTest extends TestCase
{
    use RefreshDatabase;

    private function connectedUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'gmail_access_token' => 'a', 'gmail_refresh_token' => 'r',
            'gmail_token_expires_at' => now()->addHour(),
        ])->save();

        return $user->fresh();
    }

    public function test_scan_redirects_to_connect_when_not_connected(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/gmail/scan')
            ->assertRedirect(route('gmail.connect'));
    }

    public function test_scan_renders_candidates(): void
    {
        $netflixBody = rtrim(strtr(base64_encode('Charged $12.99 monthly.'), '+/', '-_'), '=');
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1*' => Http::response([
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'info@netflix.com'],
                        ['name' => 'Subject', 'value' => 'Your Netflix receipt'],
                        ['name' => 'Date', 'value' => 'Wed, 01 Jul 2026 10:00:00 +0000'],
                    ],
                    'mimeType' => 'text/plain',
                    'body' => ['data' => $netflixBody],
                ],
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response([
                'messages' => [['id' => 'm1']],
            ]),
        ]);

        $this->actingAs($this->connectedUser())
            ->get('/gmail/scan')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Gmail/ScanResults')
                ->has('candidates', 1)
                ->where('candidates.0.provider_key', 'netflix')
                ->where('candidates.0.action', 'create')
            );
    }

    public function test_import_creates_new_subscriptions_scoped_to_user(): void
    {
        $user = $this->connectedUser();

        $this->actingAs($user)->post('/gmail/import', [
            'items' => [[
                'action' => 'create', 'provider_key' => 'netflix', 'name' => 'Netflix',
                'amount' => 12.99, 'currency' => 'USD', 'billing_cycle' => 'monthly',
                'next_renewal_date' => '2026-08-01', 'cancel_url' => 'https://netflix.com/cancelplan',
                'duplicate_of' => null,
            ]],
        ])->assertRedirect(route('dashboard'));

        $sub = $user->subscriptions()->first();
        $this->assertSame('netflix', $sub->provider_key);
        $this->assertSame('active', $sub->status);
        $this->assertGreaterThan(0, $sub->amount_vnd); // saving hook computed it
    }

    public function test_import_update_status_marks_existing_cancelled(): void
    {
        $user = $this->connectedUser();
        $sub = $user->subscriptions()->create([
            'name' => 'Netflix', 'provider_key' => 'netflix', 'amount' => 12.99,
            'currency' => 'USD', 'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-07-20', 'status' => 'active',
        ]);

        $this->actingAs($user)->post('/gmail/import', [
            'items' => [['action' => 'update_status', 'duplicate_of' => $sub->id]],
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('cancelled', $sub->fresh()->status);
    }

    public function test_import_cannot_touch_another_users_subscription(): void
    {
        $user = $this->connectedUser();
        $other = User::factory()->create();
        $victim = $other->subscriptions()->create([
            'name' => 'Netflix', 'provider_key' => 'netflix', 'amount' => 12.99,
            'currency' => 'USD', 'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-07-20', 'status' => 'active',
        ]);

        $this->actingAs($user)->post('/gmail/import', [
            'items' => [['action' => 'update_status', 'duplicate_of' => $victim->id]],
        ]);

        $this->assertSame('active', $victim->fresh()->status); // untouched
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GmailScanImportTest`
Expected: FAIL — scan/import routes undefined.

- [ ] **Step 3: Create the import request**

Create `app/Http/Requests/GmailImportRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Support\CurrencyConverter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GmailImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*.action' => ['required', Rule::in(['create', 'update_status'])],
            'items.*.duplicate_of' => ['nullable', 'integer'],

            // Required only for create rows.
            'items.*.provider_key' => ['nullable', 'string', 'max:255'],
            'items.*.name' => ['required_if:items.*.action,create', 'string', 'max:255'],
            'items.*.amount' => ['required_if:items.*.action,create', 'nullable', 'numeric', 'gt:0'],
            'items.*.currency' => ['required_if:items.*.action,create', Rule::in(CurrencyConverter::supportedCurrencies())],
            'items.*.billing_cycle' => ['required_if:items.*.action,create', Rule::in(['monthly', 'yearly'])],
            'items.*.next_renewal_date' => ['required_if:items.*.action,create', 'nullable', 'date'],
            'items.*.cancel_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
```

- [ ] **Step 4: Add scan + import to GmailController**

In `app/Http/Controllers/GmailController.php`, add the imports at the top (below the existing `use` lines):

```php
use App\Http\Requests\GmailImportRequest;
use App\Support\Gmail\GmailClient;
use App\Support\Gmail\SubscriptionScanner;
use Inertia\Inertia;
```

Add these two methods to the class:

```php
    public function scan(Request $request)
    {
        $user = $request->user();

        if (! $user->hasGmailConnected()) {
            return redirect()->route('gmail.connect');
        }

        $scanner = new SubscriptionScanner(new GmailClient($user));

        return Inertia::render('Gmail/ScanResults', [
            'candidates' => $scanner->scan($user),
        ]);
    }

    public function import(GmailImportRequest $request)
    {
        $user = $request->user();

        foreach ($request->validated()['items'] as $item) {
            if ($item['action'] === 'create') {
                $user->subscriptions()->create([
                    'name' => $item['name'],
                    'provider_key' => $item['provider_key'] ?? null,
                    'amount' => $item['amount'],
                    'currency' => $item['currency'],
                    'billing_cycle' => $item['billing_cycle'],
                    'next_renewal_date' => $item['next_renewal_date'],
                    'status' => 'active',
                    'cancel_url' => $item['cancel_url'] ?? null,
                ]);
            } elseif ($item['action'] === 'update_status' && ! empty($item['duplicate_of'])) {
                // Scope to the user's own subscriptions — a foreign id simply finds nothing.
                $user->subscriptions()
                    ->whereKey($item['duplicate_of'])
                    ->update(['status' => 'cancelled']);
            }
        }

        return redirect()->route('dashboard');
    }
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, inside the same `auth` group, add:

```php
    Route::get('/gmail/scan', [GmailController::class, 'scan'])->name('gmail.scan');
    Route::post('/gmail/import', [GmailController::class, 'import'])->name('gmail.import');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=GmailScanImportTest`
Expected: PASS — 5 tests green.

Then the full backend suite:

Run: `php artisan test`
Expected: PASS — full suite green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/GmailController.php app/Http/Requests/GmailImportRequest.php routes/web.php tests/Feature/Gmail/GmailScanImportTest.php
git commit -m "Update: add Gmail scan and import actions

Add GET /gmail/scan (renders detected candidates via the scanner,
redirecting to connect when Gmail isn't linked) and POST /gmail/import
(creates ticked subscriptions or marks an existing one cancelled,
strictly scoped to the user's own subscriptions). Validate the import
payload, reusing the subscription currency/cycle rules.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 8: Scan-results UI + Gmail buttons

**Files:**
- Create: `resources/js/Pages/Gmail/ScanResults.vue`
- Modify: `resources/js/Pages/Dashboard.vue`
- Test: build-verified (no component test harness in scope)

**Interfaces:**
- Consumes: the `candidates` prop from `GmailController@scan`; routes `gmail.connect`, `gmail.scan`, `gmail.disconnect`, `gmail.import`; `$page.props.auth.user.gmail_connected` is NOT available (tokens are hidden) — derive connection state from a dedicated prop instead (below).
- Produces: the review page and the dashboard entry buttons.

Because Gmail tokens are hidden from `auth.user`, the dashboard needs an explicit boolean. Add it in `DashboardController@index` (from Task 3 of SP2) — this is the one backend touch in this task.

- [ ] **Step 1: Expose gmail_connected to the dashboard**

In `app/Http/Controllers/DashboardController.php`, add `gmail_connected` to the render props:

```php
        return Inertia::render('Dashboard', [
            'subscriptions' => $subscriptions,
            'gmail_connected' => $request->user()->hasGmailConnected(),
        ]);
```

- [ ] **Step 2: Add Gmail buttons to Dashboard.vue**

In `resources/js/Pages/Dashboard.vue`, add `gmail_connected` to the props and render the buttons in the header. Update the `<script setup>` props:

```javascript
defineProps({
    subscriptions: { type: Array, default: () => [] },
    gmail_connected: { type: Boolean, default: false },
});
```

Replace the header `<template #header>` block with:

```vue
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Vũ trụ của bạn
                </h2>
                <div class="flex items-center gap-3 text-sm">
                    <Link
                        v-if="gmail_connected"
                        :href="route('gmail.scan')"
                        class="rounded-md bg-emerald-600 px-3 py-2 text-white hover:bg-emerald-500"
                    >
                        Quét Gmail
                    </Link>
                    <a
                        v-else
                        :href="route('gmail.connect')"
                        class="rounded-md bg-emerald-600 px-3 py-2 text-white hover:bg-emerald-500"
                    >
                        Kết nối Gmail
                    </a>
                    <Link
                        v-if="gmail_connected"
                        :href="route('gmail.disconnect')"
                        method="delete"
                        as="button"
                        class="text-gray-500 hover:underline"
                    >
                        Ngắt kết nối
                    </Link>
                    <Link
                        :href="route('subscriptions.index')"
                        class="text-indigo-600 hover:underline"
                    >
                        Quản lý danh sách
                    </Link>
                </div>
            </div>
        </template>
```

(The `gmail.connect` link is a plain `<a>` because it leaves the app to Google's OAuth page, not an Inertia visit.)

- [ ] **Step 3: Create ScanResults.vue**

Create `resources/js/Pages/Gmail/ScanResults.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, computed } from 'vue';

const props = defineProps({
    candidates: { type: Array, default: () => [] },
});

const actionLabel = {
    create: 'Sẽ thêm mới',
    update_status: 'Đề xuất đánh dấu đã hủy',
    skip: 'Bỏ qua',
};

// Local editable rows; skip rows start unticked.
const rows = reactive(
    props.candidates.map((c) => ({
        ...c,
        selected: c.action === 'create' || c.action === 'update_status',
    })),
);

const selectedCount = computed(() => rows.filter((r) => r.selected).length);

function submit() {
    const items = rows
        .filter((r) => r.selected && r.action !== 'skip')
        .map((r) => ({
            action: r.action,
            provider_key: r.provider_key,
            name: r.name,
            amount: r.amount,
            currency: r.currency,
            billing_cycle: r.billing_cycle,
            next_renewal_date: r.next_renewal_date,
            cancel_url: r.cancel_url,
            duplicate_of: r.duplicate_of,
        }));

    router.post(route('gmail.import'), { items });
}
</script>

<template>
    <Head title="Kết quả quét Gmail" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Kết quả quét Gmail
                </h2>
                <Link :href="route('dashboard')" class="text-sm text-indigo-600 hover:underline">
                    Về vũ trụ
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <p v-if="rows.length === 0" class="rounded-lg bg-white p-8 text-center text-gray-600 shadow-sm">
                    Không tìm thấy dịch vụ nào trong hộp thư gần đây.
                </p>

                <div v-else class="space-y-3">
                    <div
                        v-for="(row, i) in rows"
                        :key="i"
                        class="rounded-lg bg-white p-4 shadow-sm"
                        :class="{ 'opacity-50': row.action === 'skip' }"
                    >
                        <div class="flex items-start gap-3">
                            <input
                                type="checkbox"
                                v-model="row.selected"
                                :disabled="row.action === 'skip'"
                                class="mt-1"
                            />
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-semibold text-gray-900">{{ row.name }}</span>
                                    <span class="text-xs text-gray-500">
                                        {{ actionLabel[row.action] }}
                                        <span v-if="row.duplicate_of">· 🔁 đã có</span>
                                    </span>
                                </div>

                                <div v-if="row.action !== 'update_status'" class="mt-2 grid grid-cols-2 gap-2 text-sm">
                                    <label class="flex flex-col">
                                        <span class="text-gray-500">Số tiền</span>
                                        <input v-model="row.amount" type="number" step="0.01" class="rounded border-gray-300" />
                                    </label>
                                    <label class="flex flex-col">
                                        <span class="text-gray-500">Tiền tệ</span>
                                        <input v-model="row.currency" type="text" class="rounded border-gray-300" />
                                    </label>
                                    <label class="flex flex-col">
                                        <span class="text-gray-500">Chu kỳ</span>
                                        <select v-model="row.billing_cycle" class="rounded border-gray-300">
                                            <option value="monthly">Hàng tháng</option>
                                            <option value="yearly">Hàng năm</option>
                                        </select>
                                    </label>
                                    <label class="flex flex-col">
                                        <span class="text-gray-500">Gia hạn</span>
                                        <input v-model="row.next_renewal_date" type="date" class="rounded border-gray-300" />
                                    </label>
                                </div>
                                <p v-else class="mt-1 text-sm text-gray-600">
                                    Gói này đang có trong danh sách — sẽ được đánh dấu đã hủy.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button
                            type="button"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-500 disabled:opacity-50"
                            :disabled="selectedCount === 0"
                            @click="submit"
                        >
                            Nhập {{ selectedCount }} mục đã chọn
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 4: Verify the build compiles**

Run: `npm run build`
Expected: PASS — build completes with the new `Gmail/ScanResults` chunk, no errors.

- [ ] **Step 5: Verify suites still pass**

Run: `npm run test:js && php artisan test`
Expected: PASS — Vitest green, full PHPUnit suite green.

- [ ] **Step 6: Manual verification (record in report)**

With the app running, a logged-in user, and real Google OAuth credentials (Testing mode + your email as a test user):
- Dashboard shows "Kết nối Gmail" when not connected; after connecting it shows "Quét Gmail" + "Ngắt kết nối".
- "Quét Gmail" lists detected candidates; create rows are ticked, duplicates/cancellations labelled, skips dimmed & unticked.
- Editing a row and clicking "Nhập N mục đã chọn" creates/updates subscriptions and returns to the orbit, where new planets appear.
- Note honestly which items are build-verified vs require live Google credentials to confirm.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Gmail/ScanResults.vue resources/js/Pages/Dashboard.vue app/Http/Controllers/DashboardController.php
git commit -m "Update: add Gmail scan-results UI and dashboard buttons

Add the ScanResults review page (editable, tickable candidate cards
with action/duplicate labels and bulk import) and Connect/Scan/
Disconnect buttons on the dashboard. Expose a hidden-token-safe
gmail_connected boolean from the dashboard controller.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Definition of Done

- User can connect Gmail (separate offline consent), tokens stored encrypted + hidden; disconnect clears them.
- "Quét Gmail" detects known providers rule-based, classifies payment/cancellation, dedups against existing subscriptions with correct actions.
- Scan-results page lets the user edit/tick candidates and bulk-import; creates set `provider_key` + `amount_vnd`, `update_status` marks cancelled — all scoped to the user (no cross-user writes).
- Imported subscriptions appear on the orbit with the right brand color; cancelled ones are absent (SP2 behavior).
- `user_id` removed from `Subscription::$fillable`; `provider_key` added.
- All new PHPUnit tests pass; Vitest + full PHPUnit suites stay green; `npm run build` succeeds.
- No background/scheduled scan or webhook (documented as SP4/SP5 in the spec).
```
