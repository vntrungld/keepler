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
