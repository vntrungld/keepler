# Orbit SP1 — Foundation & Auth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dựng nền tảng Orbit: app Laravel + Vue/Inertia, đăng nhập bằng Google, model User & Subscription, CRUD tối thiểu có phân quyền, quy đổi tiền tệ ra VND.

**Architecture:** Laravel monolith với Inertia.js + Vue 3 cho frontend. Đăng nhập qua Laravel Socialite (Google). Subscription tự tính `amount_vnd` qua một model `saving` hook gọi service quy đổi tiền tệ đọc tỉ giá tĩnh từ config. Phân quyền bằng Policy theo `user_id`.

**Tech Stack:** Laravel (mới nhất), Laravel Breeze (Vue+Inertia+Tailwind scaffold), Laravel Socialite, Vue 3, Vite, Tailwind CSS, SQLite, Pest (test).

## Global Constraints

- Thư mục dự án: `/home/trungld/work/trungld/orbit` (đã có `.git` và `docs/`).
- Database dev: SQLite (`database/database.sqlite`).
- Chu kỳ thanh toán chỉ: `monthly` | `yearly`.
- Trạng thái subscription: `active` | `pending_cancel` | `cancelled`.
- Đăng nhập CHỈ qua Google (scope `email`, `profile`). Không có luồng email/mật khẩu tự tạo; cột `password` để nullable.
- KHÔNG lưu Gmail access token, KHÔNG quét Gmail, KHÔNG dựng UI hành tinh ở SP1 (để dành SP2/SP3).
- `amount_vnd` luôn được tính tự động, không nhận từ input người dùng.
- Currency chưa cấu hình trong `config/currency.php` → coi là lỗi (validation từ chối / exception).
- Test framework: Pest. Mỗi task kết thúc bằng một commit.

---

### Task 1: Scaffold Laravel + Breeze (Vue/Inertia/Tailwind) + SQLite

**Files:**
- Create: toàn bộ khung Laravel trong `/home/trungld/work/trungld/orbit`
- Modify: `.env`, `config/database.php` (mặc định sqlite)

**Interfaces:**
- Consumes: (không)
- Produces: một app Laravel boot được, có Inertia+Vue+Tailwind, chạy `php artisan test` xanh, welcome page render.

- [ ] **Step 1: Scaffold Laravel vào thư mục tạm rồi gộp vào orbit (giữ `.git` và `docs/`)**

```bash
cd /home/trungld/work/trungld
composer create-project laravel/laravel orbit-scaffold
# Di chuyển toàn bộ file (kể cả file ẩn) vào orbit, không đè .git và docs
shopt -s dotglob
rsync -a --exclude='.git' /home/trungld/work/trungld/orbit-scaffold/ /home/trungld/work/trungld/orbit/
shopt -u dotglob
rm -rf /home/trungld/work/trungld/orbit-scaffold
cd /home/trungld/work/trungld/orbit
```

- [ ] **Step 2: Cài Breeze với stack Vue (Inertia + Vue + Tailwind)**

```bash
cd /home/trungld/work/trungld/orbit
composer require laravel/breeze --dev
php artisan breeze:install vue --no-interaction
npm install
```

- [ ] **Step 3: Cấu hình SQLite**

Sửa `.env`: xóa các dòng `DB_*` cũ và đặt:

```
DB_CONNECTION=sqlite
```

Tạo file DB và chạy migrate:

```bash
cd /home/trungld/work/trungld/orbit
touch database/database.sqlite
php artisan migrate --no-interaction
```

- [ ] **Step 4: Build assets và chạy test mặc định để xác nhận app xanh**

```bash
cd /home/trungld/work/trungld/orbit
npm run build
php artisan test
```

Expected: PASS (các test mặc định của Breeze). Nếu Breeze tạo test PHPUnit thay vì Pest, giữ nguyên — các task sau dùng cú pháp Pest, tương thích vì Laravel nạp cả hai.

- [ ] **Step 5: Commit**

```bash
cd /home/trungld/work/trungld/orbit
git add -A
git commit -m "Update: scaffold Laravel + Breeze Vue/Inertia with SQLite

Scaffold the Orbit foundation: Laravel app with Breeze (Vue + Inertia
+ Tailwind) and SQLite for local development.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 2: Currency conversion service (unit)

**Files:**
- Create: `config/currency.php`
- Create: `app/Support/CurrencyConverter.php`
- Test: `tests/Unit/CurrencyConverterTest.php`

**Interfaces:**
- Consumes: (không)
- Produces: `App\Support\CurrencyConverter::toVnd(float $amount, string $currency): int` — trả số tiền VND làm tròn; ném `InvalidArgumentException` nếu currency chưa cấu hình. Tỉ giá đọc từ `config('currency.rates')` dạng `['VND' => 1, 'USD' => 26000]`.

- [ ] **Step 1: Viết test thất bại**

`tests/Unit/CurrencyConverterTest.php`:

```php
<?php

use App\Support\CurrencyConverter;

beforeEach(function () {
    config()->set('currency.rates', ['VND' => 1, 'USD' => 26000]);
});

test('converts USD to VND using configured rate', function () {
    expect(CurrencyConverter::toVnd(9.99, 'USD'))->toBe(259740);
});

test('passes VND through unchanged', function () {
    expect(CurrencyConverter::toVnd(260000, 'VND'))->toBe(260000);
});

test('is case-insensitive on currency code', function () {
    expect(CurrencyConverter::toVnd(1, 'usd'))->toBe(26000);
});

test('throws for unsupported currency', function () {
    CurrencyConverter::toVnd(10, 'EUR');
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 2: Chạy test để xác nhận fail**

Run: `php artisan test --filter=CurrencyConverterTest`
Expected: FAIL với lỗi class `App\Support\CurrencyConverter` không tồn tại.

- [ ] **Step 3: Tạo config và service**

`config/currency.php`:

```php
<?php

return [
    // Tỉ giá tĩnh: 1 đơn vị tiền = ? VND. Nâng cấp lên API tỉ giá sau SP1.
    'rates' => [
        'VND' => 1,
        'USD' => 26000,
    ],
];
```

`app/Support/CurrencyConverter.php`:

```php
<?php

namespace App\Support;

use InvalidArgumentException;

class CurrencyConverter
{
    /**
     * Quy đổi số tiền từ một loại tiền sang VND (làm tròn số nguyên đồng).
     */
    public static function toVnd(float $amount, string $currency): int
    {
        $currency = strtoupper($currency);
        $rates = config('currency.rates', []);

        if (! array_key_exists($currency, $rates)) {
            throw new InvalidArgumentException("Unsupported currency: {$currency}");
        }

        return (int) round($amount * $rates[$currency]);
    }

    /**
     * Danh sách mã tiền được hỗ trợ (dùng cho validation).
     *
     * @return array<int, string>
     */
    public static function supportedCurrencies(): array
    {
        return array_keys(config('currency.rates', []));
    }
}
```

- [ ] **Step 4: Chạy test để xác nhận pass**

Run: `php artisan test --filter=CurrencyConverterTest`
Expected: PASS (4 test).

- [ ] **Step 5: Commit**

```bash
git add config/currency.php app/Support/CurrencyConverter.php tests/Unit/CurrencyConverterTest.php
git commit -m "Update: add currency converter service with static VND rates

Add CurrencyConverter::toVnd() reading static rates from
config/currency.php. Rejects unsupported currencies. Unit tested.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 3: User Google fields + Subscription model & migration

**Files:**
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php` (thêm `avatar`, `google_id`; `password` nullable)
- Create: `database/migrations/2026_07_09_000001_create_subscriptions_table.php`
- Create: `app/Models/Subscription.php`
- Modify: `app/Models/User.php` (thêm quan hệ `subscriptions()`)
- Test: `tests/Feature/SubscriptionModelTest.php`

**Interfaces:**
- Consumes: `App\Support\CurrencyConverter::toVnd()` (Task 2).
- Produces:
  - `App\Models\Subscription` với `$fillable = ['user_id','name','amount','currency','billing_cycle','next_renewal_date','status','cancel_url','notes']`; casts `next_renewal_date` → `date`, `amount` → `decimal:2`, `amount_vnd` → `integer`. Tự set `amount_vnd` qua `saving` hook.
  - `Subscription::belongsTo(User)` qua `user()`; `User::hasMany(Subscription)` qua `subscriptions()`.
  - Cột enum: `billing_cycle` ('monthly'|'yearly'), `status` ('active'|'pending_cancel'|'cancelled', default 'active').

- [ ] **Step 1: Viết test thất bại**

`tests/Feature/SubscriptionModelTest.php`:

```php
<?php

use App\Models\Subscription;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config()->set('currency.rates', ['VND' => 1, 'USD' => 26000]);
});

test('creating a subscription auto-computes amount_vnd from currency', function () {
    $user = User::factory()->create();

    $sub = Subscription::create([
        'user_id' => $user->id,
        'name' => 'Netflix',
        'amount' => 9.99,
        'currency' => 'USD',
        'billing_cycle' => 'monthly',
        'next_renewal_date' => '2026-08-01',
        'status' => 'active',
    ]);

    expect($sub->amount_vnd)->toBe(259740);
});

test('vnd subscription keeps amount as amount_vnd', function () {
    $user = User::factory()->create();

    $sub = Subscription::create([
        'user_id' => $user->id,
        'name' => 'Spotify VN',
        'amount' => 59000,
        'currency' => 'VND',
        'billing_cycle' => 'monthly',
        'next_renewal_date' => '2026-08-01',
    ]);

    expect($sub->amount_vnd)->toBe(59000);
});

test('a user has many subscriptions', function () {
    $user = User::factory()->create();
    Subscription::create([
        'user_id' => $user->id,
        'name' => 'ChatGPT',
        'amount' => 20,
        'currency' => 'USD',
        'billing_cycle' => 'monthly',
        'next_renewal_date' => '2026-08-01',
    ]);

    expect($user->subscriptions)->toHaveCount(1);
});
```

- [ ] **Step 2: Chạy test để xác nhận fail**

Run: `php artisan test --filter=SubscriptionModelTest`
Expected: FAIL — bảng `subscriptions` / model chưa tồn tại.

- [ ] **Step 3: Sửa migration users**

Trong `database/migrations/0001_01_01_000000_create_users_table.php`, bên trong `Schema::create('users', ...)`, đổi dòng `$table->string('password');` thành nullable và thêm 2 cột sau `email`:

```php
$table->string('email')->unique();
$table->string('avatar')->nullable();
$table->string('google_id')->nullable()->index();
$table->timestamp('email_verified_at')->nullable();
$table->string('password')->nullable();
```

- [ ] **Step 4: Tạo migration subscriptions**

`database/migrations/2026_07_09_000001_create_subscriptions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_vnd');
            $table->enum('billing_cycle', ['monthly', 'yearly']);
            $table->date('next_renewal_date');
            $table->enum('status', ['active', 'pending_cancel', 'cancelled'])->default('active');
            $table->string('cancel_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
```

- [ ] **Step 5: Tạo model Subscription**

`app/Models/Subscription.php`:

```php
<?php

namespace App\Models;

use App\Support\CurrencyConverter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'amount',
        'currency',
        'billing_cycle',
        'next_renewal_date',
        'status',
        'cancel_url',
        'notes',
    ];

    protected $casts = [
        'next_renewal_date' => 'date',
        'amount' => 'decimal:2',
        'amount_vnd' => 'integer',
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
}
```

- [ ] **Step 6: Thêm quan hệ vào User**

Trong `app/Models/User.php`, thêm import và method:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

public function subscriptions(): HasMany
{
    return $this->hasMany(Subscription::class);
}
```

- [ ] **Step 7: Chạy migrate rồi test để xác nhận pass**

```bash
php artisan migrate:fresh --no-interaction
php artisan test --filter=SubscriptionModelTest
```

Expected: PASS (3 test).

- [ ] **Step 8: Commit**

```bash
git add database/migrations app/Models/Subscription.php app/Models/User.php tests/Feature/SubscriptionModelTest.php
git commit -m "Update: add Subscription model and Google user fields

Add subscriptions table/model with auto-computed amount_vnd via a
saving hook, user relationship, and status/billing_cycle enums. Add
avatar/google_id to users and make password nullable.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 4: Google Sign-in via Socialite

**Files:**
- Modify: `composer.json` (require `laravel/socialite`)
- Modify: `config/services.php` (thêm block `google`)
- Modify: `.env` và `.env.example` (thêm `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`)
- Create: `app/Http/Controllers/Auth/GoogleController.php`
- Modify: `routes/auth.php` (thêm 2 route google)
- Modify: `resources/js/Pages/Auth/Login.vue` (thêm nút "Đăng nhập với Google")
- Test: `tests/Feature/GoogleLoginTest.php`

**Interfaces:**
- Consumes: `App\Models\User`.
- Produces:
  - `GET /auth/google/redirect` (name `google.redirect`) → redirect tới Google.
  - `GET /auth/google/callback` → `updateOrCreate` user theo `google_id`, đăng nhập, redirect `/dashboard`.
  - `App\Http\Controllers\Auth\GoogleController::redirect()` và `callback()`.

- [ ] **Step 1: Cài Socialite**

```bash
cd /home/trungld/work/trungld/orbit
composer require laravel/socialite
```

- [ ] **Step 2: Viết test thất bại**

`tests/Feature/GoogleLoginTest.php`:

```php
<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function fakeGoogleUser(string $id, string $email, string $name = 'Trung'): SocialiteUser
{
    $user = new SocialiteUser();
    $user->map([
        'id' => $id,
        'name' => $name,
        'email' => $email,
        'avatar' => 'https://example.com/avatar.png',
    ]);

    return $user;
}

test('callback creates a new user and authenticates', function () {
    Socialite::shouldReceive('driver->user')
        ->andReturn(fakeGoogleUser('google-123', 'trung@example.com'));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'google_id' => 'google-123',
        'email' => 'trung@example.com',
    ]);
    expect(User::count())->toBe(1);
});

test('callback with existing google_id does not duplicate user', function () {
    User::factory()->create([
        'google_id' => 'google-123',
        'email' => 'trung@example.com',
    ]);

    Socialite::shouldReceive('driver->user')
        ->andReturn(fakeGoogleUser('google-123', 'trung@example.com'));

    $this->get('/auth/google/callback');

    expect(User::count())->toBe(1);
    $this->assertAuthenticated();
});
```

- [ ] **Step 3: Chạy test để xác nhận fail**

Run: `php artisan test --filter=GoogleLoginTest`
Expected: FAIL — route `/auth/google/callback` chưa tồn tại (404).

- [ ] **Step 4: Cấu hình services + env**

Thêm vào `config/services.php` trong mảng trả về:

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
],
```

Thêm vào `.env` và `.env.example`:

```
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
```

- [ ] **Step 5: Tạo controller**

`app/Http/Controllers/Auth/GoogleController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')
            ->scopes(['email', 'profile'])
            ->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::updateOrCreate(
            ['google_id' => $googleUser->getId()],
            [
                'name' => $googleUser->getName() ?: $googleUser->getEmail(),
                'email' => $googleUser->getEmail(),
                'avatar' => $googleUser->getAvatar(),
            ],
        );

        Auth::login($user, remember: true);

        return redirect()->intended('/dashboard');
    }
}
```

- [ ] **Step 6: Thêm route**

Trong `routes/auth.php`, ở nhóm guest, thêm:

```php
use App\Http\Controllers\Auth\GoogleController;

Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])
    ->name('google.redirect');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])
    ->name('google.callback');
```

- [ ] **Step 7: Thêm nút vào trang Login**

Trong `resources/js/Pages/Auth/Login.vue`, thêm link trong `<template>` (trên hoặc dưới form):

```html
<div class="mt-4">
    <a
        href="/auth/google/redirect"
        class="flex w-full items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
    >
        Đăng nhập với Google
    </a>
</div>
```

- [ ] **Step 8: Chạy test để xác nhận pass**

Run: `php artisan test --filter=GoogleLoginTest`
Expected: PASS (2 test).

- [ ] **Step 9: Commit**

```bash
git add composer.json composer.lock config/services.php .env.example app/Http/Controllers/Auth/GoogleController.php routes/auth.php resources/js/Pages/Auth/Login.vue
git commit -m "Update: add Sign in with Google via Socialite

Add Google OAuth login (scopes email, profile) with a controller that
updateOrCreates users by google_id, plus routes and a login button.
Feature tested with a mocked Socialite user.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 5: Subscription CRUD + authorization

**Files:**
- Create: `app/Http/Controllers/SubscriptionController.php`
- Create: `app/Policies/SubscriptionPolicy.php`
- Create: `app/Http/Requests/SubscriptionRequest.php`
- Create: `database/factories/SubscriptionFactory.php`
- Modify: `routes/web.php` (resource route trong nhóm `auth`)
- Create: `resources/js/Pages/Subscriptions/Index.vue`
- Create: `resources/js/Pages/Subscriptions/Create.vue`
- Create: `resources/js/Pages/Subscriptions/Edit.vue`
- Test: `tests/Feature/SubscriptionCrudTest.php`

**Interfaces:**
- Consumes: `App\Models\Subscription`, `App\Models\User`, `App\Support\CurrencyConverter::supportedCurrencies()`.
- Produces:
  - Resource routes `subscriptions.{index,create,store,edit,update,destroy}` dưới middleware `auth`.
  - `SubscriptionPolicy` với `view/update/delete` trả true khi `subscription.user_id === user.id`.
  - `SubscriptionFactory` cho test.
  - `SubscriptionRequest` validate: `name` required; `amount` numeric>0; `currency` in supported; `billing_cycle` in monthly,yearly; `next_renewal_date` date; `status` in enum; `cancel_url` nullable url; `notes` nullable string. KHÔNG cho `user_id`/`amount_vnd` qua input.

- [ ] **Step 1: Tạo factory (hạ tầng cho test)**

`database/factories/SubscriptionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Netflix', 'Spotify', 'ChatGPT', 'Adobe']),
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => now()->addDays(10)->toDateString(),
            'status' => 'active',
        ];
    }
}
```

- [ ] **Step 2: Viết test thất bại**

`tests/Feature/SubscriptionCrudTest.php`:

```php
<?php

use App\Models\Subscription;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config()->set('currency.rates', ['VND' => 1, 'USD' => 26000]);
});

test('guest is redirected from subscriptions index', function () {
    $this->get('/subscriptions')->assertRedirect('/login');
});

test('user can store a subscription and amount_vnd is computed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/subscriptions', [
        'name' => 'Netflix',
        'amount' => 9.99,
        'currency' => 'USD',
        'billing_cycle' => 'monthly',
        'next_renewal_date' => '2026-08-01',
        'status' => 'active',
    ]);

    $response->assertRedirect('/subscriptions');
    $sub = Subscription::first();
    expect($sub->user_id)->toBe($user->id);
    expect($sub->amount_vnd)->toBe(259740);
});

test('store rejects an unsupported currency', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/subscriptions', [
        'name' => 'Foo',
        'amount' => 10,
        'currency' => 'EUR',
        'billing_cycle' => 'monthly',
        'next_renewal_date' => '2026-08-01',
        'status' => 'active',
    ])->assertSessionHasErrors('currency');
});

test('user cannot update another users subscription', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $sub = Subscription::factory()->for($owner)->create();

    $this->actingAs($other)->put("/subscriptions/{$sub->id}", [
        'name' => 'Hacked',
        'amount' => 1,
        'currency' => 'USD',
        'billing_cycle' => 'monthly',
        'next_renewal_date' => '2026-08-01',
        'status' => 'active',
    ])->assertForbidden();
});

test('user cannot delete another users subscription', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $sub = Subscription::factory()->for($owner)->create();

    $this->actingAs($other)->delete("/subscriptions/{$sub->id}")->assertForbidden();
    $this->assertDatabaseHas('subscriptions', ['id' => $sub->id]);
});

test('user can delete own subscription', function () {
    $user = User::factory()->create();
    $sub = Subscription::factory()->for($user)->create();

    $this->actingAs($user)->delete("/subscriptions/{$sub->id}")->assertRedirect('/subscriptions');
    $this->assertDatabaseMissing('subscriptions', ['id' => $sub->id]);
});
```

- [ ] **Step 3: Chạy test để xác nhận fail**

Run: `php artisan test --filter=SubscriptionCrudTest`
Expected: FAIL — route/controller chưa tồn tại.

- [ ] **Step 4: Tạo Form Request**

`app/Http/Requests/SubscriptionRequest.php`:

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
        ];
    }
}
```

- [ ] **Step 5: Tạo Policy và đăng ký**

`app/Policies/SubscriptionPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function view(User $user, Subscription $subscription): bool
    {
        return $subscription->user_id === $user->id;
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $subscription->user_id === $user->id;
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return $subscription->user_id === $user->id;
    }
}
```

Laravel tự phát hiện policy theo quy ước (Model `Subscription` → `SubscriptionPolicy`). Không cần đăng ký thủ công.

- [ ] **Step 6: Tạo Controller**

`app/Http/Controllers/SubscriptionController.php`:

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

    public function create()
    {
        return Inertia::render('Subscriptions/Create', [
            'currencies' => CurrencyConverter::supportedCurrencies(),
        ]);
    }

    public function store(SubscriptionRequest $request)
    {
        $request->user()->subscriptions()->create($request->validated());

        return redirect('/subscriptions');
    }

    public function edit(Subscription $subscription)
    {
        $this->authorize('update', $subscription);

        return Inertia::render('Subscriptions/Edit', [
            'subscription' => $subscription,
            'currencies' => CurrencyConverter::supportedCurrencies(),
        ]);
    }

    public function update(SubscriptionRequest $request, Subscription $subscription)
    {
        $this->authorize('update', $subscription);

        $subscription->update($request->validated());

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

- [ ] **Step 7: Thêm route**

Trong `routes/web.php`, thêm trong nhóm middleware `auth`:

```php
use App\Http\Controllers\SubscriptionController;

Route::middleware('auth')->group(function () {
    Route::resource('subscriptions', SubscriptionController::class)
        ->except('show');
});
```

Lưu ý: giữ nguyên các route `auth` sẵn có của Breeze; chỉ thêm resource này.

- [ ] **Step 8: Tạo các trang Vue tối thiểu**

`resources/js/Pages/Subscriptions/Index.vue`:

```vue
<script setup>
import { Link, router } from '@inertiajs/vue3';

defineProps({ subscriptions: Array });

function destroy(id) {
    if (confirm('Xóa dịch vụ này?')) {
        router.delete(`/subscriptions/${id}`);
    }
}
</script>

<template>
    <div class="mx-auto max-w-3xl p-6">
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-xl font-semibold">Dịch vụ đăng ký</h1>
            <Link href="/subscriptions/create" class="rounded bg-indigo-600 px-3 py-2 text-white">Thêm</Link>
        </div>
        <table class="w-full text-left">
            <thead>
                <tr class="border-b">
                    <th class="py-2">Tên</th>
                    <th>Giá</th>
                    <th>VND</th>
                    <th>Chu kỳ</th>
                    <th>Gia hạn</th>
                    <th>Trạng thái</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="s in subscriptions" :key="s.id" class="border-b">
                    <td class="py-2">{{ s.name }}</td>
                    <td>{{ s.amount }} {{ s.currency }}</td>
                    <td>{{ s.amount_vnd }}</td>
                    <td>{{ s.billing_cycle }}</td>
                    <td>{{ s.next_renewal_date }}</td>
                    <td>{{ s.status }}</td>
                    <td class="space-x-2">
                        <Link :href="`/subscriptions/${s.id}/edit`" class="text-indigo-600">Sửa</Link>
                        <button class="text-red-600" @click="destroy(s.id)">Xóa</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
```

`resources/js/Pages/Subscriptions/Create.vue`:

```vue
<script setup>
import { useForm } from '@inertiajs/vue3';

defineProps({ currencies: Array });

const form = useForm({
    name: '',
    amount: '',
    currency: 'VND',
    billing_cycle: 'monthly',
    next_renewal_date: '',
    status: 'active',
    cancel_url: '',
    notes: '',
});

function submit() {
    form.post('/subscriptions');
}
</script>

<template>
    <form class="mx-auto max-w-lg space-y-3 p-6" @submit.prevent="submit">
        <h1 class="text-xl font-semibold">Thêm dịch vụ</h1>
        <input v-model="form.name" placeholder="Tên" class="w-full border p-2" />
        <input v-model="form.amount" type="number" step="0.01" placeholder="Số tiền" class="w-full border p-2" />
        <select v-model="form.currency" class="w-full border p-2">
            <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
        </select>
        <select v-model="form.billing_cycle" class="w-full border p-2">
            <option value="monthly">Hàng tháng</option>
            <option value="yearly">Hàng năm</option>
        </select>
        <input v-model="form.next_renewal_date" type="date" class="w-full border p-2" />
        <select v-model="form.status" class="w-full border p-2">
            <option value="active">Đang hoạt động</option>
            <option value="pending_cancel">Sắp hủy</option>
            <option value="cancelled">Đã hủy</option>
        </select>
        <input v-model="form.cancel_url" placeholder="Link hủy (tùy chọn)" class="w-full border p-2" />
        <textarea v-model="form.notes" placeholder="Ghi chú" class="w-full border p-2"></textarea>
        <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white">Lưu</button>
    </form>
</template>
```

`resources/js/Pages/Subscriptions/Edit.vue`:

```vue
<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({ subscription: Object, currencies: Array });

const form = useForm({
    name: props.subscription.name,
    amount: props.subscription.amount,
    currency: props.subscription.currency,
    billing_cycle: props.subscription.billing_cycle,
    next_renewal_date: props.subscription.next_renewal_date?.slice(0, 10),
    status: props.subscription.status,
    cancel_url: props.subscription.cancel_url ?? '',
    notes: props.subscription.notes ?? '',
});

function submit() {
    form.put(`/subscriptions/${props.subscription.id}`);
}
</script>

<template>
    <form class="mx-auto max-w-lg space-y-3 p-6" @submit.prevent="submit">
        <h1 class="text-xl font-semibold">Sửa dịch vụ</h1>
        <input v-model="form.name" class="w-full border p-2" />
        <input v-model="form.amount" type="number" step="0.01" class="w-full border p-2" />
        <select v-model="form.currency" class="w-full border p-2">
            <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
        </select>
        <select v-model="form.billing_cycle" class="w-full border p-2">
            <option value="monthly">Hàng tháng</option>
            <option value="yearly">Hàng năm</option>
        </select>
        <input v-model="form.next_renewal_date" type="date" class="w-full border p-2" />
        <select v-model="form.status" class="w-full border p-2">
            <option value="active">Đang hoạt động</option>
            <option value="pending_cancel">Sắp hủy</option>
            <option value="cancelled">Đã hủy</option>
        </select>
        <input v-model="form.cancel_url" class="w-full border p-2" />
        <textarea v-model="form.notes" class="w-full border p-2"></textarea>
        <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white">Cập nhật</button>
    </form>
</template>
```

- [ ] **Step 9: Chạy test để xác nhận pass**

Run: `php artisan test --filter=SubscriptionCrudTest`
Expected: PASS (7 test).

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/SubscriptionController.php app/Policies/SubscriptionPolicy.php app/Http/Requests/SubscriptionRequest.php database/factories/SubscriptionFactory.php routes/web.php resources/js/Pages/Subscriptions tests/Feature/SubscriptionCrudTest.php
git commit -m "Update: add subscription CRUD with per-user authorization

Add resource controller, form request validation (rejects unsupported
currencies), ownership policy, factory, and minimal Vue pages for
listing/creating/editing subscriptions. Feature tested.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 6: Seed sample data for SP2

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/DatabaseSeederTest.php`

**Interfaces:**
- Consumes: `App\Models\User`, `App\Models\Subscription`.
- Produces: Seeder tạo 1 demo user (`demo@orbit.test`, `google_id` = `demo-google`) và ≥4 subscription mẫu đa dạng chu kỳ/tiền tệ.

- [ ] **Step 1: Viết test thất bại**

`tests/Feature/DatabaseSeederTest.php`:

```php
<?php

use App\Models\Subscription;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config()->set('currency.rates', ['VND' => 1, 'USD' => 26000]);
});

test('seeder creates a demo user with sample subscriptions', function () {
    $this->seed();

    $user = User::where('email', 'demo@orbit.test')->first();
    expect($user)->not->toBeNull();
    expect(Subscription::where('user_id', $user->id)->count())->toBeGreaterThanOrEqual(4);
});
```

- [ ] **Step 2: Chạy test để xác nhận fail**

Run: `php artisan test --filter=DatabaseSeederTest`
Expected: FAIL — chưa có demo user.

- [ ] **Step 3: Viết seeder**

Thay nội dung `database/seeders/DatabaseSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'demo@orbit.test'],
            ['name' => 'Orbit Demo', 'google_id' => 'demo-google'],
        );

        $samples = [
            ['name' => 'Netflix', 'amount' => 260000, 'currency' => 'VND', 'billing_cycle' => 'monthly', 'days' => 5, 'status' => 'active'],
            ['name' => 'Spotify', 'amount' => 59000, 'currency' => 'VND', 'billing_cycle' => 'monthly', 'days' => 20, 'status' => 'active'],
            ['name' => 'ChatGPT Plus', 'amount' => 20, 'currency' => 'USD', 'billing_cycle' => 'monthly', 'days' => 12, 'status' => 'pending_cancel'],
            ['name' => 'Adobe Creative Cloud', 'amount' => 599.88, 'currency' => 'USD', 'billing_cycle' => 'yearly', 'days' => 200, 'status' => 'active'],
            ['name' => 'YouTube Premium', 'amount' => 79000, 'currency' => 'VND', 'billing_cycle' => 'monthly', 'days' => 1, 'status' => 'cancelled'],
        ];

        foreach ($samples as $s) {
            Subscription::updateOrCreate(
                ['user_id' => $user->id, 'name' => $s['name']],
                [
                    'amount' => $s['amount'],
                    'currency' => $s['currency'],
                    'billing_cycle' => $s['billing_cycle'],
                    'next_renewal_date' => now()->addDays($s['days'])->toDateString(),
                    'status' => $s['status'],
                ],
            );
        }
    }
}
```

- [ ] **Step 4: Chạy test để xác nhận pass**

Run: `php artisan test --filter=DatabaseSeederTest`
Expected: PASS (1 test).

- [ ] **Step 5: Chạy toàn bộ test suite**

Run: `php artisan test`
Expected: PASS toàn bộ.

- [ ] **Step 6: Commit**

```bash
git add database/seeders/DatabaseSeeder.php tests/Feature/DatabaseSeederTest.php
git commit -m "Update: seed demo user and sample subscriptions

Add idempotent seeder creating a demo user with diverse sample
subscriptions (mixed currency, cycle, status) so SP2 has data to
render. Seeder feature tested.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- §4 Stack (Laravel+Vue/Inertia/Tailwind/SQLite) → Task 1. ✓
- §5 Model users Google fields + subscriptions → Task 3. ✓
- §5 Tỉ giá config + quy đổi → Task 2 (service) + Task 3 (saving hook). ✓
- §6 Luồng đăng nhập Google → Task 4. ✓
- §7 CRUD tối thiểu + phân quyền user → Task 5. ✓
- §7 Seeder dữ liệu mẫu → Task 6. ✓
- §8 Test: đăng nhập Google (mock) → Task 4; CRUD + phân quyền → Task 5; amount_vnd → Task 3 & 5; quy đổi tiền tệ → Task 2; currency chưa cấu hình → Task 2 & 5. ✓
- §3 Ngoài phạm vi (Gmail token, UI hành tinh) → không có task nào chạm tới. ✓

**Placeholder scan:** Không có TBD/TODO; mọi step có code/command cụ thể. ✓

**Type consistency:** `CurrencyConverter::toVnd()` và `supportedCurrencies()` khai báo ở Task 2, dùng nhất quán ở Task 3/5. Tên cột (`amount_vnd`, `billing_cycle`, `next_renewal_date`, `status`) đồng nhất giữa migration, model, request, test, seeder. Route `/subscriptions`, `/auth/google/callback` nhất quán giữa controller/route/test. ✓
