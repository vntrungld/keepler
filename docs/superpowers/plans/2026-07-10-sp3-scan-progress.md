# Real-time Gmail Scan Progress Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the synchronous Gmail scan with a queued background job + polling so the dashboard shows a real percentage bar ("Đang xử lý 12/50 email — 24%").

**Architecture:** A `gmail_scans` row (per user) tracks status/total/processed/candidates. `POST /gmail/scans` creates the row and dispatches `ScanGmailJob`, which runs `SubscriptionScanner` with a progress callback that updates the row. The front-end polls `GET /gmail/scans/{scan}` for progress and navigates to a results route (reusing `ScanResults.vue`) when done.

**Tech Stack:** Laravel 13 (database queue — already configured), queued Job, Inertia/Vue 3, PHPUnit (class style), SQLite.

## Global Constraints

- **Tests use PHPUnit class style** (`extends Tests\TestCase`), NOT Pest.
- Queue driver is **database** (`QUEUE_CONNECTION=database` already set; `jobs` table migration already exists — do NOT recreate it). The feature requires a running `php artisan queue:work`.
- `gmail_scans` columns: `user_id, status (pending|running|done|failed), total, processed, candidates (json nullable), error (text nullable)`. `user_id` NOT in `$fillable` (create via the `gmailScans()` relation).
- `SubscriptionScanner::scan` gains an OPTIONAL `?callable $onProgress = null` — existing callers/tests must keep working unchanged, and grouping/dedup behavior must be identical.
- `processed` counts every fetched message (increment even when a message matches no provider or is gated out); `total` = number of message ids from `listMessageIds`.
- All scan endpoints scoped to the authenticated user (`$request->user()->id === $scan->user_id`, else 404).
- The job must never write tokens or raw Gmail content into `error` — use a friendly generic message.
- The old synchronous `GET /gmail/scan` route + `GmailController::scan` are REMOVED and replaced; update the two old tests that hit it.
- Do NOT stage `app/Support/CLAUDE.md`.

---

## File Structure

**Create:**
- `database/migrations/2026_07_10_000003_create_gmail_scans_table.php`
- `app/Models/GmailScan.php`
- `app/Jobs/ScanGmailJob.php`
- `tests/Feature/Gmail/GmailScanModelTest.php`
- `tests/Feature/Gmail/ScanGmailJobTest.php`
- `tests/Feature/Gmail/GmailScanFlowTest.php`

**Modify:**
- `app/Models/User.php` — `gmailScans()` relation.
- `app/Support/Gmail/SubscriptionScanner.php` — optional progress callback.
- `app/Http/Controllers/GmailController.php` — replace `scan` with `storeScan`/`showScan`/`scanResults`.
- `routes/web.php` — swap the scan route for the three new ones.
- `tests/Feature/Gmail/GmailScanImportTest.php` — remove the two obsolete `GET /gmail/scan` tests (keep the import tests).
- `resources/js/Pages/Dashboard.vue` — POST + poll + percentage popup.

---

## Task 1: `gmail_scans` table + GmailScan model + User relation

**Files:**
- Create: `database/migrations/2026_07_10_000003_create_gmail_scans_table.php`
- Create: `app/Models/GmailScan.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Gmail/GmailScanModelTest.php`

**Interfaces:**
- Consumes: `User`.
- Produces:
  - `gmail_scans` table.
  - `GmailScan` model: `$fillable = ['status','total','processed','candidates','error']`; cast `candidates`→array; `user(): BelongsTo`; `progressPercent(): int` = `total>0 ? round(processed/total*100) : 0`.
  - `User::gmailScans(): HasMany`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Gmail/GmailScanModelTest.php`:

```php
<?php

namespace Tests\Feature\Gmail;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmailScanModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scan_is_created_through_the_user_relation(): void
    {
        $user = User::factory()->create();

        $scan = $user->gmailScans()->create(['status' => 'pending']);

        $this->assertSame('pending', $scan->status);
        $this->assertSame($user->id, $scan->user_id);
        $this->assertSame(0, $scan->total);
        $this->assertSame(0, $scan->processed);
    }

    public function test_candidates_are_cast_to_array(): void
    {
        $user = User::factory()->create();
        $scan = $user->gmailScans()->create([
            'status' => 'done',
            'candidates' => [['provider_key' => 'netflix', 'name' => 'Netflix']],
        ]);

        $this->assertIsArray($scan->fresh()->candidates);
        $this->assertSame('netflix', $scan->fresh()->candidates[0]['provider_key']);
    }

    public function test_progress_percent_computes_and_handles_zero_total(): void
    {
        $user = User::factory()->create();

        $zero = $user->gmailScans()->create(['status' => 'pending']);
        $this->assertSame(0, $zero->progressPercent());

        $half = $user->gmailScans()->create(['status' => 'running', 'total' => 50, 'processed' => 12]);
        $this->assertSame(24, $half->progressPercent());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GmailScanModelTest`
Expected: FAIL — table/model/relation missing.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_10_000003_create_gmail_scans_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->json('candidates')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_scans');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/GmailScan.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmailScan extends Model
{
    protected $fillable = [
        'status',
        'total',
        'processed',
        'candidates',
        'error',
    ];

    protected $casts = [
        'candidates' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progressPercent(): int
    {
        return $this->total > 0
            ? (int) round($this->processed / $this->total * 100)
            : 0;
    }
}
```

- [ ] **Step 5: Add the User relation**

In `app/Models/User.php`, add the import and the relation method (next to `subscriptions()`):

```php
    /**
     * @return HasMany<GmailScan, $this>
     */
    public function gmailScans(): HasMany
    {
        return $this->hasMany(GmailScan::class);
    }
```

(The `HasMany` import already exists in User.php from the `subscriptions()` relation.)

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=GmailScanModelTest`
Expected: PASS — 3 tests green.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_10_000003_create_gmail_scans_table.php app/Models/GmailScan.php app/Models/User.php tests/Feature/Gmail/GmailScanModelTest.php
git commit -m "Update: add gmail_scans table and GmailScan model

Track background Gmail scans per user (status/total/processed/
candidates/error) with a progressPercent helper and a user relation.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 2: SubscriptionScanner progress callback

**Files:**
- Modify: `app/Support/Gmail/SubscriptionScanner.php`
- Test: `tests/Feature/Gmail/SubscriptionScannerTest.php` (add one test)

**Interfaces:**
- Consumes: existing `GmailClient`, `ProviderMatcher`, `ReceiptParser`.
- Produces: `scan(User $user, ?callable $onProgress = null): array` — calls `$onProgress(0, $total)` after listing, then `$onProgress($processed, $total)` after each message (processed counts every fetched message). Same candidate output as before.

- [ ] **Step 1: Write the failing test**

Add this test method to `tests/Feature/Gmail/SubscriptionScannerTest.php` (keep the existing tests and the existing stubbed-client helper `scannerReturning`):

```php
    public function test_scan_reports_progress_for_every_message(): void
    {
        $user = User::factory()->create();
        $scanner = $this->scannerReturning([
            'm1' => ['from' => 'info@netflix.com', 'subject' => 'receipt', 'date' => '2026-07-01', 'body' => 'Charged $12.99 monthly.'],
            'm2' => ['from' => 'stranger@unknown.com', 'subject' => 'hello', 'date' => '2026-07-02', 'body' => 'no provider here'],
            'm3' => ['from' => 'no-reply@spotify.com', 'subject' => 'receipt', 'date' => '2026-07-03', 'body' => 'Charged $9.99 monthly.'],
        ]);

        $calls = [];
        $scanner->scan($user, function (int $processed, int $total) use (&$calls) {
            $calls[] = [$processed, $total];
        });

        // First call sets the total with zero processed; last call is all done.
        $this->assertSame([0, 3], $calls[0]);
        $this->assertSame([3, 3], end($calls));
        // Processed increments once per message even for the unmatched one.
        $this->assertSame([[0, 3], [1, 3], [2, 3], [3, 3]], $calls);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SubscriptionScannerTest`
Expected: FAIL — `scan()` takes only one argument / no progress calls.

- [ ] **Step 3: Update SubscriptionScanner::scan**

In `app/Support/Gmail/SubscriptionScanner.php`, replace the whole `scan` method with this (the grouping/dedup logic is unchanged; the only changes are the `$onProgress` parameter, the `$processed` counter that always increments, and restructuring the `continue`s into nested `if`s so the counter still runs):

```php
    /** @return array<int,array<string,mixed>> */
    public function scan(User $user, ?callable $onProgress = null): array
    {
        $ids = $this->client->listMessageIds($this->buildQuery(), self::MAX_MESSAGES);
        $total = count($ids);

        if ($onProgress) {
            $onProgress(0, $total);
        }

        // Parse each message into a raw candidate keyed by provider+cycle,
        // keeping only the latest email per group.
        $byGroup = [];
        $processed = 0;
        foreach ($ids as $id) {
            $msg = $this->client->getMessage($id);
            $key = ProviderMatcher::match($msg['from'], $msg['subject'], $msg['body']);

            if ($key !== null) {
                $provider = config("providers.$key");
                $parsed = ReceiptParser::parse($provider, $msg['subject'], $msg['body'], $msg['date']);

                // Drop non-receipt payment emails (marketing); keep cancellations.
                if (! ($parsed['intent'] === 'payment' && ! $parsed['is_receipt'])) {
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
            }

            $processed++;
            if ($onProgress) {
                $onProgress($processed, $total);
            }
        }

        return $this->applyDedup($user, array_values($byGroup));
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=SubscriptionScannerTest`
Expected: PASS — the new test plus all existing scanner tests (grouping/dedup behavior unchanged).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Gmail/SubscriptionScanner.php tests/Feature/Gmail/SubscriptionScannerTest.php
git commit -m "Update: add optional progress callback to SubscriptionScanner

scan() now accepts an optional onProgress(processed, total) callback,
invoked once with the total and after every fetched message (counting
unmatched/gated messages too). Grouping and dedup are unchanged; the
callback is optional so existing callers are unaffected.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 3: ScanGmailJob

**Files:**
- Create: `app/Jobs/ScanGmailJob.php`
- Test: `tests/Feature/Gmail/ScanGmailJobTest.php`

**Interfaces:**
- Consumes: `GmailScan` (Task 1), `SubscriptionScanner` (Task 2), `GmailClient`.
- Produces: `ScanGmailJob` (queued). `handle()` sets `running`, runs the scanner writing `processed`/`total` via the callback, then stores `candidates` + `done`; on any exception sets `failed` + a friendly `error`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Gmail/ScanGmailJobTest.php`:

```php
<?php

namespace Tests\Feature\Gmail;

use App\Jobs\ScanGmailJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScanGmailJobTest extends TestCase
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

    public function test_job_completes_a_scan_with_candidates_and_full_progress(): void
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

        $user = $this->connectedUser();
        $scan = $user->gmailScans()->create(['status' => 'pending']);

        (new ScanGmailJob($scan))->handle();

        $fresh = $scan->fresh();
        $this->assertSame('done', $fresh->status);
        $this->assertSame(1, $fresh->total);
        $this->assertSame(1, $fresh->processed);
        $this->assertCount(1, $fresh->candidates);
        $this->assertSame('netflix', $fresh->candidates[0]['provider_key']);
    }

    public function test_job_marks_scan_failed_without_leaking_details_on_api_error(): void
    {
        Http::fake(['gmail.googleapis.com/*' => Http::response(['error' => 'boom'], 401)]);

        $user = $this->connectedUser();
        $scan = $user->gmailScans()->create(['status' => 'pending']);

        (new ScanGmailJob($scan))->handle();

        $fresh = $scan->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->error);
        $this->assertStringNotContainsString('boom', $fresh->error);
        $this->assertStringNotContainsString('401', $fresh->error);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ScanGmailJobTest`
Expected: FAIL — `ScanGmailJob` class not found.

- [ ] **Step 3: Create the job**

Create `app/Jobs/ScanGmailJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\GmailScan;
use App\Support\Gmail\GmailClient;
use App\Support\Gmail\SubscriptionScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanGmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public GmailScan $scan)
    {
    }

    public function handle(): void
    {
        $scan = $this->scan;
        $user = $scan->user;

        $scan->update(['status' => 'running']);

        try {
            $scanner = new SubscriptionScanner(new GmailClient($user));

            $candidates = $scanner->scan($user, function (int $processed, int $total) use ($scan) {
                $scan->update(['processed' => $processed, 'total' => $total]);
            });

            $scan->update([
                'candidates' => $candidates,
                'status' => 'done',
            ]);
        } catch (\Throwable $e) {
            $scan->update([
                'status' => 'failed',
                'error' => 'Không đọc được Gmail. Vui lòng thử kết nối lại và quét lại.',
            ]);
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ScanGmailJobTest`
Expected: PASS — 2 tests green.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ScanGmailJob.php tests/Feature/Gmail/ScanGmailJobTest.php
git commit -m "Update: add ScanGmailJob to run Gmail scans in the background

Queued job that runs SubscriptionScanner, writing processed/total to
the gmail_scans row via the progress callback and storing candidates
on completion. Failures are caught and recorded as a friendly error
with no token or API detail leaked.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 4: Controller endpoints + routes

**Files:**
- Modify: `app/Http/Controllers/GmailController.php` (remove `scan`, add `storeScan`/`showScan`/`scanResults`)
- Modify: `routes/web.php`
- Modify: `tests/Feature/Gmail/GmailScanImportTest.php` (remove the two obsolete `GET /gmail/scan` tests)
- Test: `tests/Feature/Gmail/GmailScanFlowTest.php`

**Interfaces:**
- Consumes: `GmailScan` (Task 1), `ScanGmailJob` (Task 3), `SubscriptionScanner` no longer used directly here.
- Produces routes (all `auth`): `POST /gmail/scans` (`gmail.scans.store`) → JSON `{id}` or `409 {connect_url}`; `GET /gmail/scans/{scan}` (`gmail.scans.show`) → JSON `{status,processed,total,percent}`; `GET /gmail/scans/{scan}/results` (`gmail.scans.results`) → Inertia `Gmail/ScanResults`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Gmail/GmailScanFlowTest.php`:

```php
<?php

namespace Tests\Feature\Gmail;

use App\Jobs\ScanGmailJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GmailScanFlowTest extends TestCase
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

    public function test_store_creates_a_scan_and_dispatches_the_job(): void
    {
        Queue::fake();
        $user = $this->connectedUser();

        $response = $this->actingAs($user)->postJson('/gmail/scans');

        $response->assertOk()->assertJsonStructure(['id']);
        $this->assertDatabaseHas('gmail_scans', [
            'id' => $response->json('id'), 'user_id' => $user->id, 'status' => 'pending',
        ]);
        Queue::assertPushed(ScanGmailJob::class);
    }

    public function test_store_asks_to_connect_when_gmail_not_linked(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/gmail/scans')
            ->assertStatus(409)
            ->assertJsonStructure(['connect_url']);

        $this->assertDatabaseCount('gmail_scans', 0);
        Queue::assertNothingPushed();
    }

    public function test_show_returns_progress_and_is_user_scoped(): void
    {
        $user = $this->connectedUser();
        $scan = $user->gmailScans()->create(['status' => 'running', 'total' => 50, 'processed' => 12]);

        $this->actingAs($user)->getJson("/gmail/scans/{$scan->id}")
            ->assertOk()
            ->assertJson(['status' => 'running', 'processed' => 12, 'total' => 50, 'percent' => 24]);

        // Another user cannot read it.
        $this->actingAs(User::factory()->create())
            ->getJson("/gmail/scans/{$scan->id}")
            ->assertNotFound();
    }

    public function test_results_renders_candidates_when_done_and_redirects_otherwise(): void
    {
        $user = $this->connectedUser();

        $done = $user->gmailScans()->create([
            'status' => 'done',
            'candidates' => [['provider_key' => 'netflix', 'name' => 'Netflix', 'action' => 'create']],
        ]);
        $this->actingAs($user)->get("/gmail/scans/{$done->id}/results")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Gmail/ScanResults')
                ->has('candidates', 1)
                ->where('candidates.0.provider_key', 'netflix'));

        $running = $user->gmailScans()->create(['status' => 'running']);
        $this->actingAs($user)->get("/gmail/scans/{$running->id}/results")
            ->assertRedirect(route('dashboard'));

        // Cross-user results are not viewable.
        $this->actingAs(User::factory()->create())
            ->get("/gmail/scans/{$done->id}/results")
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GmailScanFlowTest`
Expected: FAIL — routes/methods missing.

- [ ] **Step 3: Replace the controller's scan method**

In `app/Http/Controllers/GmailController.php`:

Update the imports at the top — remove `use App\Support\Gmail\SubscriptionScanner;` and `use App\Support\Gmail\GmailClient;` if they are now unused (they were only used by the old `scan`), and add:

```php
use App\Jobs\ScanGmailJob;
use App\Models\GmailScan;
```

Keep `use Inertia\Inertia;`. Then DELETE the entire existing `scan()` method and add these three methods:

```php
    public function storeScan(Request $request)
    {
        $user = $request->user();

        if (! $user->hasGmailConnected()) {
            return response()->json(['connect_url' => route('gmail.connect')], 409);
        }

        $scan = $user->gmailScans()->create(['status' => 'pending']);
        ScanGmailJob::dispatch($scan);

        return response()->json(['id' => $scan->id]);
    }

    public function showScan(Request $request, GmailScan $scan)
    {
        abort_unless($scan->user_id === $request->user()->id, 404);

        return response()->json([
            'status' => $scan->status,
            'processed' => $scan->processed,
            'total' => $scan->total,
            'percent' => $scan->progressPercent(),
        ]);
    }

    public function scanResults(Request $request, GmailScan $scan)
    {
        abort_unless($scan->user_id === $request->user()->id, 404);

        if ($scan->status !== 'done') {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Gmail/ScanResults', [
            'candidates' => $scan->candidates ?? [],
        ]);
    }
```

- [ ] **Step 4: Update the routes**

In `routes/web.php`, inside the `auth` group, REMOVE the old scan route:

```php
    Route::get('/gmail/scan', [GmailController::class, 'scan'])->name('gmail.scan');
```

and add:

```php
    Route::post('/gmail/scans', [GmailController::class, 'storeScan'])->name('gmail.scans.store');
    Route::get('/gmail/scans/{scan}', [GmailController::class, 'showScan'])->name('gmail.scans.show');
    Route::get('/gmail/scans/{scan}/results', [GmailController::class, 'scanResults'])->name('gmail.scans.results');
```

(Keep `POST /gmail/import` and the connect/callback/disconnect routes as they are.)

- [ ] **Step 5: Remove the two obsolete scan tests**

In `tests/Feature/Gmail/GmailScanImportTest.php`, DELETE exactly these two methods (they test the removed synchronous `GET /gmail/scan`): `test_scan_redirects_to_connect_when_not_connected` and `test_scan_renders_candidates`. Keep every `import` test in that file. If removing them leaves an unused `use Illuminate\Support\Facades\Http;` or `use Inertia\Testing\AssertableInertia as Assert;` import, remove those too.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=GmailScanFlowTest`
Expected: PASS — 4 tests green.

Then the full suite (confirms the obsolete tests are gone and nothing else regressed):

Run: `php artisan test`
Expected: PASS — full suite green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/GmailController.php routes/web.php tests/Feature/Gmail/GmailScanFlowTest.php tests/Feature/Gmail/GmailScanImportTest.php
git commit -m "Update: replace synchronous scan with async scan endpoints

Swap GET /gmail/scan for POST /gmail/scans (creates a scan row +
dispatches ScanGmailJob), GET /gmail/scans/{scan} (JSON progress), and
GET /gmail/scans/{scan}/results (Inertia results from stored
candidates). All user-scoped. Remove the two obsolete sync-scan tests.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 5: Dashboard polling + percentage popup

**Files:**
- Modify: `resources/js/Pages/Dashboard.vue`
- Test: build-verified (no component test harness in scope)

**Interfaces:**
- Consumes: `gmail.scans.store` (POST → `{id}` / 409 `{connect_url}`), `gmail.scans.show` (`{status,processed,total,percent}`), `gmail.scans.results`.
- Produces: the percentage popup + polling behavior.

- [ ] **Step 1: Rewrite the scan trigger + popup in Dashboard.vue**

Replace the ENTIRE `<script setup>` block of `resources/js/Pages/Dashboard.vue` with:

```javascript
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Orbit from '@/orbit/Orbit.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import axios from 'axios';

defineProps({
    subscriptions: { type: Array, default: () => [] },
    gmail_connected: { type: Boolean, default: false },
});

const user = computed(() => usePage().props.auth.user);

// Background scan + polling state.
const scanning = ref(false);
const scanError = ref(null);
const processed = ref(0);
const total = ref(0);
const percent = ref(0);
let pollTimer = null;

function resetScanState() {
    scanError.value = null;
    processed.value = 0;
    total.value = 0;
    percent.value = 0;
}

async function startScan() {
    scanning.value = true;
    resetScanState();

    try {
        const { data } = await axios.post(route('gmail.scans.store'));
        pollScan(data.id);
    } catch (e) {
        if (e.response?.status === 409 && e.response.data?.connect_url) {
            window.location.href = e.response.data.connect_url;
            return;
        }
        scanError.value = 'Không bắt đầu quét được. Vui lòng thử lại.';
    }
}

function pollScan(id) {
    pollTimer = setInterval(async () => {
        try {
            const { data } = await axios.get(route('gmail.scans.show', id));
            processed.value = data.processed;
            total.value = data.total;
            percent.value = data.percent;

            if (data.status === 'done') {
                stopPolling();
                router.visit(route('gmail.scans.results', id));
            } else if (data.status === 'failed') {
                stopPolling();
                scanError.value =
                    'Quét Gmail thất bại. Vui lòng thử kết nối lại và quét lại.';
            }
        } catch (e) {
            stopPolling();
            scanError.value = 'Mất kết nối khi theo dõi tiến trình.';
        }
    }, 1000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function closeScan() {
    stopPolling();
    scanning.value = false;
}

onBeforeUnmount(stopPolling);
```

- [ ] **Step 2: Update the scan button + popup markup**

In `resources/js/Pages/Dashboard.vue`, the scan button already calls `startScan` (from the previous popup work) — leave it. Replace the existing progress `<Teleport>` overlay block (the one containing "Đang quét Gmail…") with this percentage version:

```vue
        <Teleport to="body">
            <div
                v-if="scanning"
                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm"
            >
                <div
                    class="mx-4 flex w-full max-w-sm flex-col gap-4 rounded-xl bg-white p-8 shadow-2xl"
                >
                    <template v-if="!scanError">
                        <div class="flex items-center gap-3">
                            <span
                                class="h-6 w-6 shrink-0 animate-spin rounded-full border-4 border-emerald-500 border-t-transparent"
                            ></span>
                            <p class="text-lg font-semibold text-gray-900">
                                Đang quét Gmail…
                            </p>
                        </div>

                        <div>
                            <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-200">
                                <div
                                    class="h-full rounded-full bg-emerald-500 transition-all duration-300"
                                    :style="{ width: percent + '%' }"
                                ></div>
                            </div>
                            <p class="mt-2 text-center text-sm text-gray-500">
                                <template v-if="total > 0">
                                    Đang xử lý {{ processed }}/{{ total }} email — {{ percent }}%
                                </template>
                                <template v-else>
                                    Đang tìm email hóa đơn…
                                </template>
                            </p>
                        </div>
                    </template>

                    <template v-else>
                        <p class="text-lg font-semibold text-gray-900">Quét thất bại</p>
                        <p class="text-sm text-gray-600">{{ scanError }}</p>
                        <div class="flex justify-end gap-2">
                            <button
                                type="button"
                                class="rounded-md px-3 py-2 text-sm text-gray-600 hover:underline"
                                @click="closeScan"
                            >
                                Đóng
                            </button>
                            <button
                                type="button"
                                class="rounded-md bg-emerald-600 px-3 py-2 text-sm text-white hover:bg-emerald-500"
                                @click="startScan"
                            >
                                Thử lại
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </Teleport>
```

- [ ] **Step 3: Verify the build compiles**

Run: `npm run build`
Expected: PASS — build completes, no errors.

- [ ] **Step 4: Verify suites still pass**

Run: `npm run test:js && php artisan test`
Expected: PASS — Vitest green, full PHPUnit suite green.

- [ ] **Step 5: Manual verification (record in report)**

With `php artisan serve`, `npm run dev`, AND `php artisan queue:work` all running, and a connected Gmail user:
- Clicking "Quét Gmail" opens the popup; the bar and "N/M email — X%" advance as the worker processes messages.
- On completion the app navigates to the scan-results page with the detected candidates.
- Stopping the worker (so the job never runs) leaves the popup at "Đang tìm email…" — confirm the poll keeps running without error (documents the worker requirement).
- Note honestly which items are verified live vs. only by build.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Dashboard.vue
git commit -m "Update: show a real percentage bar while scanning Gmail

Start the scan via POST /gmail/scans, poll the scan's progress every
second, and render a live percentage bar (processed/total) in the
popup, navigating to the results page on completion. Failures show a
friendly message with retry/close. Polling is cleared on unmount.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Definition of Done

- Clicking "Quét Gmail" dispatches a background job and shows a popup whose bar advances with real `processed/total` progress.
- The scan runs in `queue:work` (database queue), not blocking the request; on completion the app shows the existing results page from stored candidates.
- Failures record a friendly, detail-free error and surface a retry.
- `gmail_scans` and all endpoints are user-scoped (cross-user → 404).
- `SubscriptionScanner::scan`'s progress callback is optional; grouping/dedup unchanged; existing tests pass.
- The obsolete synchronous scan route/method/tests are removed.
- New PHPUnit tests pass; full PHPUnit + Vitest suites stay green; `npm run build` succeeds.
- README/spec note the `php artisan queue:work` requirement.
