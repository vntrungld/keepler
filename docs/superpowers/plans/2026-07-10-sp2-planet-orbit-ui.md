# SP2 — Planet/Orbit UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Render the user's active subscriptions as an interactive SVG "solar system" at `/dashboard` — user = sun, each subscription = a planet on one of two orbits (monthly/yearly).

**Architecture:** Pure geometry + brand-color math live in framework-free JS modules (`resources/js/orbit/layout.js`, `brandColors.js`) that are unit-tested with Vitest. A thin `DashboardController` feeds per-user, non-cancelled subscriptions to an Inertia `Dashboard` page. Vue components (`Orbit.vue`, `Planet.vue`, `PlanetTooltip.vue`, `OrbitDetailPanel.vue`) consume those modules to draw and animate the scene and reuse SP1's CRUD routes for edit/delete.

**Tech Stack:** Laravel 13, Inertia 2 + Vue 3, Tailwind, SVG. New: Vitest (JS unit tests). PHPUnit (class style) for backend.

## Global Constraints

- **Tests use PHPUnit class style — NOT Pest** (project convention from SP1).
- Billing cycles are exactly `monthly` | `yearly`; status is exactly `active` | `pending_cancel` | `cancelled`.
- The orbit shows only `active` + `pending_cancel`; **`cancelled` is excluded entirely** (filtered server-side).
- Planet **size** is driven by `amount_vnd` (raw per-charge amount, no monthly normalization).
- Planet **fill color** = brand color from a small catalog, with a deterministic hash→palette fallback for unknown names.
- **`R_MIN` is a hard floor (12px):** `planetRadius` never returns below it, so the cheapest planet stays visible and clickable. Ceiling `R_MAX` = 40px.
- Planets on one orbit are **evenly distributed** by angle (`360/N`); angle does NOT encode time.
- Urgency: `next_renewal_date` within 7 days (or overdue) → pulsing glow.
- Respect `prefers-reduced-motion` — no drift/pulse animation when the user opts out.
- Do NOT modify SP1's `Subscription` model, migrations, or CRUD controllers unless a task explicitly requires it. SP2 is read + display.
- Data is always scoped to the authenticated user.
- Vue import alias `@` = `resources/js` (already works; no vite config change needed).

---

## File Structure

**Create:**
- `vitest.config.js` — Vitest config (node env, `resources/js/**/*.test.js`).
- `resources/js/orbit/layout.js` — pure geometry: angle distribution, radius scaling, polar→xy, day math.
- `resources/js/orbit/layout.test.js` — Vitest unit tests for layout.
- `resources/js/orbit/brandColors.js` — pure: brand color lookup, initial letter, contrast text.
- `resources/js/orbit/brandColors.test.js` — Vitest unit tests for brand colors.
- `resources/js/orbit/Orbit.vue` — SVG scene: sun, orbit rings, planets, animation.
- `resources/js/orbit/Planet.vue` — one planet (body, ring, glow, initial), emits hover/leave/select.
- `resources/js/orbit/PlanetTooltip.vue` — hover tooltip (HTML overlay).
- `resources/js/orbit/OrbitDetailPanel.vue` — click detail panel with Edit/Delete.
- `app/Http/Controllers/DashboardController.php` — feeds scoped subscriptions to the page.
- `tests/Feature/DashboardTest.php` — PHPUnit feature tests for the dashboard route.

**Modify:**
- `package.json` — add `vitest` devDependency + `test:js` script.
- `routes/web.php` — point `/dashboard` at `DashboardController@index`.
- `resources/js/Pages/Dashboard.vue` — render `Orbit.vue` / empty state.

---

## Task 1: Vitest setup + pure layout math

**Files:**
- Create: `vitest.config.js`
- Create: `resources/js/orbit/layout.js`
- Test: `resources/js/orbit/layout.test.js`
- Modify: `package.json`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `distributeAngles(count: number, rotationOffset = 0): number[]` — degrees, `offset + i*(360/count)`; `[]` when count is 0.
  - `planetRadius(amountVnd: number, minVnd: number, maxVnd: number, opts?: {rMin?: number, rMax?: number}): number` — sqrt scale, clamped to `[rMin=12, rMax=40]`; returns midpoint when `minVnd === maxVnd`.
  - `polarToXy(cx: number, cy: number, orbitRadius: number, angleDeg: number): {x: number, y: number}` — angle 0 = top (12 o'clock), increasing clockwise.
  - `daysUntil(renewalDate: string, today: string): number` — whole days, negative if overdue; accepts `YYYY-MM-DD` or ISO datetime (uses first 10 chars).
  - `isUrgent(days: number): boolean` — `days <= 7` (includes overdue).

- [ ] **Step 1: Add Vitest to package.json**

Add to `devDependencies` (keep alphabetical-ish, place after `vue` or anywhere in the block) and add a script:

```json
"scripts": {
    "build": "vite build",
    "dev": "vite",
    "test:js": "vitest run"
},
```

Add to `devDependencies`:

```json
"vitest": "^2.1.0"
```

Then install:

Run: `npm install -D vitest`
Expected: adds vitest to node_modules (needs network; if it fails, report BLOCKED).

- [ ] **Step 2: Create vitest.config.js**

```javascript
import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        include: ['resources/js/**/*.test.js'],
        environment: 'node',
    },
});
```

- [ ] **Step 3: Write the failing test**

Create `resources/js/orbit/layout.test.js`:

```javascript
import { describe, it, expect } from 'vitest';
import {
    distributeAngles,
    planetRadius,
    polarToXy,
    daysUntil,
    isUrgent,
} from './layout.js';

describe('distributeAngles', () => {
    it('places a single planet at the offset', () => {
        expect(distributeAngles(1, 0)).toEqual([0]);
    });
    it('spreads two planets 180 apart', () => {
        expect(distributeAngles(2, 0)).toEqual([0, 180]);
    });
    it('spreads three planets 120 apart', () => {
        expect(distributeAngles(3, 0)).toEqual([0, 120, 240]);
    });
    it('spreads four planets 90 apart', () => {
        expect(distributeAngles(4, 0)).toEqual([0, 90, 180, 270]);
    });
    it('applies the rotation offset', () => {
        expect(distributeAngles(2, 45)).toEqual([45, 225]);
    });
    it('returns an empty array for zero planets', () => {
        expect(distributeAngles(0, 0)).toEqual([]);
    });
});

describe('planetRadius', () => {
    it('returns the midpoint when all amounts are equal', () => {
        expect(planetRadius(100, 100, 100)).toBe(26); // (12+40)/2
    });
    it('returns rMin at the cheapest amount', () => {
        expect(planetRadius(100, 100, 400)).toBe(12);
    });
    it('returns rMax at the priciest amount', () => {
        expect(planetRadius(400, 100, 400)).toBe(40);
    });
    it('is monotonic in amount', () => {
        const a = planetRadius(200, 100, 400);
        const b = planetRadius(300, 100, 400);
        expect(b).toBeGreaterThan(a);
    });
    it('clamps above rMax and never below rMin', () => {
        expect(planetRadius(100000, 100, 400)).toBe(40);
        expect(planetRadius(1, 100, 400)).toBe(12);
    });
    it('honors custom rMin/rMax', () => {
        expect(planetRadius(100, 100, 100, { rMin: 10, rMax: 30 })).toBe(20);
    });
});

describe('polarToXy', () => {
    it('puts angle 0 at the top', () => {
        const p = polarToXy(100, 100, 50, 0);
        expect(p.x).toBeCloseTo(100);
        expect(p.y).toBeCloseTo(50);
    });
    it('puts angle 90 to the right', () => {
        const p = polarToXy(100, 100, 50, 90);
        expect(p.x).toBeCloseTo(150);
        expect(p.y).toBeCloseTo(100);
    });
    it('puts angle 180 at the bottom', () => {
        const p = polarToXy(100, 100, 50, 180);
        expect(p.x).toBeCloseTo(100);
        expect(p.y).toBeCloseTo(150);
    });
});

describe('daysUntil', () => {
    it('counts whole days ahead', () => {
        expect(daysUntil('2026-07-17', '2026-07-10')).toBe(7);
    });
    it('is zero on the day', () => {
        expect(daysUntil('2026-07-10', '2026-07-10')).toBe(0);
    });
    it('is negative when overdue', () => {
        expect(daysUntil('2026-07-05', '2026-07-10')).toBe(-5);
    });
    it('crosses month and year boundaries', () => {
        expect(daysUntil('2026-08-01', '2026-07-10')).toBe(22);
        expect(daysUntil('2027-01-01', '2026-12-31')).toBe(1);
    });
    it('accepts ISO datetime strings', () => {
        expect(daysUntil('2026-07-17T00:00:00.000000Z', '2026-07-10')).toBe(7);
    });
});

describe('isUrgent', () => {
    it('is true within 7 days and when overdue', () => {
        expect(isUrgent(0)).toBe(true);
        expect(isUrgent(7)).toBe(true);
        expect(isUrgent(-3)).toBe(true);
    });
    it('is false beyond 7 days', () => {
        expect(isUrgent(8)).toBe(false);
        expect(isUrgent(30)).toBe(false);
    });
});
```

- [ ] **Step 4: Run test to verify it fails**

Run: `npm run test:js`
Expected: FAIL — cannot resolve `./layout.js` / functions not defined.

- [ ] **Step 5: Implement layout.js**

Create `resources/js/orbit/layout.js`:

```javascript
// Pure geometry + day math for the orbit visualization. No DOM, no Vue —
// everything here is unit-tested in layout.test.js.

const DEFAULT_R_MIN = 12;
const DEFAULT_R_MAX = 40;

export function distributeAngles(count, rotationOffset = 0) {
    if (count <= 0) return [];
    const step = 360 / count;
    return Array.from({ length: count }, (_, i) => rotationOffset + i * step);
}

export function planetRadius(amountVnd, minVnd, maxVnd, opts = {}) {
    const rMin = opts.rMin ?? DEFAULT_R_MIN;
    const rMax = opts.rMax ?? DEFAULT_R_MAX;
    const s = Math.sqrt(Math.max(0, amountVnd));
    const sMin = Math.sqrt(Math.max(0, minVnd));
    const sMax = Math.sqrt(Math.max(0, maxVnd));
    if (sMax === sMin) return (rMin + rMax) / 2;
    let t = (s - sMin) / (sMax - sMin);
    t = Math.min(1, Math.max(0, t));
    return rMin + t * (rMax - rMin);
}

export function polarToXy(cx, cy, orbitRadius, angleDeg) {
    const rad = (angleDeg * Math.PI) / 180;
    return {
        x: cx + orbitRadius * Math.sin(rad),
        y: cy - orbitRadius * Math.cos(rad),
    };
}

function toUtcMidnight(dateStr) {
    const [y, m, d] = dateStr.slice(0, 10).split('-').map(Number);
    return Date.UTC(y, m - 1, d);
}

export function daysUntil(renewalDate, today) {
    const ms = toUtcMidnight(renewalDate) - toUtcMidnight(today);
    return Math.round(ms / 86400000);
}

export function isUrgent(days) {
    return days <= 7;
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `npm run test:js`
Expected: PASS — all layout tests green, output pristine.

- [ ] **Step 7: Commit**

```bash
git add package.json package-lock.json vitest.config.js resources/js/orbit/layout.js resources/js/orbit/layout.test.js
git commit -m "Update: add Vitest and pure orbit layout math

Adds Vitest for framework-free JS unit tests and the layout module
(angle distribution, sqrt-scaled planet radius with a hard R_MIN
floor, polar-to-xy, and day-until-renewal math) with full coverage.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 2: Brand color module

**Files:**
- Create: `resources/js/orbit/brandColors.js`
- Test: `resources/js/orbit/brandColors.test.js`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `brandColor(name: string): string` — `#RRGGBB`; catalog match by normalized substring, else deterministic palette pick by name hash.
  - `initial(name: string): string` — first alphanumeric char uppercased, `'?'` if none.
  - `contrastText(hex: string): '#fff' | '#000'` — readable text color over the given hex fill.

- [ ] **Step 1: Write the failing test**

Create `resources/js/orbit/brandColors.test.js`:

```javascript
import { describe, it, expect } from 'vitest';
import { brandColor, initial, contrastText } from './brandColors.js';

describe('brandColor', () => {
    it('maps known brands to their color, case-insensitively', () => {
        expect(brandColor('Netflix')).toBe('#E50914');
        expect(brandColor('NETFLIX')).toBe('#E50914');
        expect(brandColor('Spotify')).toBe('#1DB954');
    });
    it('matches a brand keyword inside a longer name', () => {
        expect(brandColor('Netflix Premium 4K')).toBe('#E50914');
    });
    it('gives unknown names a stable, valid hex color', () => {
        const first = brandColor('Foobar Cloud');
        const second = brandColor('Foobar Cloud');
        expect(first).toBe(second);
        expect(first).toMatch(/^#[0-9A-Fa-f]{6}$/);
    });
});

describe('initial', () => {
    it('returns the first letter uppercased', () => {
        expect(initial('Netflix')).toBe('N');
        expect(initial('  spotify')).toBe('S');
    });
    it('falls back to ? for empty names', () => {
        expect(initial('')).toBe('?');
        expect(initial('   ')).toBe('?');
    });
});

describe('contrastText', () => {
    it('uses dark text on light fills', () => {
        expect(contrastText('#FFFFFF')).toBe('#000');
    });
    it('uses light text on dark fills', () => {
        expect(contrastText('#000000')).toBe('#fff');
        expect(contrastText('#E50914')).toBe('#fff');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:js`
Expected: FAIL — cannot resolve `./brandColors.js`.

- [ ] **Step 3: Implement brandColors.js**

Create `resources/js/orbit/brandColors.js`:

```javascript
// Maps a subscription name to a stable brand color, an initial letter, and a
// readable text color. Pure and unit-tested in brandColors.test.js.

// Ordered so earlier keywords win when a name contains several.
const CATALOG = [
    ['netflix', '#E50914'],
    ['spotify', '#1DB954'],
    ['youtube', '#FF0000'],
    ['chatgpt', '#10A37F'],
    ['openai', '#10A37F'],
    ['adobe', '#FF0000'],
    ['apple', '#555555'],
    ['google', '#4285F4'],
    ['amazon', '#FF9900'],
    ['prime', '#FF9900'],
    ['disney', '#113CCF'],
];

// Fallback palette for unknown services (all hex so contrastText works).
const PALETTE = [
    '#6366F1', '#EC4899', '#F59E0B', '#10B981',
    '#3B82F6', '#8B5CF6', '#EF4444', '#14B8A6',
];

function normalize(name) {
    return (name || '').toLowerCase().replace(/[^a-z0-9]/g, '');
}

function hashString(s) {
    let h = 0;
    for (let i = 0; i < s.length; i++) {
        h = (h * 31 + s.charCodeAt(i)) >>> 0;
    }
    return h;
}

export function brandColor(name) {
    const norm = normalize(name);
    for (const [keyword, color] of CATALOG) {
        if (norm.includes(keyword)) return color;
    }
    return PALETTE[hashString(norm) % PALETTE.length];
}

export function initial(name) {
    const match = (name || '').match(/[a-z0-9]/i);
    return match ? match[0].toUpperCase() : '?';
}

export function contrastText(hex) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    const luminance = 0.299 * r + 0.587 * g + 0.114 * b;
    return luminance > 150 ? '#000' : '#fff';
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm run test:js`
Expected: PASS — all layout + brandColors tests green.

- [ ] **Step 5: Commit**

```bash
git add resources/js/orbit/brandColors.js resources/js/orbit/brandColors.test.js
git commit -m "Update: add brand color module for planets

Maps subscription names to stable brand colors via a small catalog
(Netflix, Spotify, YouTube, ChatGPT, Adobe, Apple, Google, Amazon,
Disney) with a deterministic hash-to-palette fallback for unknown
services, plus initial-letter and contrast-text helpers.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 3: DashboardController + feature tests

**Files:**
- Create: `app/Http/Controllers/DashboardController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `Subscription` model + `user()->subscriptions()` relation (SP1).
- Produces: Inertia `Dashboard` page prop `subscriptions: Array<{id, name, amount, currency, amount_vnd, billing_cycle, next_renewal_date, status}>` — current user only, `cancelled` excluded, ordered by `id`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DashboardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_dashboard_renders_only_the_current_users_non_cancelled_subscriptions(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create(['name' => 'Netflix', 'status' => 'active']);
        Subscription::factory()->for($user)->create(['name' => 'Spotify', 'status' => 'pending_cancel']);
        Subscription::factory()->for($user)->create(['name' => 'Old', 'status' => 'cancelled']);

        $other = User::factory()->create();
        Subscription::factory()->for($other)->create(['name' => 'Theirs', 'status' => 'active']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('subscriptions', 2)
                ->where('subscriptions.0.name', 'Netflix')
                ->where('subscriptions.1.name', 'Spotify')
            );
    }

    public function test_each_subscription_prop_has_the_fields_the_orbit_needs(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('subscriptions.0', fn (Assert $sub) => $sub
                    ->hasAll(['id', 'name', 'amount', 'currency', 'amount_vnd', 'billing_cycle', 'next_renewal_date', 'status'])
                )
            );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL — `Dashboard` currently renders from a closure with no `subscriptions` prop (component asserts pass but `has('subscriptions', 2)` fails / prop missing).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $subscriptions = $request->user()->subscriptions()
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get([
                'id', 'name', 'amount', 'currency', 'amount_vnd',
                'billing_cycle', 'next_renewal_date', 'status',
            ]);

        return Inertia::render('Dashboard', [
            'subscriptions' => $subscriptions,
        ]);
    }
}
```

- [ ] **Step 4: Point the route at the controller**

In `routes/web.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\DashboardController;
```

Replace the existing dashboard closure:

```php
Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');
```

with:

```php
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
```

If the `Inertia` facade import is now unused elsewhere in the file, leave it — the `/` route still uses `Inertia::render`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS — 3 tests green.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DashboardController.php routes/web.php tests/Feature/DashboardTest.php
git commit -m "Update: feed scoped subscriptions to the dashboard

Add DashboardController@index returning the current user's
non-cancelled subscriptions (ordered by id, with only the fields the
orbit needs) to the Inertia Dashboard page, and point /dashboard at
it. Feature tests cover guest redirect, per-user scoping, cancelled
exclusion, and prop shape.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 4: Orbit scene — sun, orbits, planets, animation

**Files:**
- Create: `resources/js/orbit/Planet.vue`
- Create: `resources/js/orbit/Orbit.vue`
- Modify: `resources/js/Pages/Dashboard.vue`

**Interfaces:**
- Consumes: `layout.js` (`distributeAngles`, `planetRadius`, `polarToXy`, `daysUntil`, `isUrgent`), `brandColors.js` (`brandColor`, `initial`, `contrastText`), and the `subscriptions` prop from Task 3.
- Produces:
  - `Orbit.vue` — props `{ subscriptions: Array, user: Object }`. Groups by billing cycle, positions/animates planets, draws sun + rings. (Hover/click wiring added in Task 5.)
  - `Planet.vue` — props `{ cx, cy, radius, color, textColor, letter, status, urgent }`; emits `hover`, `leave`, `select`.

- [ ] **Step 1: Create Planet.vue**

Create `resources/js/orbit/Planet.vue`:

```vue
<script setup>
defineProps({
    cx: { type: Number, required: true },
    cy: { type: Number, required: true },
    radius: { type: Number, required: true },
    color: { type: String, required: true },
    textColor: { type: String, required: true },
    letter: { type: String, required: true },
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
        <text
            :x="cx"
            :y="cy"
            :fill="textColor"
            :font-size="radius"
            text-anchor="middle"
            dominant-baseline="central"
            font-weight="700"
            style="pointer-events: none; user-select: none"
        >
            {{ letter }}
        </text>
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
}
@media (prefers-reduced-motion: no-preference) {
    .orbit-pulse {
        animation: orbit-pulse 1.6s ease-in-out infinite;
    }
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

- [ ] **Step 2: Create Orbit.vue**

Create `resources/js/orbit/Orbit.vue`:

```vue
<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Planet from './Planet.vue';
import {
    distributeAngles,
    planetRadius,
    polarToXy,
    daysUntil,
    isUrgent,
} from './layout.js';
import { brandColor, contrastText, initial } from './brandColors.js';

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
const SPEED_MONTHLY = 6; // degrees per second
const SPEED_YEARLY = 2.5;

const elapsed = ref(0); // seconds since mount, drives drift
const reduceMotion = ref(false);
let rafId = null;
let last = null;

function frame(ts) {
    if (last === null) last = ts;
    elapsed.value += (ts - last) / 1000;
    last = ts;
    rafId = requestAnimationFrame(frame);
}

onMounted(() => {
    reduceMotion.value =
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    if (!reduceMotion.value) {
        rafId = requestAnimationFrame(frame);
    }
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
    const offset = reduceMotion.value ? 0 : elapsed.value * speed;
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
            textColor: contrastText(color),
            letter: initial(sub.name),
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

const sunInitial = computed(() => initial(props.user?.name ?? ''));
</script>

<template>
    <div class="relative mx-auto aspect-square w-full max-w-2xl">
        <svg :viewBox="`0 0 ${VIEW} ${VIEW}`" class="h-auto w-full">
            <circle
                v-if="monthly.length"
                :cx="CENTER"
                :cy="CENTER"
                :r="ORBIT_MONTHLY"
                fill="none"
                stroke="#CBD5E1"
                stroke-width="1"
                opacity="0.35"
            />
            <circle
                v-if="yearly.length"
                :cx="CENTER"
                :cy="CENTER"
                :r="ORBIT_YEARLY"
                fill="none"
                stroke="#CBD5E1"
                stroke-width="1"
                opacity="0.35"
            />

            <g>
                <circle :cx="CENTER" :cy="CENTER" r="48" fill="#FCD34D" />
                <clipPath id="orbit-sun-clip">
                    <circle :cx="CENTER" :cy="CENTER" r="42" />
                </clipPath>
                <image
                    v-if="user?.avatar"
                    :href="user.avatar"
                    :x="CENTER - 42"
                    :y="CENTER - 42"
                    width="84"
                    height="84"
                    clip-path="url(#orbit-sun-clip)"
                    preserveAspectRatio="xMidYMid slice"
                />
                <text
                    v-else
                    :x="CENTER"
                    :y="CENTER"
                    text-anchor="middle"
                    dominant-baseline="central"
                    font-size="36"
                    fill="#7C2D12"
                    font-weight="700"
                >
                    {{ sunInitial }}
                </text>
            </g>

            <Planet
                v-for="p in planets"
                :key="p.sub.id"
                :cx="p.x"
                :cy="p.y"
                :radius="p.radius"
                :color="p.color"
                :text-color="p.textColor"
                :letter="p.letter"
                :status="p.sub.status"
                :urgent="p.urgent"
            />
        </svg>
    </div>
</template>
```

- [ ] **Step 3: Wire Orbit into Dashboard.vue**

Replace the entire contents of `resources/js/Pages/Dashboard.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Orbit from '@/orbit/Orbit.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    subscriptions: { type: Array, default: () => [] },
});

const user = computed(() => usePage().props.auth.user);
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Vũ trụ của bạn
                </h2>
                <Link
                    :href="route('subscriptions.index')"
                    class="text-sm text-indigo-600 hover:underline"
                >
                    Quản lý danh sách
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <div
                    v-if="subscriptions.length === 0"
                    class="rounded-lg bg-white p-12 text-center shadow-sm"
                >
                    <p class="text-gray-600">
                        Chưa có dịch vụ nào trong vũ trụ của bạn.
                    </p>
                    <Link
                        :href="route('subscriptions.create')"
                        class="mt-4 inline-block rounded-md bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-500"
                    >
                        Thêm dịch vụ đầu tiên
                    </Link>
                </div>

                <div
                    v-else
                    class="rounded-lg bg-gradient-to-b from-slate-900 to-slate-800 p-4 shadow-sm"
                >
                    <Orbit :subscriptions="subscriptions" :user="user" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 4: Verify the build compiles**

Run: `npm run build`
Expected: PASS — build completes, `Dashboard`, `Orbit`, `Planet` chunks emitted, no errors.

- [ ] **Step 5: Verify existing suites still pass**

Run: `npm run test:js && php artisan test`
Expected: PASS — JS unit tests + full PHPUnit suite green.

- [ ] **Step 6: Manual verification (record in report)**

With `php artisan serve` + `npm run dev` and a seeded/logged-in user, confirm:
- Sun shows the user's avatar (or initial) at center.
- Monthly planets sit on the inner ring, yearly on the outer ring, evenly spaced.
- Planet sizes vary with amount; the cheapest is still clearly visible (≥ R_MIN).
- A `pending_cancel` planet shows the amber dashed ring; a planet due within 7 days pulses.
- The whole scene drifts slowly (and holds still if the OS "reduce motion" setting is on).
- With no subscriptions, the empty-state invite renders instead.

- [ ] **Step 7: Commit**

```bash
git add resources/js/orbit/Planet.vue resources/js/orbit/Orbit.vue resources/js/Pages/Dashboard.vue
git commit -m "Update: render the subscription orbit on the dashboard

Draw the solar-system scene: sun (user avatar/initial), monthly and
yearly orbit rings, evenly-distributed planets sized by amount and
colored by brand, with an amber dashed ring for pending_cancel and a
pulsing glow for renewals due within 7 days. The scene drifts gently
and respects prefers-reduced-motion. Dashboard shows an empty-state
invite when there are no subscriptions.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 5: Interaction — hover tooltip + click detail panel

**Files:**
- Create: `resources/js/orbit/PlanetTooltip.vue`
- Create: `resources/js/orbit/OrbitDetailPanel.vue`
- Modify: `resources/js/orbit/Orbit.vue`

**Interfaces:**
- Consumes: Task 4 `Orbit.vue` planet objects (`{ sub, x, y, radius, color, days, ... }`) and Planet's `hover`/`leave`/`select` events; SP1 routes `subscriptions.edit` and `subscriptions.destroy`.
- Produces:
  - `PlanetTooltip.vue` — props `{ sub: Object, days: Number, leftPct: Number, topPct: Number }`; HTML overlay near the planet.
  - `OrbitDetailPanel.vue` — props `{ sub: Object }`; emits `close`; contains Edit link + Delete form.

- [ ] **Step 1: Create PlanetTooltip.vue**

Create `resources/js/orbit/PlanetTooltip.vue`:

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
    sub: { type: Object, required: true },
    days: { type: Number, required: true },
    leftPct: { type: Number, required: true },
    topPct: { type: Number, required: true },
});

const vnd = computed(() =>
    new Intl.NumberFormat('vi-VN').format(props.sub.amount_vnd),
);

const dueLabel = computed(() => {
    if (props.days < 0) return `Quá hạn ${Math.abs(props.days)} ngày`;
    if (props.days === 0) return 'Tới hạn hôm nay';
    return `Còn ${props.days} ngày`;
});
</script>

<template>
    <div
        class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full rounded-md bg-slate-900/95 px-3 py-2 text-xs text-white shadow-lg ring-1 ring-white/10"
        :style="{ left: `${leftPct}%`, top: `${topPct}%` }"
    >
        <div class="font-semibold">{{ sub.name }}</div>
        <div>{{ sub.amount }} {{ sub.currency }} (≈ {{ vnd }} ₫)</div>
        <div>{{ sub.next_renewal_date?.slice(0, 10) }} · {{ dueLabel }}</div>
    </div>
</template>
```

- [ ] **Step 2: Create OrbitDetailPanel.vue**

Create `resources/js/orbit/OrbitDetailPanel.vue`:

```vue
<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    sub: { type: Object, required: true },
});

defineEmits(['close']);

const vnd = computed(() =>
    new Intl.NumberFormat('vi-VN').format(props.sub.amount_vnd),
);

const statusLabel = {
    active: 'Đang hoạt động',
    pending_cancel: 'Sắp hủy',
    cancelled: 'Đã hủy',
};

const form = useForm({});

function destroy() {
    if (!confirm(`Xóa "${props.sub.name}"?`)) return;
    form.delete(route('subscriptions.destroy', props.sub.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div
        class="absolute right-0 top-0 z-20 flex h-full w-72 max-w-full flex-col gap-4 rounded-l-lg bg-white p-5 shadow-xl"
    >
        <div class="flex items-start justify-between">
            <h3 class="text-lg font-semibold text-gray-900">{{ sub.name }}</h3>
            <button
                type="button"
                class="text-gray-400 hover:text-gray-600"
                @click="$emit('close')"
            >
                ✕
            </button>
        </div>

        <dl class="space-y-2 text-sm text-gray-700">
            <div class="flex justify-between">
                <dt class="text-gray-500">Số tiền</dt>
                <dd>{{ sub.amount }} {{ sub.currency }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">Quy đổi</dt>
                <dd>{{ vnd }} ₫</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">Chu kỳ</dt>
                <dd>{{ sub.billing_cycle === 'yearly' ? 'Hàng năm' : 'Hàng tháng' }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">Gia hạn</dt>
                <dd>{{ sub.next_renewal_date?.slice(0, 10) }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">Trạng thái</dt>
                <dd>{{ statusLabel[sub.status] ?? sub.status }}</dd>
            </div>
        </dl>

        <div class="mt-auto flex gap-2">
            <Link
                :href="route('subscriptions.edit', sub.id)"
                class="flex-1 rounded-md bg-indigo-600 px-3 py-2 text-center text-sm text-white hover:bg-indigo-500"
            >
                Sửa
            </Link>
            <button
                type="button"
                class="flex-1 rounded-md bg-red-600 px-3 py-2 text-sm text-white hover:bg-red-500 disabled:opacity-50"
                :disabled="form.processing"
                @click="destroy"
            >
                Xóa
            </button>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Wire interaction into Orbit.vue**

In `resources/js/orbit/Orbit.vue`, update the `<script setup>`: add the two imports and the hover/select state.

Add imports below the existing component/module imports:

```javascript
import PlanetTooltip from './PlanetTooltip.vue';
import OrbitDetailPanel from './OrbitDetailPanel.vue';
```

Add state + derived selections (after `const planets = computed(...)`):

```javascript
const hoveredId = ref(null);
const selectedId = ref(null);

const hoveredPlanet = computed(
    () => planets.value.find((p) => p.sub.id === hoveredId.value) ?? null,
);
const selectedSub = computed(
    () => props.subscriptions.find((s) => s.id === selectedId.value) ?? null,
);
```

Update the `<Planet>` element in the template to bind events:

```vue
<Planet
    v-for="p in planets"
    :key="p.sub.id"
    :cx="p.x"
    :cy="p.y"
    :radius="p.radius"
    :color="p.color"
    :text-color="p.textColor"
    :letter="p.letter"
    :status="p.sub.status"
    :urgent="p.urgent"
    @hover="hoveredId = p.sub.id"
    @leave="hoveredId = null"
    @select="selectedId = p.sub.id"
/>
```

Add the tooltip + panel just before the closing `</div>` of the relative wrapper (after `</svg>`):

```vue
<PlanetTooltip
    v-if="hoveredPlanet"
    :sub="hoveredPlanet.sub"
    :days="hoveredPlanet.days"
    :left-pct="(hoveredPlanet.x / VIEW) * 100"
    :top-pct="(hoveredPlanet.y / VIEW) * 100"
/>

<OrbitDetailPanel
    v-if="selectedSub"
    :sub="selectedSub"
    @close="selectedId = null"
/>
```

- [ ] **Step 4: Verify the build compiles**

Run: `npm run build`
Expected: PASS — build completes with the new components, no errors.

- [ ] **Step 5: Verify suites still pass**

Run: `npm run test:js && php artisan test`
Expected: PASS — JS unit + full PHPUnit suite green.

- [ ] **Step 6: Manual verification (record in report)**

With the app running and a logged-in seeded user, confirm:
- Hovering a planet shows a tooltip with name, amount + VND, renewal date, and "Còn N ngày" (or "Quá hạn…").
- The tooltip tracks the planet as it drifts.
- Clicking a planet opens the detail panel; "Sửa" navigates to the edit form, "Xóa" prompts and deletes (then the planet disappears after redirect).
- The panel's ✕ closes it.

- [ ] **Step 7: Commit**

```bash
git add resources/js/orbit/PlanetTooltip.vue resources/js/orbit/OrbitDetailPanel.vue resources/js/orbit/Orbit.vue
git commit -m "Update: add hover tooltip and click detail panel to the orbit

Hovering a planet shows a tooltip (name, amount, VND, renewal, days
left); clicking opens a side panel with full details plus Edit and
Delete actions wired to the existing SP1 CRUD routes. Tooltip and
panel derive from live planet state so the tooltip tracks drift.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Definition of Done

- `/dashboard` renders the SVG universe: sun = user avatar/initial, two orbit rings, evenly-distributed drifting planets.
- Planets encode data correctly: size by `amount_vnd` (never below R_MIN), brand color + initial, amber dashed ring for `pending_cancel`, pulsing glow when ≤ 7 days; `cancelled` never appears.
- Hover shows a tooltip; click opens a detail panel with working Edit/Delete via SP1 CRUD.
- Empty state shows when the user has no (non-cancelled) subscriptions.
- All data scoped to the authenticated user.
- Vitest suite (layout + brandColors) and the full PHPUnit suite pass; `npm run build` succeeds; `prefers-reduced-motion` disables animation.
```
