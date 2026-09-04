<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-lg border border-white/10 bg-midnight-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-slate-200 shadow-sm transition duration-150 ease-in-out hover:bg-midnight-700 focus:outline-none focus:ring-2 focus:ring-violet-400 focus:ring-offset-2 focus:ring-offset-midnight-950 disabled:opacity-25"
    >
        <slot />
    </button>
</template>
