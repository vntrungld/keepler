# Orbit SP4 — Mobile Redesign, Calendar & Renewal Reminders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend Orbit's data model (list/category/payment method/trial/history) and rebuild the subscription list, detail, create/edit, dashboard, and add a new Calendar page and real email renewal reminders, matching the reference app screenshots while keeping the existing dark/violet visual theme.

**Architecture:** Laravel + Inertia/Vue monolith (unchanged). New tables (`payment_methods`, `subscription_events`) and new columns on `subscriptions`/`users`. Subscription lifecycle events (subscribed/price_changed/cancelled) are logged server-side in the controller, not via model observers, to keep the log intentional and easy to trace. Renewal reminders are a daily scheduled Artisan command using Laravel's built-in `Notification` (mail channel only — no push infra). The Calendar page does all date-projection math client-side in a new pure JS module (`orbit/calendar.js`), mirroring the existing `orbit/layout.js` pattern, so it needs no new backend endpoint per month navigation.

**Tech Stack:** Laravel 13, Inertia.js v2 + Vue 3, Tailwind CSS v3, PHPUnit (class style, not Pest), Vitest, `simple-icons` (new frontend dependency for brand logos), Mailpit (already running in the Spin dev stack) for verifying reminder emails locally.

**Spec:** `docs/superpowers/specs/2026-09-04-sp4-mobile-redesign-calendar-reminders-design.md`

## Global Constraints

- PHPUnit **class style**, not Pest — mirror `tests/Feature/SubscriptionCrudTest.php` and `tests/Feature/DashboardTest.php` conventions exactly.
- This codebase does **not** unit-test Vue components (no `@vue/test-utils` installed) — only pure JS modules under `resources/js/orbit/*.js` get Vitest tests. Component-only tasks in this plan end with a manual browser check, not an invented component test.
- `list` values: `personal | business | family`. `subscription_events.kind` values: `subscribed | price_changed | cancelled`. `payment_methods` stores a display `label` only — never a real card/account number.
- Keep the existing dark/violet visual theme (`midnight-*`, `violet-*` Tailwind tokens already added) — new UI must reuse these tokens, not introduce a new palette.
- All Calendar math runs client-side from props already loaded on the page — no new endpoint fires on month navigation.
- Don't rename existing Breeze route names (`profile.edit`, `profile.update`, `profile.destroy`, etc.) — only add new routes/methods alongside them.
- After any PHP file change, run `vendor/bin/pint --dirty --format agent` before committing.
- Run the narrowest relevant test file after each task; run the full suite (`php artisan test` and `npm run test:js`) before the final commit of the plan.

---

### Task 1: `payment_methods` table + model

**Files:**
- Create: `database/migrations/2026_09_04_000001_create_payment_methods_table.php`
- Create: `app/Models/PaymentMethod.php`
- Create: `database/factories/PaymentMethodFactory.php`
- Modify: `app/Models/User.php` (add `paymentMethods()` relation)
- Test: `tests/Feature/PaymentMethodModelTest.php`

**Interfaces:**
- Consumes: `App\Models\User`.
- Produces: `PaymentMethod::class` with `user_id, label`; `User::paymentMethods(): HasMany`. Later tasks (SubscriptionRequest, PaymentMethodController, Subscription model) depend on this table/model existing.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_method_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $method = PaymentMethod::factory()->for($user)->create(['label' => 'Visa •••• 1234']);

        $this->assertSame($user->id, $method->user_id);
        $this->assertTrue($user->paymentMethods->contains($method));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PaymentMethodModelTest`
Expected: FAIL — class `App\Models\PaymentMethod` not found.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = ['label'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentMethodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Visa •••• '.fake()->numerify('####'),
        ];
    }
}
```

- [ ] **Step 6: Add the relation to `User`**

Add to `app/Models/User.php` (near the existing `subscriptions()`/`gmailScans()` methods):

```php
    /**
     * @return HasMany<PaymentMethod, $this>
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=PaymentMethodModelTest`
Expected: PASS

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_09_04_000001_create_payment_methods_table.php \
        app/Models/PaymentMethod.php app/Models/User.php \
        database/factories/PaymentMethodFactory.php \
        tests/Feature/PaymentMethodModelTest.php
git commit -m "Update: add payment_methods table and model

Introduce a simple payment_methods table (label only, no real card
data) so subscriptions can reference a display-only payment method,
per SP4 design.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 2: New columns on `subscriptions` + `Subscription` model updates

**Files:**
- Create: `database/migrations/2026_09_04_000002_add_fields_to_subscriptions_table.php`
- Modify: `app/Models/Subscription.php`
- Test: `tests/Feature/SubscriptionModelTest.php` (extend existing file)

**Interfaces:**
- Consumes: `App\Models\PaymentMethod` (Task 1).
- Produces: `Subscription` fillable includes `list, category, payment_method_id, is_trial, started_at`; casts `started_at => date`, `is_trial => boolean`, `last_reminder_sent_for => date`; relation `paymentMethod(): BelongsTo`; accessors `subscribed_days` (int) and `total_spent` (float), both appended to array/JSON output. Later tasks (SubscriptionRequest, SubscriptionController, reminders command) rely on these exact names.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/SubscriptionModelTest.php` (read the file first to match its existing style/imports before appending):

```php
    public function test_subscribed_days_and_total_spent_are_computed_from_started_at(): void
    {
        $user = \App\Models\User::factory()->create();
        $subscription = Subscription::factory()->for($user)->create([
            'amount' => 100000,
            'currency' => 'VND',
            'billing_cycle' => 'monthly',
            'started_at' => now()->subDays(65)->toDateString(),
        ]);

        $this->assertSame(65, $subscription->subscribed_days);
        // 65 days / 30-day cycle = 2 completed cycles.
        $this->assertSame(200000.0, $subscription->total_spent);
    }

    public function test_total_spent_is_zero_before_the_first_cycle_completes(): void
    {
        $user = \App\Models\User::factory()->create();
        $subscription = Subscription::factory()->for($user)->create([
            'amount' => 50000,
            'currency' => 'VND',
            'started_at' => now()->toDateString(),
        ]);

        $this->assertSame(0.0, $subscription->total_spent);
    }

    public function test_subscription_belongs_to_a_payment_method(): void
    {
        $user = \App\Models\User::factory()->create();
        $method = \App\Models\PaymentMethod::factory()->for($user)->create();
        $subscription = Subscription::factory()->for($user)->create(['payment_method_id' => $method->id]);

        $this->assertTrue($subscription->paymentMethod->is($method));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SubscriptionModelTest`
Expected: FAIL — `list/category/payment_method_id/is_trial/started_at` columns don't exist yet, `subscribed_days`/`total_spent`/`paymentMethod` not defined.

- [ ] **Step 3: Write the migration**

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
            $table->enum('list', ['personal', 'business', 'family'])->default('personal')->after('user_id');
            $table->string('category', 50)->nullable()->after('list');
            $table->foreignId('payment_method_id')->nullable()->after('category')
                ->constrained()->nullOnDelete();
            $table->boolean('is_trial')->default(false)->after('status');
            $table->date('started_at')->nullable()->after('is_trial');
            $table->date('last_reminder_sent_for')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn(['list', 'category', 'is_trial', 'started_at', 'last_reminder_sent_for']);
        });
    }
};
```

- [ ] **Step 4: Update the `Subscription` model**

Replace the full contents of `app/Models/Subscription.php`:

```php
<?php

namespace App\Models;

use App\Support\CurrencyConverter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

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
        'list',
        'category',
        'payment_method_id',
        'is_trial',
        'started_at',
    ];

    protected $appends = ['subscribed_days', 'total_spent'];

    protected $casts = [
        'next_renewal_date' => 'date',
        'amount' => 'decimal:2',
        'amount_vnd' => 'integer',
        'is_trial' => 'boolean',
        'started_at' => 'date',
        'last_reminder_sent_for' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (Subscription $subscription) {
            $subscription->amount_vnd = CurrencyConverter::toVnd(
                (float) $subscription->amount,
                $subscription->currency,
            );
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    protected function startedAtOrCreatedAt(): \Illuminate\Support\Carbon
    {
        return $this->started_at ?? $this->created_at;
    }

    public function getSubscribedDaysAttribute(): int
    {
        return (int) now()->diffInDays($this->startedAtOrCreatedAt());
    }

    public function getTotalSpentAttribute(): float
    {
        $cycleDays = $this->billing_cycle === 'yearly' ? 365 : 30;
        $completedCycles = intdiv(max(0, $this->subscribed_days), $cycleDays);

        return $completedCycles * (float) $this->amount;
    }
}
```

Note: `events()` references `SubscriptionEvent`, which doesn't exist until Task 3 — that's fine, PHP resolves the class name lazily at call time, not at class-load time, so Task 2's tests (which never call `->events`) still pass.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SubscriptionModelTest`
Expected: PASS

- [ ] **Step 6: Run the full existing subscription suite to check for regressions**

Run: `php artisan test --filter=Subscription`
Expected: PASS (covers `SubscriptionModelTest` and `SubscriptionCrudTest`)

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_09_04_000002_add_fields_to_subscriptions_table.php \
        app/Models/Subscription.php tests/Feature/SubscriptionModelTest.php
git commit -m "Update: add list/category/payment method/trial fields to subscriptions

Add started_at-based subscribed_days and total_spent accessors so
the new subscription detail screen can show accurate figures even
for legacy rows with no logged history yet.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 3: `subscription_events` table + model (Billing/Price history log)

**Files:**
- Create: `database/migrations/2026_09_04_000003_create_subscription_events_table.php`
- Create: `app/Models/SubscriptionEvent.php`
- Test: `tests/Feature/SubscriptionEventModelTest.php`

**Interfaces:**
- Consumes: `Subscription::events()` relation name declared in Task 2.
- Produces: `SubscriptionEvent::class` with `subscription_id, kind, amount, currency, occurred_at`. `SubscriptionController` (Task 6) creates these rows; `Subscriptions/Show.vue` (Task 16) renders them.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionEventModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_belong_to_a_subscription_and_are_ordered_newest_first(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->for($user)->create();

        $older = $subscription->events()->create([
            'kind' => 'subscribed',
            'amount' => 9.99,
            'currency' => 'USD',
            'occurred_at' => now()->subDays(30)->toDateString(),
        ]);
        $newer = $subscription->events()->create([
            'kind' => 'price_changed',
            'amount' => 12.99,
            'currency' => 'USD',
            'occurred_at' => now()->toDateString(),
        ]);

        $ids = $subscription->events()->pluck('id')->all();
        $this->assertSame([$newer->id, $older->id], $ids);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SubscriptionEventModelTest`
Expected: FAIL — table `subscription_events` doesn't exist.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['subscribed', 'price_changed', 'cancelled']);
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionEvent extends Model
{
    protected $fillable = ['kind', 'amount', 'currency', 'occurred_at'];

    protected $casts = [
        'amount' => 'decimal:2',
        'occurred_at' => 'date',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SubscriptionEventModelTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_09_04_000003_create_subscription_events_table.php \
        app/Models/SubscriptionEvent.php tests/Feature/SubscriptionEventModelTest.php
git commit -m "Update: add subscription_events table for billing/price history

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 4: Renewal-reminder columns on `users`

**Files:**
- Create: `database/migrations/2026_09_04_000004_add_notification_preferences_to_users_table.php`
- Modify: `app/Models/User.php` (casts + `#[Fillable]` attribute)
- Test: `tests/Feature/UserNotificationPreferencesTest.php`

**Interfaces:**
- Consumes: none new.
- Produces: `User` columns `renewal_reminders_enabled` (bool, default true), `reminder_days_before` (int, default 3), both mass-assignable and cast. Task 8 (reminders command) and Task 9 (`ProfileController`) depend on these exact names and defaults.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_default_to_reminders_enabled_with_a_3_day_window(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->renewal_reminders_enabled);
        $this->assertSame(3, $user->reminder_days_before);
    }

    public function test_preferences_are_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->update(['renewal_reminders_enabled' => false, 'reminder_days_before' => 7]);

        $this->assertFalse($user->fresh()->renewal_reminders_enabled);
        $this->assertSame(7, $user->fresh()->reminder_days_before);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserNotificationPreferencesTest`
Expected: FAIL — columns don't exist.

- [ ] **Step 3: Write the migration**

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
            $table->boolean('renewal_reminders_enabled')->default(true);
            $table->unsignedTinyInteger('reminder_days_before')->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['renewal_reminders_enabled', 'reminder_days_before']);
        });
    }
};
```

- [ ] **Step 4: Update `User` model**

In `app/Models/User.php`, change the class attribute line:

```php
#[Fillable(['name', 'email', 'password', 'google_id', 'avatar', 'renewal_reminders_enabled', 'reminder_days_before'])]
```

Add to the `casts()` method's returned array:

```php
            'renewal_reminders_enabled' => 'boolean',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=UserNotificationPreferencesTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_09_04_000004_add_notification_preferences_to_users_table.php \
        app/Models/User.php tests/Feature/UserNotificationPreferencesTest.php
git commit -m "Update: add renewal reminder preferences to users

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 5: `SubscriptionRequest` validation for the new fields

**Files:**
- Modify: `app/Http/Requests/SubscriptionRequest.php`
- Test: `tests/Feature/SubscriptionCrudTest.php` (extend)

**Interfaces:**
- Consumes: `payment_methods` table (Task 1), `subscriptions.list/category/is_trial/started_at` columns (Task 2).
- Produces: validated array now includes `list, category, payment_method_id, is_trial, started_at` — `SubscriptionController` (Task 6) passes this straight to `create()`/`update()`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/SubscriptionCrudTest.php`:

```php
    public function test_user_can_store_a_subscription_with_the_new_optional_fields(): void
    {
        $user = User::factory()->create();
        $method = \App\Models\PaymentMethod::factory()->for($user)->create();

        $this->actingAs($user)->post('/subscriptions', [
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
            'list' => 'business',
            'category' => 'Streaming',
            'payment_method_id' => $method->id,
            'is_trial' => true,
            'started_at' => '2026-01-15',
        ])->assertRedirect('/subscriptions');

        $sub = Subscription::first();
        $this->assertSame('business', $sub->list);
        $this->assertSame('Streaming', $sub->category);
        $this->assertSame($method->id, $sub->payment_method_id);
        $this->assertTrue($sub->is_trial);
        $this->assertSame('2026-01-15', $sub->started_at->toDateString());
    }

    public function test_a_users_payment_method_cannot_be_assigned_to_another_users_subscription(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $method = \App\Models\PaymentMethod::factory()->for($owner)->create();

        $this->actingAs($other)->post('/subscriptions', [
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
            'payment_method_id' => $method->id,
        ])->assertSessionHasErrors('payment_method_id');
    }

    public function test_list_defaults_to_personal_when_omitted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/subscriptions', [
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
        ])->assertRedirect('/subscriptions');

        $this->assertSame('personal', Subscription::first()->list);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SubscriptionCrudTest`
Expected: FAIL — new fields rejected/ignored by validation, `list` not defaulted.

- [ ] **Step 3: Update `SubscriptionRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Support\CurrencyConverter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'list' => $this->input('list', 'personal'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', Rule::in(CurrencyConverter::supportedCurrencies())],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'next_renewal_date' => ['required', 'date'],
            'status' => ['required', Rule::in(['active', 'pending_cancel', 'cancelled'])],
            'cancel_url' => ['nullable', 'url', 'max:2048'],
            'notes' => ['nullable', 'string'],
            'list' => ['required', Rule::in(['personal', 'business', 'family'])],
            'category' => ['nullable', 'string', 'max:50'],
            'payment_method_id' => [
                'nullable',
                Rule::exists('payment_methods', 'id')->where(
                    fn ($query) => $query->where('user_id', $this->user()->id),
                ),
            ],
            'is_trial' => ['boolean'],
            'started_at' => ['nullable', 'date'],
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SubscriptionCrudTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/SubscriptionRequest.php tests/Feature/SubscriptionCrudTest.php
git commit -m "Update: validate list/category/payment method/trial fields

payment_method_id is scoped to the authenticated user's own payment
methods so one user can never attach another user's payment method
to their subscription.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 6: `SubscriptionController` — event logging, `show()`, payment methods prop

**Files:**
- Modify: `app/Http/Controllers/SubscriptionController.php`
- Modify: `routes/web.php` (re-enable the `show` route)
- Test: `tests/Feature/SubscriptionCrudTest.php` (extend), new `tests/Feature/SubscriptionShowTest.php`

**Interfaces:**
- Consumes: `SubscriptionEvent` (Task 3), `PaymentMethod` (Task 1), `SubscriptionRequest` (Task 5).
- Produces: route `subscriptions.show` (`GET /subscriptions/{subscription}`) rendering `Subscriptions/Show` with prop `subscription` (including `events`, `payment_method`, `subscribed_days`, `total_spent`). `create`/`edit` now also pass prop `paymentMethods` (array of `{id, label}`). Frontend Task 15 (`SubscriptionList.vue`) links to this route; Task 16 (`Show.vue`) consumes its props; Task 17 (`Create.vue`/`Edit.vue`) consumes `paymentMethods`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SubscriptionCrudTest.php`:

```php
    public function test_updating_the_amount_logs_a_price_changed_event(): void
    {
        $user = User::factory()->create();
        $sub = Subscription::factory()->for($user)->create(['amount' => 9.99]);

        $this->actingAs($user)->put("/subscriptions/{$sub->id}", [
            'name' => $sub->name,
            'amount' => 14.99,
            'currency' => $sub->currency,
            'billing_cycle' => $sub->billing_cycle,
            'next_renewal_date' => $sub->next_renewal_date->toDateString(),
            'status' => 'active',
        ])->assertRedirect('/subscriptions');

        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $sub->id,
            'kind' => 'price_changed',
        ]);
    }

    public function test_marking_active_as_cancelled_logs_a_cancelled_event(): void
    {
        $user = User::factory()->create();
        $sub = Subscription::factory()->for($user)->create(['status' => 'active']);

        $this->actingAs($user)->put("/subscriptions/{$sub->id}", [
            'name' => $sub->name,
            'amount' => $sub->amount,
            'currency' => $sub->currency,
            'billing_cycle' => $sub->billing_cycle,
            'next_renewal_date' => $sub->next_renewal_date->toDateString(),
            'status' => 'cancelled',
        ])->assertRedirect('/subscriptions');

        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $sub->id,
            'kind' => 'cancelled',
        ]);
    }

    public function test_storing_a_subscription_logs_a_subscribed_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/subscriptions', [
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
        ]);

        $sub = Subscription::first();
        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $sub->id,
            'kind' => 'subscribed',
        ]);
    }
```

Create `tests/Feature/SubscriptionShowTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_their_subscription_detail_page(): void
    {
        $user = User::factory()->create();
        $sub = Subscription::factory()->for($user)->create();

        $this->actingAs($user)
            ->get("/subscriptions/{$sub->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Show')
                ->where('subscription.id', $sub->id)
                ->has('subscription.subscribed_days')
                ->has('subscription.total_spent')
            );
    }

    public function test_a_user_cannot_view_another_users_subscription(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $sub = Subscription::factory()->for($owner)->create();

        $this->actingAs($other)->get("/subscriptions/{$sub->id}")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=Subscription`
Expected: FAIL — no `show` route (404 instead of 200/403), no event logging happening.

- [ ] **Step 3: Re-enable the `show` route**

In `routes/web.php`, change:

```php
    Route::resource('subscriptions', SubscriptionController::class)
        ->except('show');
```

to:

```php
    Route::resource('subscriptions', SubscriptionController::class);
```

- [ ] **Step 4: Update `SubscriptionController`**

Replace the full contents of `app/Http/Controllers/SubscriptionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubscriptionRequest;
use App\Models\Subscription;
use App\Support\CurrencyConverter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => $request->user()->subscriptions()->latest()->get(),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Subscriptions/Create', [
            'currencies' => CurrencyConverter::supportedCurrencies(),
            'paymentMethods' => $request->user()->paymentMethods()->get(['id', 'label']),
        ]);
    }

    public function store(SubscriptionRequest $request)
    {
        $subscription = $request->user()->subscriptions()->create($request->validated());

        $subscription->events()->create([
            'kind' => 'subscribed',
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'occurred_at' => $subscription->started_at?->toDateString() ?? now()->toDateString(),
        ]);

        return redirect('/subscriptions');
    }

    public function show(Subscription $subscription)
    {
        $this->authorize('view', $subscription);

        $subscription->load('paymentMethod', 'events');

        return Inertia::render('Subscriptions/Show', [
            'subscription' => $subscription,
        ]);
    }

    public function edit(Request $request, Subscription $subscription)
    {
        $this->authorize('update', $subscription);

        return Inertia::render('Subscriptions/Edit', [
            'subscription' => $subscription,
            'currencies' => CurrencyConverter::supportedCurrencies(),
            'paymentMethods' => $request->user()->paymentMethods()->get(['id', 'label']),
        ]);
    }

    public function update(SubscriptionRequest $request, Subscription $subscription)
    {
        $this->authorize('update', $subscription);

        $originalAmount = (float) $subscription->amount;
        $originalStatus = $subscription->status;

        $subscription->update($request->validated());

        if ((float) $subscription->amount !== $originalAmount) {
            $subscription->events()->create([
                'kind' => 'price_changed',
                'amount' => $subscription->amount,
                'currency' => $subscription->currency,
                'occurred_at' => now()->toDateString(),
            ]);
        }

        if ($originalStatus !== 'cancelled' && $subscription->status === 'cancelled') {
            $subscription->events()->create([
                'kind' => 'cancelled',
                'occurred_at' => now()->toDateString(),
            ]);
        }

        return redirect('/subscriptions');
    }

    public function destroy(Subscription $subscription)
    {
        $this->authorize('delete', $subscription);

        $subscription->delete();

        return redirect('/subscriptions');
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=Subscription`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SubscriptionController.php routes/web.php \
        tests/Feature/SubscriptionCrudTest.php tests/Feature/SubscriptionShowTest.php
git commit -m "Update: log lifecycle events and add subscription detail page

store()/update() now log subscribed/price_changed/cancelled events
to subscription_events. Re-enable the show route so subscriptions
have a dedicated detail page instead of only the sidebar panel.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 7: `PaymentMethodController` (quick-add from the subscription form)

**Files:**
- Create: `app/Http/Controllers/PaymentMethodController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PaymentMethodControllerTest.php`

**Interfaces:**
- Consumes: `User::paymentMethods()` (Task 1).
- Produces: route `payment-methods.store` (`POST /payment-methods`) returning JSON `{id, label, user_id, created_at, updated_at}`. Task 17 (`Create.vue`/`Edit.vue`) calls this via `axios.post` to append a new option without a page reload.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_payment_method_for_themselves(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/payment-methods', [
            'label' => 'Visa •••• 1234',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payment_methods', [
            'user_id' => $user->id,
            'label' => 'Visa •••• 1234',
        ]);
    }

    public function test_label_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/payment-methods', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label');
    }

    public function test_guest_cannot_create_a_payment_method(): void
    {
        $this->postJson('/payment-methods', ['label' => 'Visa'])->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PaymentMethodControllerTest`
Expected: FAIL — route doesn't exist (404).

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
        ]);

        $paymentMethod = $request->user()->paymentMethods()->create($validated);

        return response()->json($paymentMethod, 201);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, inside the existing `Route::middleware('auth')->group(...)` block (alongside the `subscriptions` resource), add:

```php
    Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
```

And add the import at the top of the file:

```php
use App\Http\Controllers\PaymentMethodController;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PaymentMethodControllerTest`
Expected: PASS. Note: guest requests to a web-middleware route normally redirect (302) rather than 401 — if `assertUnauthorized()` fails with a redirect assertion mismatch, change that one assertion to `assertRedirect('/login')` to match this app's existing auth-redirect behavior (see `SubscriptionCrudTest::test_guest_is_redirected_from_subscriptions_index`).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/PaymentMethodController.php routes/web.php \
        tests/Feature/PaymentMethodControllerTest.php
git commit -m "Update: add endpoint to quick-add a payment method

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 8: Renewal reminder notification + scheduled command

**Files:**
- Create: `app/Notifications/SubscriptionRenewalReminder.php`
- Create: `app/Console/Commands/SendRenewalReminders.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/SendRenewalRemindersTest.php`

**Interfaces:**
- Consumes: `Subscription` (Task 2: `last_reminder_sent_for`), `User` (Task 4: `renewal_reminders_enabled`, `reminder_days_before`).
- Produces: Artisan command `subscriptions:send-renewal-reminders`, scheduled daily. `Subscriptions/Edit.vue` (Task 17) reads `renewal_reminders_enabled`/`email_verified_at` off the shared `auth.user` prop (already exposed by `HandleInertiaRequests`, no controller change needed) to show its banner.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionRenewalReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendRenewalRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_a_reminder_when_the_renewal_is_exactly_reminder_days_before_away(): void
    {
        Notification::fake();

        $user = User::factory()->create(['reminder_days_before' => 3]);
        $subscription = Subscription::factory()->for($user)->create([
            'status' => 'active',
            'next_renewal_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->artisan('subscriptions:send-renewal-reminders')->assertExitCode(0);

        Notification::assertSentTo($user, SubscriptionRenewalReminder::class);
        $this->assertSame(
            $subscription->fresh()->next_renewal_date->toDateString(),
            $subscription->fresh()->last_reminder_sent_for->toDateString(),
        );
    }

    public function test_does_not_send_twice_for_the_same_renewal_date(): void
    {
        Notification::fake();

        $user = User::factory()->create(['reminder_days_before' => 3]);
        $renewalDate = now()->addDays(3)->toDateString();
        Subscription::factory()->for($user)->create([
            'status' => 'active',
            'next_renewal_date' => $renewalDate,
            'last_reminder_sent_for' => $renewalDate,
        ]);

        $this->artisan('subscriptions:send-renewal-reminders');

        Notification::assertNotSentTo($user, SubscriptionRenewalReminder::class);
    }

    public function test_does_not_send_when_reminders_are_disabled(): void
    {
        Notification::fake();

        $user = User::factory()->create(['renewal_reminders_enabled' => false, 'reminder_days_before' => 3]);
        Subscription::factory()->for($user)->create([
            'status' => 'active',
            'next_renewal_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->artisan('subscriptions:send-renewal-reminders');

        Notification::assertNotSentTo($user, SubscriptionRenewalReminder::class);
    }

    public function test_does_not_send_when_the_subscription_is_cancelled(): void
    {
        Notification::fake();

        $user = User::factory()->create(['reminder_days_before' => 3]);
        Subscription::factory()->for($user)->create([
            'status' => 'cancelled',
            'next_renewal_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->artisan('subscriptions:send-renewal-reminders');

        Notification::assertNothingSent();
    }

    public function test_does_not_send_before_the_reminder_window(): void
    {
        Notification::fake();

        $user = User::factory()->create(['reminder_days_before' => 3]);
        Subscription::factory()->for($user)->create([
            'status' => 'active',
            'next_renewal_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->artisan('subscriptions:send-renewal-reminders');

        Notification::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SendRenewalRemindersTest`
Expected: FAIL — command doesn't exist.

- [ ] **Step 3: Write the notification**

```php
<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionRenewalReminder extends Notification
{
    use Queueable;

    public function __construct(public Subscription $subscription)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Sắp đến hạn thanh toán: {$this->subscription->name}")
            ->greeting("Xin chào {$notifiable->name},")
            ->line(sprintf(
                'Dịch vụ "%s" sẽ gia hạn vào ngày %s với số tiền %s %s.',
                $this->subscription->name,
                $this->subscription->next_renewal_date->format('d/m/Y'),
                $this->subscription->amount,
                $this->subscription->currency,
            ))
            ->action('Xem chi tiết', route('subscriptions.show', $this->subscription))
            ->line('Nếu muốn hủy trước ngày gia hạn, vào trang chi tiết dịch vụ để thao tác.');
    }
}
```

- [ ] **Step 4: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Notifications\SubscriptionRenewalReminder;
use Illuminate\Console\Command;

class SendRenewalReminders extends Command
{
    protected $signature = 'subscriptions:send-renewal-reminders';

    protected $description = 'Gửi email nhắc nhở cho các subscription sắp đến hạn gia hạn';

    public function handle(): int
    {
        $today = now()->toDateString();

        Subscription::query()
            ->where('status', 'active')
            ->whereHas('user', function ($query) {
                $query->where('renewal_reminders_enabled', true)
                    ->whereNotNull('email_verified_at');
            })
            ->with('user')
            ->chunkById(100, function ($subscriptions) use ($today) {
                foreach ($subscriptions as $subscription) {
                    $targetDate = $subscription->next_renewal_date
                        ->copy()
                        ->subDays($subscription->user->reminder_days_before)
                        ->toDateString();

                    if ($targetDate !== $today) {
                        continue;
                    }

                    if ($subscription->last_reminder_sent_for?->toDateString() === $subscription->next_renewal_date->toDateString()) {
                        continue;
                    }

                    $subscription->user->notify(new SubscriptionRenewalReminder($subscription));
                    $subscription->update(['last_reminder_sent_for' => $subscription->next_renewal_date]);
                }
            });

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Register the daily schedule**

Append to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('subscriptions:send-renewal-reminders')->dailyAt('08:00');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=SendRenewalRemindersTest`
Expected: PASS

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Notifications/SubscriptionRenewalReminder.php \
        app/Console/Commands/SendRenewalReminders.php routes/console.php \
        tests/Feature/SendRenewalRemindersTest.php
git commit -m "Update: send real email reminders before subscriptions renew

Daily-scheduled command emails users N days (per-user preference,
default 3) before a subscription renews. Uses last_reminder_sent_for
to avoid duplicate sends for the same renewal cycle. Verifiable
locally via Mailpit.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 9: `ProfileController` — notification preferences endpoint

**Files:**
- Modify: `app/Http/Controllers/ProfileController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ProfileTest.php` (extend)

**Interfaces:**
- Consumes: `User` columns from Task 4.
- Produces: route `profile.notifications.update` (`PATCH /profile/notifications`). Task 21 (`NotificationPreferencesForm.vue`) posts to this route.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/ProfileTest.php` (read the file first to match its existing style before appending):

```php
    public function test_user_can_update_notification_preferences(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile/notifications', [
                'renewal_reminders_enabled' => false,
                'reminder_days_before' => 7,
            ])
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertFalse($user->renewal_reminders_enabled);
        $this->assertSame(7, $user->reminder_days_before);
    }

    public function test_reminder_days_before_must_be_one_of_the_allowed_options(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile/notifications', [
                'renewal_reminders_enabled' => true,
                'reminder_days_before' => 4,
            ])
            ->assertSessionHasErrors('reminder_days_before');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProfileTest`
Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Add the controller method**

In `app/Http/Controllers/ProfileController.php`, add the import `use Illuminate\Validation\Rule;` and this method (after `update()`):

```php
    /**
     * Update the user's renewal reminder preferences.
     */
    public function updateNotificationPreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'renewal_reminders_enabled' => ['required', 'boolean'],
            'reminder_days_before' => ['required', 'integer', Rule::in([1, 3, 7])],
        ]);

        $request->user()->update($validated);

        return Redirect::route('profile.edit');
    }
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, alongside the existing `/profile` routes:

```php
    Route::patch('/profile/notifications', [ProfileController::class, 'updateNotificationPreferences'])->name('profile.notifications.update');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ProfileTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/ProfileController.php routes/web.php tests/Feature/ProfileTest.php
git commit -m "Update: add endpoint to update renewal reminder preferences

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 10: `CalendarController` + route

**Files:**
- Create: `app/Http/Controllers/CalendarController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CalendarTest.php`

**Interfaces:**
- Consumes: `Subscription` columns (Task 2 adds `started_at`, needed for client-side lower-bound clamping).
- Produces: route `calendar.index` (`GET /calendar`) rendering `Calendar/Index` with prop `subscriptions`. Task 19 (`Calendar/Index.vue`) consumes this.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/calendar')->assertRedirect(route('login'));
    }

    public function test_calendar_renders_only_the_current_users_non_cancelled_subscriptions(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create(['name' => 'Netflix', 'status' => 'active']);
        Subscription::factory()->for($user)->create(['name' => 'Old', 'status' => 'cancelled']);

        $other = User::factory()->create();
        Subscription::factory()->for($other)->create(['name' => 'Theirs', 'status' => 'active']);

        $this->actingAs($user)
            ->get('/calendar')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Calendar/Index')
                ->has('subscriptions', 1)
                ->where('subscriptions.0.name', 'Netflix')
            );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CalendarTest`
Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $subscriptions = $request->user()->subscriptions()
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get([
                'id', 'name', 'provider_key', 'amount', 'currency', 'amount_vnd',
                'billing_cycle', 'next_renewal_date', 'status', 'started_at',
            ]);

        return Inertia::render('Calendar/Index', [
            'subscriptions' => $subscriptions,
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add near the `/dashboard` route declaration (same middleware pattern):

```php
Route::get('/calendar', [CalendarController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('calendar.index');
```

And add the import: `use App\Http\Controllers\CalendarController;`

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CalendarTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/CalendarController.php routes/web.php tests/Feature/CalendarTest.php
git commit -m "Update: add Calendar page route and controller

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 11: `orbit/brandIcon.js` + `simple-icons` + `BrandIcon.vue`

**Files:**
- Modify: `resources/js/orbit/brandColors.js` (export `normalize`)
- Create: `resources/js/orbit/brandIcon.js`
- Create: `resources/js/orbit/brandIcon.test.js`
- Create: `resources/js/orbit/BrandIcon.vue`
- Modify: `package.json` (add `simple-icons` dependency)

**Interfaces:**
- Consumes: `normalize` (newly exported from `brandColors.js`).
- Produces: `resolveBrandIcon(name): {path, hex, title} | null`. `BrandIcon.vue` component: props `name` (string, required), `size` (number, default 32). Task 15 (`SubscriptionList.vue`) and Task 16 (`Show.vue`) use `BrandIcon.vue`.

- [ ] **Step 1: Install the dependency**

```bash
npm install simple-icons
```

- [ ] **Step 2: Write the failing test**

Create `resources/js/orbit/brandIcon.test.js`:

```js
import { describe, it, expect } from 'vitest';
import { resolveBrandIcon } from './brandIcon.js';

describe('resolveBrandIcon', () => {
    it('resolves a known brand case-insensitively', () => {
        const icon = resolveBrandIcon('Netflix');
        expect(icon).not.toBeNull();
        expect(icon.title.toLowerCase()).toContain('netflix');
        expect(typeof icon.path).toBe('string');
        expect(typeof icon.hex).toBe('string');
    });

    it('resolves chatgpt and openai to the same icon', () => {
        expect(resolveBrandIcon('ChatGPT Plus').path).toBe(resolveBrandIcon('OpenAI').path);
    });

    it('returns null for an unknown service', () => {
        expect(resolveBrandIcon('Some Random Local Gym')).toBeNull();
    });

    it('returns null for an empty name', () => {
        expect(resolveBrandIcon('')).toBeNull();
    });
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `npm run test:js -- brandIcon`
Expected: FAIL — `brandIcon.js` doesn't exist.

- [ ] **Step 4: Export `normalize` from `brandColors.js`**

In `resources/js/orbit/brandColors.js`, change:

```js
function normalize(name) {
```

to:

```js
export function normalize(name) {
```

(no other change to that file — `brandColor`/`initial`/`contrastText` keep calling `normalize` exactly as before, now via the exported binding).

- [ ] **Step 5: Write `brandIcon.js`**

```js
// Curated brand-icon lookup for the subscription list/detail UI. Mirrors
// the keyword catalog in brandColors.js — small and hand-picked, not the
// full simple-icons dataset, to keep the bundle lean.
import {
    siNetflix,
    siSpotify,
    siYoutube,
    siOpenai,
    siAdobe,
    siApple,
    siGoogle,
    siAmazon,
    siDisneyplus,
} from 'simple-icons';
import { normalize } from './brandColors.js';

const ICON_CATALOG = [
    ['netflix', siNetflix],
    ['spotify', siSpotify],
    ['youtube', siYoutube],
    ['chatgpt', siOpenai],
    ['openai', siOpenai],
    ['adobe', siAdobe],
    ['apple', siApple],
    ['google', siGoogle],
    ['amazon', siAmazon],
    ['prime', siAmazon],
    ['disney', siDisneyplus],
];

export function resolveBrandIcon(name) {
    const norm = normalize(name);
    if (!norm) return null;

    for (const [keyword, icon] of ICON_CATALOG) {
        if (norm.includes(keyword)) {
            return { path: icon.path, hex: icon.hex, title: icon.title };
        }
    }

    return null;
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `npm run test:js -- brandIcon`
Expected: PASS

- [ ] **Step 7: Run the full Vitest suite to check `normalize` export didn't break anything**

Run: `npm run test:js`
Expected: PASS (all existing `brandColors.test.js` tests still pass, plus the new file)

- [ ] **Step 8: Write `BrandIcon.vue`**

```vue
<script setup>
import { computed } from 'vue';
import { resolveBrandIcon } from './brandIcon.js';
import { brandColor, contrastText, initial } from './brandColors.js';

const props = defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 32 },
});

const icon = computed(() => resolveBrandIcon(props.name));
const color = computed(() => brandColor(props.name));
const textColor = computed(() => contrastText(color.value));
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full"
        :style="{
            width: size + 'px',
            height: size + 'px',
            backgroundColor: icon ? '#fff' : color,
        }"
    >
        <svg
            v-if="icon"
            viewBox="0 0 24 24"
            :width="size * 0.6"
            :height="size * 0.6"
            :fill="'#' + icon.hex"
        >
            <path :d="icon.path" />
        </svg>
        <span v-else class="text-sm font-bold" :style="{ color: textColor }">
            {{ initial(name) }}
        </span>
    </span>
</template>
```

- [ ] **Step 9: Commit**

```bash
git add package.json package-lock.json resources/js/orbit/brandColors.js \
        resources/js/orbit/brandIcon.js resources/js/orbit/brandIcon.test.js \
        resources/js/orbit/BrandIcon.vue
git commit -m "Update: add real brand icons via simple-icons with letter fallback

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 12: `orbit/layout.js` — `annualizedVnd()` helper

**Files:**
- Modify: `resources/js/orbit/layout.js`
- Modify: `resources/js/orbit/layout.test.js`

**Interfaces:**
- Consumes: a subscription-shaped object `{ amount_vnd, billing_cycle }`.
- Produces: `annualizedVnd(sub): number`. Task 20 (`Dashboard.vue`) uses this for the "total chi phí/năm" stat.

- [ ] **Step 1: Write the failing test**

Append to `resources/js/orbit/layout.test.js` (add `annualizedVnd` to the existing import line at the top of the file):

```js
describe('annualizedVnd', () => {
    it('multiplies a monthly amount by 12', () => {
        expect(annualizedVnd({ amount_vnd: 100000, billing_cycle: 'monthly' })).toBe(1200000);
    });
    it('returns a yearly amount unchanged', () => {
        expect(annualizedVnd({ amount_vnd: 1200000, billing_cycle: 'yearly' })).toBe(1200000);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:js -- layout`
Expected: FAIL — `annualizedVnd` is not exported.

- [ ] **Step 3: Add the function**

Append to `resources/js/orbit/layout.js`:

```js
export function annualizedVnd(sub) {
    return sub.billing_cycle === 'yearly' ? sub.amount_vnd : sub.amount_vnd * 12;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:js -- layout`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/orbit/layout.js resources/js/orbit/layout.test.js
git commit -m "Update: add annualizedVnd helper for the dashboard stats row

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 13: `orbit/calendar.js` — occurrence projection

**Files:**
- Create: `resources/js/orbit/calendar.js`
- Create: `resources/js/orbit/calendar.test.js`

**Interfaces:**
- Consumes: subscription objects shaped like the `CalendarController` prop (Task 10): `{ id, name, billing_cycle, next_renewal_date, status, amount_vnd, started_at }`.
- Produces: `projectOccurrences(subscriptions, year, monthIndex): Array<{date: string, subscription: object}>`, `groupByDate(occurrences): Record<string, array>`, `monthTotals(occurrences, today): {total: number, upcoming: number}`. Task 19 (`Calendar/Index.vue`) consumes all three.

- [ ] **Step 1: Write the failing test**

```js
import { describe, it, expect } from 'vitest';
import { projectOccurrences, groupByDate, monthTotals } from './calendar.js';

const monthlySub = {
    id: 1,
    name: 'Netflix',
    billing_cycle: 'monthly',
    next_renewal_date: '2026-05-15',
    status: 'active',
    amount_vnd: 260000,
    started_at: null,
};

const yearlySub = {
    id: 2,
    name: 'Adobe',
    billing_cycle: 'yearly',
    next_renewal_date: '2026-01-26',
    status: 'active',
    amount_vnd: 15000000,
    started_at: null,
};

describe('projectOccurrences', () => {
    it('projects a monthly subscription into an earlier month', () => {
        const occurrences = projectOccurrences([monthlySub], 2026, 3); // April 2026 (0-indexed)
        expect(occurrences).toHaveLength(1);
        expect(occurrences[0].date).toBe('2026-04-15');
    });

    it('projects a monthly subscription into a later month', () => {
        const occurrences = projectOccurrences([monthlySub], 2026, 6); // July 2026
        expect(occurrences).toHaveLength(1);
        expect(occurrences[0].date).toBe('2026-07-15');
    });

    it('only projects a yearly subscription into the matching month', () => {
        expect(projectOccurrences([yearlySub], 2026, 0)).toHaveLength(1); // January
        expect(projectOccurrences([yearlySub], 2026, 1)).toHaveLength(0); // February
        expect(projectOccurrences([yearlySub], 2027, 0)).toHaveLength(1); // January next year
    });

    it('excludes cancelled subscriptions', () => {
        const cancelled = { ...monthlySub, status: 'cancelled' };
        expect(projectOccurrences([cancelled], 2026, 3)).toHaveLength(0);
    });

    it('does not project occurrences before started_at', () => {
        const startedLate = { ...monthlySub, started_at: '2026-06-01' };
        expect(projectOccurrences([startedLate], 2026, 3)).toHaveLength(0); // April, before start
        expect(projectOccurrences([startedLate], 2026, 6)).toHaveLength(1); // July, after start
    });
});

describe('groupByDate', () => {
    it('groups multiple occurrences on the same date', () => {
        const occurrences = [
            { date: '2026-04-15', subscription: monthlySub },
            { date: '2026-04-15', subscription: yearlySub },
        ];
        const grouped = groupByDate(occurrences);
        expect(grouped['2026-04-15']).toHaveLength(2);
    });
});

describe('monthTotals', () => {
    const occurrences = [
        { date: '2026-04-01', subscription: { amount_vnd: 100000 } },
        { date: '2026-04-20', subscription: { amount_vnd: 200000 } },
    ];

    it('sums every occurrence for total', () => {
        expect(monthTotals(occurrences, '2026-04-25').total).toBe(300000);
    });

    it('only counts occurrences on/after today for upcoming', () => {
        expect(monthTotals(occurrences, '2026-04-10').upcoming).toBe(200000);
    });

    it('upcoming is 0 once every occurrence is in the past', () => {
        expect(monthTotals(occurrences, '2026-04-25').upcoming).toBe(0);
    });

    it('upcoming equals total when every occurrence is still ahead', () => {
        expect(monthTotals(occurrences, '2026-03-01').upcoming).toBe(300000);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:js -- calendar`
Expected: FAIL — `calendar.js` doesn't exist.

- [ ] **Step 3: Write `calendar.js`**

```js
// Pure date-projection math for the Calendar page. No DOM, no Vue —
// unit-tested in calendar.test.js.

function addCycle(dateStr, cycle, steps) {
    const [y, m, d] = dateStr.slice(0, 10).split('-').map(Number);
    if (cycle === 'yearly') {
        return new Date(Date.UTC(y + steps, m - 1, d));
    }
    return new Date(Date.UTC(y, m - 1 + steps, d));
}

function toDateStr(date) {
    return date.toISOString().slice(0, 10);
}

const MAX_STEPS = 36;

export function projectOccurrences(subscriptions, year, monthIndex) {
    const monthStart = Date.UTC(year, monthIndex, 1);
    const monthEnd = Date.UTC(year, monthIndex + 1, 0);
    const occurrences = [];

    for (const sub of subscriptions) {
        if (sub.status === 'cancelled') continue;

        const lowerBound = sub.started_at
            ? Date.parse(`${sub.started_at.slice(0, 10)}T00:00:00Z`)
            : -Infinity;

        for (let steps = -MAX_STEPS; steps <= MAX_STEPS; steps++) {
            const occurrence = addCycle(sub.next_renewal_date, sub.billing_cycle, steps);
            const ts = occurrence.getTime();
            if (ts < lowerBound) continue;
            if (ts >= monthStart && ts <= monthEnd) {
                occurrences.push({ date: toDateStr(occurrence), subscription: sub });
            }
        }
    }

    return occurrences;
}

export function groupByDate(occurrences) {
    const map = {};
    for (const occ of occurrences) {
        (map[occ.date] ??= []).push(occ);
    }
    return map;
}

export function monthTotals(occurrences, today) {
    const todayTs = Date.parse(`${today}T00:00:00Z`);
    let total = 0;
    let upcoming = 0;

    for (const occ of occurrences) {
        total += occ.subscription.amount_vnd;
        if (Date.parse(`${occ.date}T00:00:00Z`) >= todayTs) {
            upcoming += occ.subscription.amount_vnd;
        }
    }

    return { total, upcoming };
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:js -- calendar`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/orbit/calendar.js resources/js/orbit/calendar.test.js
git commit -m "Update: add pure date-projection module for the Calendar page

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 14: `Planet.vue` drops the initial letter; `Orbit.vue` navigates to the detail page; remove `OrbitDetailPanel.vue`

**Files:**
- Modify: `resources/js/orbit/Planet.vue`
- Modify: `resources/js/orbit/Orbit.vue`
- Delete: `resources/js/orbit/OrbitDetailPanel.vue`

**Interfaces:**
- Consumes: route `subscriptions.show` (Task 6).
- Produces: `Orbit.vue` no longer has `selectedId`/`selectedSub` state or an `OrbitDetailPanel` import; clicking a planet calls `router.visit(route('subscriptions.show', sub.id))`.

- [ ] **Step 1: Remove the initial-letter `<text>` from `Planet.vue`**

In `resources/js/orbit/Planet.vue`, remove the `letter` prop and its `<text>` element:

```vue
<script setup>
defineProps({
    cx: { type: Number, required: true },
    cy: { type: Number, required: true },
    radius: { type: Number, required: true },
    color: { type: String, required: true },
    status: { type: String, required: true },
    urgent: { type: Boolean, default: false },
});

const emit = defineEmits(['hover', 'leave', 'select']);
</script>

<template>
    <g
        class="orbit-planet"
        @mouseenter="emit('hover')"
        @mouseleave="emit('leave')"
        @click="emit('select')"
    >
        <circle
            v-if="urgent"
            :cx="cx"
            :cy="cy"
            :r="radius + 6"
            :fill="color"
            class="orbit-pulse"
        />
        <circle :cx="cx" :cy="cy" :r="radius" :fill="color" />
        <circle
            v-if="status === 'pending_cancel'"
            :cx="cx"
            :cy="cy"
            :r="radius + 3"
            fill="none"
            stroke="#F59E0B"
            stroke-width="2"
            stroke-dasharray="4 3"
        />
    </g>
</template>

<style>
.orbit-planet {
    cursor: pointer;
}
.orbit-pulse {
    opacity: 0.25;
    transform-box: fill-box;
    transform-origin: center;
    animation: orbit-pulse 1.6s ease-in-out infinite;
}
@keyframes orbit-pulse {
    0%,
    100% {
        opacity: 0.15;
    }
    50% {
        opacity: 0.45;
    }
}
</style>
```

(`textColor`/`letter` are no longer passed by `Orbit.vue` after Step 2 — remove them there too, not just here.)

- [ ] **Step 2: Update `Orbit.vue`**

In `resources/js/orbit/Orbit.vue`:
- Change the import line `import { Head, Link, router, usePage } from '@inertiajs/vue3';`... actually this file doesn't currently import Inertia — add `import { router } from '@inertiajs/vue3';` near the top imports.
- Remove the `import OrbitDetailPanel from './OrbitDetailPanel.vue';` import.
- Remove `textColor: contrastText(color)` and `letter: initial(sub.name)` from the object built in `buildOrbit()` (keep everything else in that function as-is).
- Remove the now-unused `contrastText` and `initial` imports from `./brandColors.js` (keep `brandColor`).
- Remove `const selectedId = ref(null);` and the `selectedSub` computed.
- Change the `<Planet ... @select="selectedId = p.sub.id" />` line to `@select="router.visit(route('subscriptions.show', p.sub.id))"` and remove the now-unused `:text-color="p.textColor"` and `:letter="p.letter"` bindings on `<Planet>`.
- Remove the `<OrbitDetailPanel v-if="selectedSub" ... />` block at the bottom of the template.

The full updated `<script setup>` block:

```vue
<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Planet from './Planet.vue';
import {
    distributeAngles,
    planetRadius,
    polarToXy,
    daysUntil,
    isUrgent,
} from './layout.js';
import { brandColor, initial } from './brandColors.js';
import PlanetTooltip from './PlanetTooltip.vue';

const props = defineProps({
    subscriptions: { type: Array, required: true },
    user: { type: Object, default: () => ({}) },
});

const VIEW = 800;
const CENTER = VIEW / 2;
const ORBIT_MONTHLY = 150;
const ORBIT_YEARLY = 300;
const R_MIN = 12;
const R_MAX = 40;
const SPEED_MONTHLY = 12; // degrees per second
const SPEED_YEARLY = 5;

const elapsed = ref(0); // seconds since mount, drives drift
let rafId = null;
let last = null;

function frame(ts) {
    if (last === null) last = ts;
    elapsed.value += (ts - last) / 1000;
    last = ts;
    rafId = requestAnimationFrame(frame);
}

onMounted(() => {
    rafId = requestAnimationFrame(frame);
});

onBeforeUnmount(() => {
    if (rafId) cancelAnimationFrame(rafId);
});

const today = new Date().toISOString().slice(0, 10);

const amounts = computed(() =>
    props.subscriptions.map((s) => Number(s.amount_vnd)),
);
const minVnd = computed(() =>
    amounts.value.length ? Math.min(...amounts.value) : 0,
);
const maxVnd = computed(() =>
    amounts.value.length ? Math.max(...amounts.value) : 0,
);

function buildOrbit(list, orbitRadius, speed) {
    const offset = elapsed.value * speed;
    const angles = distributeAngles(list.length, offset);
    return list.map((sub, i) => {
        const pos = polarToXy(CENTER, CENTER, orbitRadius, angles[i]);
        const color = brandColor(sub.name);
        return {
            sub,
            x: pos.x,
            y: pos.y,
            radius: planetRadius(Number(sub.amount_vnd), minVnd.value, maxVnd.value, {
                rMin: R_MIN,
                rMax: R_MAX,
            }),
            color,
            days: daysUntil(sub.next_renewal_date, today),
            urgent: isUrgent(daysUntil(sub.next_renewal_date, today)),
        };
    });
}

const monthly = computed(() =>
    buildOrbit(
        props.subscriptions.filter((s) => s.billing_cycle === 'monthly'),
        ORBIT_MONTHLY,
        SPEED_MONTHLY,
    ),
);
const yearly = computed(() =>
    buildOrbit(
        props.subscriptions.filter((s) => s.billing_cycle === 'yearly'),
        ORBIT_YEARLY,
        SPEED_YEARLY,
    ),
);
const planets = computed(() => [...monthly.value, ...yearly.value]);

const hoveredId = ref(null);

const hoveredPlanet = computed(
    () => planets.value.find((p) => p.sub.id === hoveredId.value) ?? null,
);

const sunInitial = computed(() => initial(props.user?.name ?? ''));
</script>
```

Update the `<Planet>` element and remove the detail panel:

```vue
            <Planet
                v-for="p in planets"
                :key="p.sub.id"
                :cx="p.x"
                :cy="p.y"
                :radius="p.radius"
                :color="p.color"
                :status="p.sub.status"
                :urgent="p.urgent"
                @hover="hoveredId = p.sub.id"
                @leave="hoveredId = null"
                @select="router.visit(route('subscriptions.show', p.sub.id))"
            />
        </svg>

        <PlanetTooltip
            v-if="hoveredPlanet"
            :sub="hoveredPlanet.sub"
            :days="hoveredPlanet.days"
            :left-pct="(hoveredPlanet.x / VIEW) * 100"
            :top-pct="(hoveredPlanet.y / VIEW) * 100"
        />
    </div>
</template>
```

(the `<svg>` opening tag, orbit-ring `<circle>`s, sun `<g>`, and outer wrapping `<div>` are unchanged from the current file.)

- [ ] **Step 3: Delete the now-unused detail panel**

```bash
rm resources/js/orbit/OrbitDetailPanel.vue
```

- [ ] **Step 4: Build and manually verify in the browser**

Run: `npm run build` (or `npm run dev` if the ownership issue on `public/build` from the earlier session is still unresolved — ask the user to `sudo chown -R $USER public/build` first if `npm run build` fails with `EACCES`)

Then, with the app running, log in, go to `/dashboard`, and confirm:
- Planets show no letter, just the colored circle (+ amber dashed ring for `pending_cancel`, + pulse for urgent).
- Clicking a planet navigates to `/subscriptions/{id}` (Task 6's route — the actual `Subscriptions/Show` page doesn't exist until Task 16, so at this point in the plan it's fine to see a 404/Inertia error page; that will resolve once Task 16 lands. If you want a clean visual check at this exact task, temporarily test by inspecting the `router.visit` call target via the browser's network tab instead of waiting for Task 16.)

- [ ] **Step 5: Commit**

```bash
git add resources/js/orbit/Planet.vue resources/js/orbit/Orbit.vue
git rm resources/js/orbit/OrbitDetailPanel.vue
git commit -m "Update: orbit planets navigate to the detail page, drop letters

Matches the reference app's plain colored moons. The side panel is
replaced by the new Subscriptions/Show page (added in a later task)
for a consistent experience across screen sizes.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 15: `SubscriptionList.vue` — icon, renewal countdown, chevron row

**Files:**
- Modify: `resources/js/orbit/SubscriptionList.vue`

**Interfaces:**
- Consumes: `BrandIcon.vue` (Task 11), route `subscriptions.show` (Task 6).
- Produces: same prop signature (`subscriptions: Array`) — no change needed in `Dashboard.vue` or `Subscriptions/Index.vue` for this task alone.

- [ ] **Step 1: Replace the row template**

Replace the full contents of `resources/js/orbit/SubscriptionList.vue`:

```vue
<script setup>
import { Link } from '@inertiajs/vue3';
import BrandIcon from './BrandIcon.vue';
import { statusLabel, cycleLabel, formatVnd } from './labels.js';
import { daysUntil } from './layout.js';

defineProps({
    subscriptions: { type: Array, required: true },
});

const statusClass = {
    active: 'bg-emerald-500/15 text-emerald-300',
    pending_cancel: 'bg-amber-500/15 text-amber-300',
    cancelled: 'bg-slate-500/15 text-slate-400',
};

const today = new Date().toISOString().slice(0, 10);

function renewsInLabel(sub) {
    const days = daysUntil(sub.next_renewal_date, today);
    const date = sub.next_renewal_date?.slice(0, 10);
    if (days < 0) return `Quá hạn ${Math.abs(days)} ngày · ${date}`;
    if (days === 0) return `Tới hạn hôm nay · ${date}`;
    return `Còn ${days} ngày · ${date}`;
}

function priceLabel(sub) {
    if (sub.currency === 'VND') return `${formatVnd(sub.amount_vnd)} ₫`;
    return `${sub.amount} ${sub.currency}`;
}
</script>

<template>
    <ul class="space-y-3">
        <li v-for="sub in subscriptions" :key="sub.id">
            <Link
                :href="route('subscriptions.show', sub.id)"
                class="flex items-center gap-3 rounded-2xl border border-white/5 bg-midnight-900 p-4 shadow-lg shadow-black/10 transition hover:border-white/10"
            >
                <BrandIcon :name="sub.name" :size="40" />

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <p class="truncate font-semibold text-slate-100">{{ sub.name }}</p>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="statusClass[sub.status] ?? statusClass.cancelled"
                        >
                            {{ statusLabel[sub.status] ?? sub.status }}
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ renewsInLabel(sub) }}</p>
                </div>

                <div class="shrink-0 text-right">
                    <p class="font-semibold text-slate-100">{{ priceLabel(sub) }}</p>
                </div>

                <svg class="h-5 w-5 shrink-0 text-slate-600" viewBox="0 0 20 20" fill="currentColor">
                    <path
                        fill-rule="evenodd"
                        d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                        clip-rule="evenodd"
                    />
                </svg>
            </Link>
        </li>
    </ul>
</template>
```

Note: `cycleLabel` is imported but no longer used directly in this file (the renewal line now shows the countdown instead of the cycle) — remove it from the import if your editor/linter flags unused imports: `import { statusLabel, formatVnd } from './labels.js';`.

- [ ] **Step 2: Build and manually verify in the browser**

Run: `npm run build` (or `npm run dev`), then visit `/dashboard` and `/subscriptions` while logged in. Confirm each row shows: brand icon (or letter fallback), name + status pill, "Còn N ngày · date", price, chevron, and that clicking a row navigates toward `/subscriptions/{id}` (full page renders correctly once Task 16 lands).

- [ ] **Step 3: Run the JS test suite to make sure nothing broke**

Run: `npm run test:js`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add resources/js/orbit/SubscriptionList.vue
git commit -m "Update: redesign subscription rows with brand icon and chevron

Rows are now a single tappable link to the subscription detail page;
inline Sửa/Xóa buttons move there instead.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 16: `Pages/Subscriptions/Show.vue` — detail page

**Files:**
- Create: `resources/js/Pages/Subscriptions/Show.vue`

**Interfaces:**
- Consumes: prop `subscription` from `SubscriptionController@show` (Task 6): `{id, name, amount, currency, amount_vnd, billing_cycle, next_renewal_date, status, category, is_trial, subscribed_days, total_spent, payment_method: {label}|null, events: [{id, kind, amount, currency, occurred_at}]}`. Uses `BrandIcon.vue` (Task 11).
- Produces: nothing consumed by later tasks — this is a leaf page. Uses `route('subscriptions.edit', ...)`, `route('subscriptions.destroy', ...)`, `route('subscriptions.update', ...)` (for the Mark as Cancelled PATCH-via-PUT).

- [ ] **Step 1: Write the page**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrandIcon from '@/orbit/BrandIcon.vue';
import { statusLabel, cycleLabel, formatVnd } from '@/orbit/labels.js';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    subscription: { type: Object, required: true },
});

const eventLabel = {
    subscribed: 'Đã đăng ký',
    price_changed: 'Đổi giá',
    cancelled: 'Đã hủy',
};

const vnd = computed(() => formatVnd(props.subscription.amount_vnd));

const cancelForm = useForm({
    name: props.subscription.name,
    amount: props.subscription.amount,
    currency: props.subscription.currency,
    billing_cycle: props.subscription.billing_cycle,
    next_renewal_date: props.subscription.next_renewal_date?.slice(0, 10),
    status: 'cancelled',
    category: props.subscription.category,
    list: props.subscription.list,
});

function markCancelled() {
    if (!confirm(`Đánh dấu "${props.subscription.name}" là đã hủy?`)) return;
    cancelForm.put(route('subscriptions.update', props.subscription.id));
}

const deleteForm = useForm({});

function destroy() {
    if (!confirm(`Xóa "${props.subscription.name}"? Hành động này không thể hoàn tác.`)) return;
    deleteForm.delete(route('subscriptions.destroy', props.subscription.id));
}
</script>

<template>
    <Head :title="subscription.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-extrabold tracking-tight text-white">{{ subscription.name }}</h2>
                <Link
                    :href="route('subscriptions.edit', subscription.id)"
                    class="text-sm text-violet-400 hover:text-violet-300 hover:underline"
                >
                    Sửa
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-4 rounded-2xl border border-white/5 bg-midnight-900 p-6 shadow-lg shadow-black/20">
                    <BrandIcon :name="subscription.name" :size="56" />
                    <div>
                        <p class="text-xl font-bold text-white">{{ subscription.name }}</p>
                        <p class="text-lg text-slate-300">
                            {{ subscription.amount }} {{ subscription.currency }}
                            <span v-if="subscription.currency !== 'VND'" class="text-sm text-slate-500">({{ vnd }} ₫)</span>
                        </p>
                    </div>
                </div>

                <dl class="grid grid-cols-2 gap-4 rounded-2xl border border-white/5 bg-midnight-900 p-6 text-sm shadow-lg shadow-black/20">
                    <div>
                        <dt class="text-slate-500">Chu kỳ</dt>
                        <dd class="text-slate-100">{{ cycleLabel[subscription.billing_cycle] ?? subscription.billing_cycle }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Gia hạn tiếp theo</dt>
                        <dd class="text-slate-100">{{ subscription.next_renewal_date?.slice(0, 10) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Đã chi</dt>
                        <dd class="text-slate-100">{{ formatVnd(subscription.total_spent) }} ₫</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Đã đăng ký</dt>
                        <dd class="text-slate-100">{{ subscription.subscribed_days }} ngày</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Danh mục</dt>
                        <dd class="text-slate-100">{{ subscription.category ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Phương thức</dt>
                        <dd class="text-slate-100">{{ subscription.payment_method?.label ?? 'Chưa đặt' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Trạng thái</dt>
                        <dd class="text-slate-100">{{ statusLabel[subscription.status] ?? subscription.status }}</dd>
                    </div>
                    <div v-if="subscription.is_trial">
                        <dt class="text-slate-500">Dùng thử</dt>
                        <dd class="text-slate-100">Có</dd>
                    </div>
                </dl>

                <div v-if="subscription.events?.length" id="history" class="rounded-2xl border border-white/5 bg-midnight-900 p-6 shadow-lg shadow-black/20">
                    <h3 class="mb-3 text-sm font-semibold text-slate-300">Lịch sử</h3>
                    <ul class="space-y-2 text-sm">
                        <li v-for="event in subscription.events" :key="event.id" class="flex justify-between text-slate-400">
                            <span>{{ eventLabel[event.kind] ?? event.kind }}</span>
                            <span>
                                <template v-if="event.amount">{{ event.amount }} {{ event.currency }} · </template>
                                {{ event.occurred_at?.slice(0, 10) }}
                            </span>
                        </li>
                    </ul>
                </div>

                <div class="flex flex-col gap-2">
                    <button
                        v-if="subscription.status !== 'cancelled'"
                        type="button"
                        class="rounded-lg bg-violet-600 px-4 py-2 text-white hover:bg-violet-500 disabled:opacity-50"
                        :disabled="cancelForm.processing"
                        @click="markCancelled"
                    >
                        Đánh dấu đã hủy
                    </button>
                    <button
                        type="button"
                        class="text-center text-sm text-red-400 hover:text-red-300 hover:underline"
                        :disabled="deleteForm.processing"
                        @click="destroy"
                    >
                        Xóa dịch vụ
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Run the relevant PHPUnit test to confirm the props line up**

Run: `php artisan test --filter=SubscriptionShowTest`
Expected: PASS (this test already exists from Task 6 and checked the prop shape server-side; this step re-confirms nothing regressed)

- [ ] **Step 3: Build and manually verify in the browser**

Run: `npm run build` (or `npm run dev`). Visit `/subscriptions/{id}` for an existing subscription (or click a row from `/dashboard`/`/subscriptions`). Confirm: icon+name+price header, stat grid, history list (if any events), "Đánh dấu đã hủy" button works (redirects back, status updates, a `cancelled` event appears), "Xóa dịch vụ" works with confirmation.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Subscriptions/Show.vue
git commit -m "Update: add subscription detail page with history and actions

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 17: `Subscriptions/Create.vue` + `Edit.vue` — new fields, notifications banner, quick-add payment method

**Files:**
- Modify: `resources/js/Pages/Subscriptions/Create.vue`
- Modify: `resources/js/Pages/Subscriptions/Edit.vue`

**Interfaces:**
- Consumes: prop `paymentMethods` (Task 6), route `payment-methods.store` (Task 7), route `verification.send` (existing Breeze route), route `profile.edit` (existing).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Update `Create.vue`**

Replace the full contents of `resources/js/Pages/Subscriptions/Create.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';

const props = defineProps({ currencies: Array, paymentMethods: Array });

const paymentMethodOptions = ref([...props.paymentMethods]);
const newPaymentMethodLabel = ref('');
const addingPaymentMethod = ref(false);

const form = useForm({
    name: '',
    amount: '',
    currency: 'VND',
    billing_cycle: 'monthly',
    next_renewal_date: '',
    status: 'active',
    cancel_url: '',
    notes: '',
    list: 'personal',
    category: '',
    payment_method_id: '',
    is_trial: false,
    started_at: '',
});

async function addPaymentMethod() {
    if (!newPaymentMethodLabel.value.trim()) return;
    addingPaymentMethod.value = true;
    try {
        const { data } = await axios.post(route('payment-methods.store'), {
            label: newPaymentMethodLabel.value.trim(),
        });
        paymentMethodOptions.value.push(data);
        form.payment_method_id = data.id;
        newPaymentMethodLabel.value = '';
    } finally {
        addingPaymentMethod.value = false;
    }
}

function submit() {
    form.post('/subscriptions');
}
</script>

<template>
    <Head title="Thêm dịch vụ" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-extrabold tracking-tight text-white">Thêm dịch vụ</h2>
                <Link
                    :href="route('subscriptions.index')"
                    class="text-sm text-violet-400 hover:text-violet-300 hover:underline"
                >
                    Về danh sách
                </Link>
            </div>
        </template>

        <div class="py-8">
            <form
                class="mx-auto max-w-lg space-y-3 rounded-2xl border border-white/5 bg-midnight-900 p-6 shadow-lg shadow-black/20"
                @submit.prevent="submit"
            >
                <input v-model="form.name" placeholder="Tên" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500" />
                <input v-model="form.amount" type="number" step="0.01" placeholder="Số tiền" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500" />
                <select v-model="form.currency" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                    <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
                </select>
                <select v-model="form.billing_cycle" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                    <option value="monthly">Hàng tháng</option>
                    <option value="yearly">Hàng năm</option>
                </select>
                <input v-model="form.next_renewal_date" type="date" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                <select v-model="form.status" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                    <option value="active">Đang hoạt động</option>
                    <option value="pending_cancel">Sắp hủy</option>
                    <option value="cancelled">Đã hủy</option>
                </select>

                <select v-model="form.list" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                    <option value="personal">Cá nhân</option>
                    <option value="business">Công việc</option>
                    <option value="family">Gia đình</option>
                </select>

                <input
                    v-model="form.category"
                    list="category-options"
                    placeholder="Danh mục (vd: Streaming)"
                    class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500"
                />
                <datalist id="category-options">
                    <option value="Streaming" />
                    <option value="Productivity" />
                    <option value="Utilities" />
                    <option value="Finance" />
                    <option value="Health" />
                    <option value="Education" />
                    <option value="Other" />
                </datalist>

                <div class="flex gap-2">
                    <select v-model="form.payment_method_id" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                        <option value="">Chưa đặt phương thức</option>
                        <option v-for="pm in paymentMethodOptions" :key="pm.id" :value="pm.id">{{ pm.label }}</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <input
                        v-model="newPaymentMethodLabel"
                        placeholder="Thêm phương thức mới (vd: Visa •••• 1234)"
                        class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-sm text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500"
                    />
                    <button
                        type="button"
                        class="shrink-0 rounded-lg bg-midnight-800 px-3 text-sm text-slate-200 hover:bg-midnight-700 disabled:opacity-50"
                        :disabled="addingPaymentMethod"
                        @click="addPaymentMethod"
                    >
                        + Thêm
                    </button>
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" v-model="form.is_trial" class="rounded border-white/20 bg-midnight-800 text-violet-500 focus:ring-violet-500" />
                    Đang dùng thử miễn phí
                </label>

                <div>
                    <label class="text-sm text-slate-500">Bắt đầu từ (tùy chọn)</label>
                    <input v-model="form.started_at" type="date" class="mt-1 w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                </div>

                <input v-model="form.cancel_url" placeholder="Link hủy (tùy chọn)" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500" />
                <textarea v-model="form.notes" placeholder="Ghi chú" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500"></textarea>
                <button type="submit" class="rounded-lg bg-violet-600 px-4 py-2 text-white hover:bg-violet-500">Lưu</button>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Update `Edit.vue`**

Replace the full contents of `resources/js/Pages/Subscriptions/Edit.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';

const props = defineProps({ subscription: Object, currencies: Array, paymentMethods: Array });

const paymentMethodOptions = ref([...props.paymentMethods]);
const newPaymentMethodLabel = ref('');
const addingPaymentMethod = ref(false);

const authUser = computed(() => usePage().props.auth.user);

const form = useForm({
    name: props.subscription.name,
    amount: props.subscription.amount,
    currency: props.subscription.currency,
    billing_cycle: props.subscription.billing_cycle,
    next_renewal_date: props.subscription.next_renewal_date?.slice(0, 10),
    status: props.subscription.status,
    cancel_url: props.subscription.cancel_url ?? '',
    notes: props.subscription.notes ?? '',
    list: props.subscription.list,
    category: props.subscription.category ?? '',
    payment_method_id: props.subscription.payment_method_id ?? '',
    is_trial: props.subscription.is_trial,
    started_at: props.subscription.started_at?.slice(0, 10) ?? '',
});

async function addPaymentMethod() {
    if (!newPaymentMethodLabel.value.trim()) return;
    addingPaymentMethod.value = true;
    try {
        const { data } = await axios.post(route('payment-methods.store'), {
            label: newPaymentMethodLabel.value.trim(),
        });
        paymentMethodOptions.value.push(data);
        form.payment_method_id = data.id;
        newPaymentMethodLabel.value = '';
    } finally {
        addingPaymentMethod.value = false;
    }
}

function submit() {
    form.put(`/subscriptions/${props.subscription.id}`);
}
</script>

<template>
    <Head title="Sửa dịch vụ" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-extrabold tracking-tight text-white">Sửa dịch vụ</h2>
                <Link
                    :href="route('subscriptions.index')"
                    class="text-sm text-violet-400 hover:text-violet-300 hover:underline"
                >
                    Về danh sách
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-lg space-y-4 px-4 sm:px-0">
                <div v-if="!authUser.email_verified_at" class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-300">
                    Xác thực email để nhận nhắc nhở gia hạn ·
                    <Link :href="route('verification.send')" method="post" as="button" class="underline">Gửi lại email</Link>
                </div>
                <div v-else-if="!authUser.renewal_reminders_enabled" class="rounded-lg border border-white/10 bg-midnight-800 p-3 text-sm text-slate-400">
                    Nhắc gia hạn qua email đang tắt ·
                    <Link :href="route('profile.edit')" class="text-violet-400 underline">Bật trong Cài đặt</Link>
                </div>

                <form
                    class="space-y-3 rounded-2xl border border-white/5 bg-midnight-900 p-6 shadow-lg shadow-black/20"
                    @submit.prevent="submit"
                >
                    <input v-model="form.name" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                    <input v-model="form.amount" type="number" step="0.01" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                    <select v-model="form.currency" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                        <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
                    </select>
                    <select v-model="form.billing_cycle" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                        <option value="monthly">Hàng tháng</option>
                        <option value="yearly">Hàng năm</option>
                    </select>
                    <input v-model="form.next_renewal_date" type="date" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                    <select v-model="form.status" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                        <option value="active">Đang hoạt động</option>
                        <option value="pending_cancel">Sắp hủy</option>
                        <option value="cancelled">Đã hủy</option>
                    </select>

                    <select v-model="form.list" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                        <option value="personal">Cá nhân</option>
                        <option value="business">Công việc</option>
                        <option value="family">Gia đình</option>
                    </select>

                    <input
                        v-model="form.category"
                        list="category-options"
                        placeholder="Danh mục (vd: Streaming)"
                        class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500"
                    />
                    <datalist id="category-options">
                        <option value="Streaming" />
                        <option value="Productivity" />
                        <option value="Utilities" />
                        <option value="Finance" />
                        <option value="Health" />
                        <option value="Education" />
                        <option value="Other" />
                    </datalist>

                    <select v-model="form.payment_method_id" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                        <option value="">Chưa đặt phương thức</option>
                        <option v-for="pm in paymentMethodOptions" :key="pm.id" :value="pm.id">{{ pm.label }}</option>
                    </select>
                    <div class="flex gap-2">
                        <input
                            v-model="newPaymentMethodLabel"
                            placeholder="Thêm phương thức mới"
                            class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-sm text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500"
                        />
                        <button
                            type="button"
                            class="shrink-0 rounded-lg bg-midnight-800 px-3 text-sm text-slate-200 hover:bg-midnight-700 disabled:opacity-50"
                            :disabled="addingPaymentMethod"
                            @click="addPaymentMethod"
                        >
                            + Thêm
                        </button>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-300">
                        <input type="checkbox" v-model="form.is_trial" class="rounded border-white/20 bg-midnight-800 text-violet-500 focus:ring-violet-500" />
                        Đang dùng thử miễn phí
                    </label>

                    <div>
                        <label class="text-sm text-slate-500">Bắt đầu từ</label>
                        <input v-model="form.started_at" type="date" class="mt-1 w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                    </div>

                    <input v-model="form.cancel_url" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                    <textarea v-model="form.notes" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500"></textarea>

                    <Link
                        :href="`${route('subscriptions.show', subscription.id)}#history`"
                        class="block text-sm text-violet-400 hover:text-violet-300 hover:underline"
                    >
                        Xem lịch sử →
                    </Link>

                    <button type="submit" class="rounded-lg bg-violet-600 px-4 py-2 text-white hover:bg-violet-500">Cập nhật</button>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 3: Run the relevant PHPUnit tests to confirm nothing server-side regressed**

Run: `php artisan test --filter=Subscription`
Expected: PASS

- [ ] **Step 4: Build and manually verify in the browser**

Run: `npm run build` (or `npm run dev`). Visit `/subscriptions/create`: fill the form, use "+ Thêm" to quick-add a payment method and confirm it appears selected immediately without a page reload, submit, confirm the new subscription has the chosen `list`/`category`/`payment_method`. Then visit `/subscriptions/{id}/edit` for an existing one and confirm fields are pre-filled and the notifications banner shows correctly for an unverified vs. verified account.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Subscriptions/Create.vue resources/js/Pages/Subscriptions/Edit.vue
git commit -m "Update: add list/category/payment method/trial fields to the form

Edit page also shows a reminders-status banner and a link to the new
history section on the detail page.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 18: `BottomTabBar.vue` + wire into `AuthenticatedLayout.vue`

**Files:**
- Create: `resources/js/Components/BottomTabBar.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

**Interfaces:**
- Consumes: routes `dashboard`, `calendar.index` (Task 10), `profile.edit`.
- Produces: nothing consumed by later tasks besides visual layout.

- [ ] **Step 1: Write `BottomTabBar.vue`**

```vue
<script setup>
import { Link } from '@inertiajs/vue3';

const tabs = [
    { href: () => route('dashboard'), active: () => route().current('dashboard'), label: 'Subscriptions', icon: 'M4 6h16M4 12h16M4 18h7' },
    { href: () => route('calendar.index'), active: () => route().current('calendar.index'), label: 'Calendar', icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z' },
    { href: () => route('profile.edit'), active: () => route().current('profile.edit'), label: 'Cài đặt', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z' },
];
</script>

<template>
    <nav
        class="fixed inset-x-4 bottom-4 z-40 flex justify-around rounded-full border border-white/10 bg-midnight-900/95 px-2 py-2 shadow-2xl backdrop-blur sm:hidden"
        style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom))"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.label"
            :href="tab.href()"
            class="flex flex-1 flex-col items-center gap-0.5 rounded-full px-2 py-1.5 text-xs"
            :class="tab.active() ? 'text-white' : 'text-slate-500'"
        >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="tab.icon" />
            </svg>
            {{ tab.label }}
        </Link>
    </nav>
</template>
```

- [ ] **Step 2: Wire it into `AuthenticatedLayout.vue`**

In `resources/js/Layouts/AuthenticatedLayout.vue`:
- Add the import: `import BottomTabBar from '@/Components/BottomTabBar.vue';`
- Add a "Calendar" link next to the existing "Dashboard" `<NavLink>` in the desktop nav (both the `sm:flex` block and the mobile `ResponsiveNavLink` block, so the existing hamburger menu keeps working as a fallback on very old browsers without `env()` support):

```vue
                                <NavLink
                                    :href="route('calendar.index')"
                                    :active="route().current('calendar.index')"
                                >
                                    Calendar
                                </NavLink>
```

(place this right after the existing `Dashboard` `NavLink` in the `sm:flex` nav block, and add the matching `ResponsiveNavLink` right after the existing `Dashboard` one in the mobile dropdown block.)

- Render `<BottomTabBar />` right before the closing `</div>` of the outermost `min-h-screen` wrapper, and add bottom padding to `<main>` so content isn't hidden behind it on mobile:

```vue
            <!-- Page Content -->
            <main class="pb-24 sm:pb-0">
                <slot />
            </main>
        </div>

        <BottomTabBar />
    </div>
</template>
```

- [ ] **Step 3: Build and manually verify in the browser**

Run: `npm run build` (or `npm run dev`). At a desktop width, confirm the top nav shows "Dashboard" and "Calendar" and no bottom bar appears. Resize to a mobile width (e.g. 375px) and confirm the floating bottom tab bar appears with 3 tabs, the active one highlighted, and content isn't clipped behind it.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/BottomTabBar.vue resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "Update: add mobile bottom tab bar, Calendar link on desktop nav

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 19: `Pages/Calendar/Index.vue`

**Files:**
- Create: `resources/js/Pages/Calendar/Index.vue`

**Interfaces:**
- Consumes: prop `subscriptions` from `CalendarController@index` (Task 10); `projectOccurrences`/`groupByDate`/`monthTotals` from `orbit/calendar.js` (Task 13); `BrandIcon.vue` (Task 11).
- Produces: nothing consumed by later tasks — leaf page.

- [ ] **Step 1: Write the page**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrandIcon from '@/orbit/BrandIcon.vue';
import { formatVnd } from '@/orbit/labels.js';
import { projectOccurrences, groupByDate, monthTotals } from '@/orbit/calendar.js';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ subscriptions: { type: Array, default: () => [] } });

const today = new Date().toISOString().slice(0, 10);
const now = new Date();
const viewedYear = ref(now.getFullYear());
const viewedMonth = ref(now.getMonth()); // 0-indexed

const monthNames = [
    'Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6',
    'Tháng 7', 'Tháng 8', 'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12',
];

const occurrences = computed(() => projectOccurrences(props.subscriptions, viewedYear.value, viewedMonth.value));
const grouped = computed(() => groupByDate(occurrences.value));
const totals = computed(() => monthTotals(occurrences.value, today));

const weeks = computed(() => {
    const firstOfMonth = new Date(Date.UTC(viewedYear.value, viewedMonth.value, 1));
    const daysInMonth = new Date(Date.UTC(viewedYear.value, viewedMonth.value + 1, 0)).getUTCDate();
    // Monday-first weekday index (0 = Monday .. 6 = Sunday).
    const leadingBlanks = (firstOfMonth.getUTCDay() + 6) % 7;

    const cells = [];
    for (let i = 0; i < leadingBlanks; i++) cells.push(null);
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = new Date(Date.UTC(viewedYear.value, viewedMonth.value, day)).toISOString().slice(0, 10);
        cells.push({ day, dateStr, occurrences: grouped.value[dateStr] ?? [] });
    }
    while (cells.length % 7 !== 0) cells.push(null);

    const rows = [];
    for (let i = 0; i < cells.length; i += 7) rows.push(cells.slice(i, i + 7));
    return rows;
});

const selectedDate = ref(null);
const selectedOccurrences = computed(() => selectedDate.value ? (grouped.value[selectedDate.value] ?? []) : []);

function goToMonth(delta) {
    let month = viewedMonth.value + delta;
    let year = viewedYear.value;
    if (month < 0) { month = 11; year -= 1; }
    if (month > 11) { month = 0; year += 1; }
    viewedMonth.value = month;
    viewedYear.value = year;
    selectedDate.value = null;
}

function goToToday() {
    viewedYear.value = now.getFullYear();
    viewedMonth.value = now.getMonth();
    selectedDate.value = null;
}
</script>

<template>
    <Head title="Lịch gia hạn" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-2xl font-extrabold tracking-tight text-white">Lịch gia hạn</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xl font-extrabold text-white">{{ monthNames[viewedMonth] }}/{{ viewedYear }}</p>
                        <p class="text-sm text-slate-400">
                            Tổng: {{ formatVnd(totals.total) }} ₫ · Sắp tới: {{ formatVnd(totals.upcoming) }} ₫
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded-lg bg-midnight-800 px-3 py-1.5 text-slate-300 hover:bg-midnight-700" @click="goToMonth(-1)">‹</button>
                        <button type="button" class="rounded-lg bg-midnight-800 px-3 py-1.5 text-sm text-slate-300 hover:bg-midnight-700" @click="goToToday">Hôm nay</button>
                        <button type="button" class="rounded-lg bg-midnight-800 px-3 py-1.5 text-slate-300 hover:bg-midnight-700" @click="goToMonth(1)">›</button>
                    </div>
                </div>

                <div class="overflow-hidden rounded-2xl border border-white/5 bg-midnight-900 shadow-lg shadow-black/20">
                    <div class="grid grid-cols-7 border-b border-white/5 text-center text-xs font-semibold text-slate-500">
                        <div v-for="d in ['T2','T3','T4','T5','T6','T7','CN']" :key="d" class="py-2">{{ d }}</div>
                    </div>
                    <div v-for="(week, wi) in weeks" :key="wi" class="grid grid-cols-7">
                        <button
                            v-for="(cell, ci) in week"
                            :key="ci"
                            type="button"
                            class="flex h-14 flex-col items-center justify-center gap-0.5 border-b border-r border-white/5 text-sm"
                            :class="[
                                !cell && 'bg-midnight-950/40',
                                cell?.dateStr === today && 'bg-violet-500/10',
                                cell?.dateStr === selectedDate && 'ring-2 ring-inset ring-violet-500',
                            ]"
                            :disabled="!cell || cell.occurrences.length === 0"
                            @click="selectedDate = cell.dateStr"
                        >
                            <template v-if="cell">
                                <span class="text-slate-300">{{ cell.day }}</span>
                                <span v-if="cell.occurrences.length" class="flex gap-0.5">
                                    <span
                                        v-for="occ in cell.occurrences.slice(0, 3)"
                                        :key="occ.subscription.id"
                                        class="h-1.5 w-1.5 rounded-full bg-violet-400"
                                    />
                                </span>
                            </template>
                        </button>
                    </div>
                </div>

                <div v-if="selectedOccurrences.length" class="space-y-2">
                    <p class="text-sm text-slate-500">{{ selectedDate }}</p>
                    <Link
                        v-for="occ in selectedOccurrences"
                        :key="occ.subscription.id"
                        :href="route('subscriptions.show', occ.subscription.id)"
                        class="flex items-center gap-3 rounded-2xl border border-white/5 bg-midnight-900 p-3 shadow-lg shadow-black/10"
                    >
                        <BrandIcon :name="occ.subscription.name" :size="32" />
                        <span class="flex-1 text-slate-100">{{ occ.subscription.name }}</span>
                        <span class="text-slate-400">{{ formatVnd(occ.subscription.amount_vnd) }} ₫</span>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Build and manually verify in the browser**

Run: `npm run build` (or `npm run dev`). Visit `/calendar`. Confirm: current month renders with dots on renewal days, "Tổng"/"Sắp tới" figures look right (compare against known seeded subscriptions), prev/next/"Hôm nay" navigation works, clicking a day with occurrences shows the list below and clicking an item navigates to its detail page.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Calendar/Index.vue
git commit -m "Update: add Calendar page with month grid and renewal markers

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 20: `Dashboard.vue` — + button, stats row, list filter, sort toggle

**Files:**
- Modify: `resources/js/Pages/Dashboard.vue`
- Modify: `app/Http/Controllers/DashboardController.php` (select the new `list` column)
- Modify: `tests/Feature/DashboardTest.php` (extend the field-shape test)

**Interfaces:**
- Consumes: `annualizedVnd` from `orbit/layout.js` (Task 12).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

Extend the existing `test_each_subscription_prop_has_the_fields_the_orbit_needs` test in `tests/Feature/DashboardTest.php` — add `'list'` to the `hasAll([...])` array:

```php
                ->has('subscriptions.0', fn (Assert $sub) => $sub
                    ->hasAll(['id', 'name', 'amount', 'currency', 'amount_vnd', 'billing_cycle', 'next_renewal_date', 'status', 'list'])
                )
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL — `list` missing from the prop.

- [ ] **Step 3: Update `DashboardController`**

In `app/Http/Controllers/DashboardController.php`, add `'list'` to the `get([...])` column array:

```php
            ->get([
                'id', 'name', 'amount', 'currency', 'amount_vnd',
                'billing_cycle', 'next_renewal_date', 'status', 'list',
            ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS

- [ ] **Step 5: Update `Dashboard.vue`**

In `resources/js/Pages/Dashboard.vue`:
- Add imports: `import { annualizedVnd } from '@/orbit/layout.js';` and `import { formatVnd } from '@/orbit/labels.js';`. `computed` and `ref` from `'vue'` are already imported in this file — no change needed there.
- Add local state and computed values (near the top of `<script setup>`, after the existing `scanning`/`scanError` refs):

```js
const listFilter = ref('all');
const sortMode = ref('active');

const filteredSubscriptions = computed(() => {
    let list = props.subscriptions;
    if (listFilter.value !== 'all') {
        list = list.filter((s) => s.list === listFilter.value);
    }
    list = [...list];
    if (sortMode.value === 'next') {
        list.sort((a, b) => a.next_renewal_date.localeCompare(b.next_renewal_date));
    } else {
        const order = { active: 0, pending_cancel: 1, cancelled: 2 };
        list.sort((a, b) => (order[a.status] ?? 3) - (order[b.status] ?? 3));
    }
    return list;
});

const totalYearlyVnd = computed(() =>
    filteredSubscriptions.value.reduce((sum, s) => sum + annualizedVnd(s), 0),
);
```

- Add `ref` to the `vue` import if not already present (it already is, per the existing file).
- Replace the orbit-card block and the `SubscriptionList` usage in the `<template>` (the `v-else` branch of the empty-state check) with:

```vue
                <template v-else>
                    <div class="relative rounded-2xl border border-white/5 bg-gradient-to-b from-midnight-800 to-midnight-950 p-4 shadow-lg shadow-black/30">
                        <Link
                            :href="route('subscriptions.create')"
                            class="absolute right-4 top-4 z-10 flex h-10 w-10 items-center justify-center rounded-full bg-violet-600 text-white shadow-lg hover:bg-violet-500"
                            aria-label="Thêm dịch vụ"
                        >
                            +
                        </Link>
                        <Orbit :subscriptions="subscriptions" :user="user" />
                    </div>

                    <div class="mt-6 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-extrabold text-white">{{ filteredSubscriptions.length }}</span>
                            <select
                                v-model="listFilter"
                                class="rounded-lg border border-white/10 bg-midnight-800 px-2 py-1 text-sm text-slate-300 focus:border-violet-500 focus:ring-violet-500"
                            >
                                <option value="all">Tất cả</option>
                                <option value="personal">Cá nhân</option>
                                <option value="business">Công việc</option>
                                <option value="family">Gia đình</option>
                            </select>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold text-white">{{ formatVnd(totalYearlyVnd) }} ₫</p>
                            <p class="text-xs text-slate-500">Tổng chi phí/năm</p>
                        </div>
                    </div>

                    <div class="mt-4 flex gap-2 text-sm">
                        <button
                            type="button"
                            class="rounded-full px-3 py-1"
                            :class="sortMode === 'active' ? 'bg-violet-600 text-white' : 'bg-midnight-800 text-slate-400'"
                            @click="sortMode = 'active'"
                        >
                            Active
                        </button>
                        <button
                            type="button"
                            class="rounded-full px-3 py-1"
                            :class="sortMode === 'next' ? 'bg-violet-600 text-white' : 'bg-midnight-800 text-slate-400'"
                            @click="sortMode = 'next'"
                        >
                            Next
                        </button>
                    </div>

                    <SubscriptionList :subscriptions="filteredSubscriptions" class="mt-4" />
                </template>
```

- [ ] **Step 6: Run the full Dashboard test file and the full Vitest suite**

Run: `php artisan test --filter=DashboardTest && npm run test:js`
Expected: PASS

- [ ] **Step 7: Build and manually verify in the browser**

Run: `npm run build` (or `npm run dev`). Visit `/dashboard`: confirm the "+" button navigates to the create page, the list filter changes both the count and the subscriptions shown below, the yearly total updates when filtering, and the Active/Next toggle reorders the list.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/DashboardController.php resources/js/Pages/Dashboard.vue \
        tests/Feature/DashboardTest.php
git commit -m "Update: dashboard gets a + button, list filter, and sort toggle

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 21: `NotificationPreferencesForm.vue` + wire into `Profile/Edit.vue`

**Files:**
- Create: `resources/js/Pages/Profile/Partials/NotificationPreferencesForm.vue`
- Modify: `resources/js/Pages/Profile/Edit.vue`

**Interfaces:**
- Consumes: route `profile.notifications.update` (Task 9); reads `usePage().props.auth.user.renewal_reminders_enabled` / `.reminder_days_before`.
- Produces: nothing consumed by later tasks — final task in the plan.

- [ ] **Step 1: Write the partial**

```vue
<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Transition } from 'vue';

const user = usePage().props.auth.user;

const form = useForm({
    renewal_reminders_enabled: user.renewal_reminders_enabled,
    reminder_days_before: user.reminder_days_before,
});

function submit() {
    form.patch(route('profile.notifications.update'), { preserveScroll: true });
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-semibold text-white">Nhắc nhở gia hạn</h2>
            <p class="mt-1 text-sm text-slate-400">
                Nhận email nhắc trước khi một dịch vụ sắp gia hạn.
            </p>
        </header>

        <form @submit.prevent="submit" class="mt-6 space-y-6">
            <label class="flex items-center gap-2">
                <input
                    type="checkbox"
                    v-model="form.renewal_reminders_enabled"
                    class="rounded border-white/20 bg-midnight-800 text-violet-500 focus:ring-violet-500"
                />
                <span class="text-sm text-slate-300">Bật nhắc nhở qua email</span>
            </label>

            <div>
                <InputLabel for="reminder_days_before" value="Nhắc trước (số ngày)" />
                <select
                    id="reminder_days_before"
                    v-model.number="form.reminder_days_before"
                    class="mt-1 rounded-lg border-white/10 bg-midnight-800 text-slate-100 focus:border-violet-500 focus:ring-violet-500"
                >
                    <option :value="1">1 ngày</option>
                    <option :value="3">3 ngày</option>
                    <option :value="7">7 ngày</option>
                </select>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Lưu</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-slate-500">Đã lưu.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
```

- [ ] **Step 2: Wire it into `Profile/Edit.vue`**

In `resources/js/Pages/Profile/Edit.vue`, add the import `import NotificationPreferencesForm from './Partials/NotificationPreferencesForm.vue';` and add a fourth card, right after the `UpdateProfileInformationForm` card and before `UpdatePasswordForm`:

```vue
                <div
                    class="rounded-2xl border border-white/5 bg-midnight-900 p-4 shadow-lg shadow-black/20 sm:p-8"
                >
                    <NotificationPreferencesForm class="max-w-xl" />
                </div>
```

- [ ] **Step 3: Run the relevant PHPUnit test**

Run: `php artisan test --filter=ProfileTest`
Expected: PASS (already covered by Task 9's tests; this step confirms nothing regressed)

- [ ] **Step 4: Build and manually verify in the browser**

Run: `npm run build` (or `npm run dev`). Visit `/profile`, confirm the new "Nhắc nhở gia hạn" card appears, toggling the checkbox and changing the day select then clicking "Lưu" persists (reload the page and confirm the values stuck).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Profile/Partials/NotificationPreferencesForm.vue \
        resources/js/Pages/Profile/Edit.vue
git commit -m "Update: add renewal reminder preferences to the profile page

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 22: Full-suite verification

**Files:** none (verification-only task).

**Interfaces:** none.

- [ ] **Step 1: Run the full PHPUnit suite**

Run: `php artisan test`
Expected: PASS, including every test file touched in Tasks 1–21 plus the pre-existing suite (Auth, Gmail, etc.).

- [ ] **Step 2: Run the full Vitest suite**

Run: `npm run test:js`
Expected: PASS — the pre-existing 33 tests plus every new test added in Tasks 11, 12, 13.

- [ ] **Step 3: Build production assets**

Run: `npm run build`
Expected: succeeds with no errors (if the `public/build` ownership issue from the earlier session recurs, ask the user to run `sudo chown -R $USER public/build` first).

- [ ] **Step 4: Manual smoke test in the browser**

Log in, walk through: Dashboard (+ button, filter, sort, orbit click → detail page) → Subscriptions list (icon rows) → create a subscription with all new fields → edit it, change the amount (confirm a `price_changed` event appears in its history) → mark it cancelled from the detail page (confirm a `cancelled` event appears) → Calendar page (month navigation, day click) → Profile (reminders card saves) → resize to mobile width and confirm the bottom tab bar appears and top nav/hamburger doesn't.

- [ ] **Step 5: Run `subscriptions:send-renewal-reminders` manually and check Mailpit**

```bash
php artisan tinker --execute='\App\Models\Subscription::first()->update(["next_renewal_date" => now()->addDays(3)->toDateString()]);'
php artisan subscriptions:send-renewal-reminders
```

Then open Mailpit's web UI (port 8025, per the running Spin stack) and confirm the reminder email arrived with the correct subscription name/amount/date.

- [ ] **Step 6: Final commit (only if any of the above steps required a fix)**

```bash
git add -A
git commit -m "Fix: address issues found during SP4 full-suite verification

Co-Authored-By: Claude <noreply@anthropic.com>"
```
