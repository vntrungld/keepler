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
            aria-hidden="true"
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
