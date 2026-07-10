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
import PlanetTooltip from './PlanetTooltip.vue';
import OrbitDetailPanel from './OrbitDetailPanel.vue';

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

const hoveredId = ref(null);
const selectedId = ref(null);

const hoveredPlanet = computed(
    () => planets.value.find((p) => p.sub.id === hoveredId.value) ?? null,
);
const selectedSub = computed(
    () => props.subscriptions.find((s) => s.id === selectedId.value) ?? null,
);

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
                @hover="hoveredId = p.sub.id"
                @leave="hoveredId = null"
                @select="selectedId = p.sub.id"
            />
        </svg>

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
    </div>
</template>
