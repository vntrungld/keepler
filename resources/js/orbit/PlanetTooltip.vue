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
