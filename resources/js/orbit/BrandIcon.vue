<script setup>
import { computed, ref, watch } from 'vue';
import { resolveBrandIcon } from './brandIcon.js';
import { brandColor, brandDomain, contrastText, initial } from './brandColors.js';

const props = defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 32 },
});

const icon = computed(() => resolveBrandIcon(props.name));
const color = computed(() => brandColor(props.name));
const textColor = computed(() => contrastText(color.value));

// Roughly a third of the catalog has no bundled simple-icons glyph (Adobe,
// Amazon, Disney+, Microsoft and friends were pulled for trademark reasons),
// so those fall back to the site's own favicon before the letter avatar.
const faviconFailed = ref(false);
watch(() => props.name, () => (faviconFailed.value = false));

const faviconUrl = computed(() => {
    if (icon.value || faviconFailed.value) return null;

    const domain = brandDomain(props.name);
    if (!domain) return null;

    return `https://www.google.com/s2/favicons?domain=${domain}&sz=64`;
});

// Keep the fallback letter proportional so the same component reads well at
// the 14-20px sizes used inside calendar cells and at the 32px+ list sizes.
const initialFontSize = computed(() => `${Math.round(props.size * 0.45)}px`);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full"
        :style="{
            width: size + 'px',
            height: size + 'px',
            backgroundColor: icon || faviconUrl ? '#fff' : color,
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
        <img
            v-else-if="faviconUrl"
            :src="faviconUrl"
            :alt="name"
            loading="lazy"
            referrerpolicy="no-referrer"
            :width="Math.round(size * 0.6)"
            :height="Math.round(size * 0.6)"
            @error="faviconFailed = true"
        />
        <span v-else class="font-bold leading-none" :style="{ color: textColor, fontSize: initialFontSize }">
            {{ initial(name) }}
        </span>
    </span>
</template>
