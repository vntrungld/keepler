<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="rounded-lg border-white/10 bg-midnight-800 text-slate-100 shadow-sm placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500"
        v-model="model"
        ref="input"
    />
</template>
