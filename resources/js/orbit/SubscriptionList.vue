<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { statusLabel, cycleLabel, formatVnd } from './labels.js';

defineProps({
    subscriptions: { type: Array, required: true },
});

const statusClass = {
    active: 'bg-emerald-100 text-emerald-700',
    pending_cancel: 'bg-amber-100 text-amber-700',
    cancelled: 'bg-gray-100 text-gray-600',
};

const deletingId = ref(null);

function meta(sub) {
    const amount = `${sub.amount} ${sub.currency}`;
    const converted =
        sub.currency !== 'VND' ? ` (${formatVnd(sub.amount_vnd)} ₫)` : '';
    const cycle = cycleLabel[sub.billing_cycle] ?? sub.billing_cycle;
    const renewal = sub.next_renewal_date?.slice(0, 10);

    return `${amount}${converted} · ${cycle} · gia hạn ${renewal}`;
}

function destroy(sub) {
    if (!confirm(`Xóa "${sub.name}"?`)) return;

    deletingId.value = sub.id;
    router.delete(route('subscriptions.destroy', sub.id), {
        preserveScroll: true,
        onFinish: () => (deletingId.value = null),
    });
}
</script>

<template>
    <ul class="space-y-3">
        <li
            v-for="sub in subscriptions"
            :key="sub.id"
            class="rounded-lg bg-white p-4 shadow-sm"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate font-semibold text-gray-900">{{ sub.name }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ meta(sub) }}</p>
                </div>
                <span
                    class="shrink-0 rounded-full px-2 py-1 text-xs font-medium"
                    :class="statusClass[sub.status] ?? statusClass.cancelled"
                >
                    {{ statusLabel[sub.status] ?? sub.status }}
                </span>
            </div>

            <div class="mt-3 flex gap-2">
                <Link
                    :href="route('subscriptions.edit', sub.id)"
                    class="flex-1 rounded-md bg-indigo-600 px-3 py-2 text-center text-sm text-white hover:bg-indigo-500"
                >
                    Sửa
                </Link>
                <button
                    type="button"
                    class="flex-1 rounded-md bg-red-600 px-3 py-2 text-sm text-white hover:bg-red-500 disabled:opacity-50"
                    :disabled="deletingId === sub.id"
                    @click="destroy(sub)"
                >
                    Xóa
                </button>
            </div>
        </li>
    </ul>
</template>
