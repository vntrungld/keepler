<script setup>
import { Link } from '@inertiajs/vue3';
import BrandIcon from './BrandIcon.vue';
import { statusLabel, endedLabel, periodsLabel, formatVnd } from './labels.js';
import { daysUntil, todayLocal } from './layout.js';

defineProps({
    subscriptions: { type: Array, required: true },
});

const statusClass = {
    active: 'bg-emerald-500/15 text-emerald-300',
    pending_cancel: 'bg-amber-500/15 text-amber-300',
    cancelled: 'bg-slate-500/15 text-slate-400',
};

const today = todayLocal();

function renewsInLabel(sub) {
    // A finished plan has no next billing — its date field is frozen on the
    // last one, so counting days to it would read as months overdue.
    if (sub.has_ended) return periodsLabel(sub);

    const days = daysUntil(sub.next_renewal_date, today);
    const date = sub.next_renewal_date?.slice(0, 10);
    if (days < 0) return `Quá hạn ${Math.abs(days)} ngày · ${date}`;

    const periods = periodsLabel(sub);
    const suffix = periods ? ` · ${periods}` : '';

    if (days === 0) return `Tới hạn hôm nay · ${date}${suffix}`;
    return `Còn ${days} ngày · ${date}${suffix}`;
}

function priceLabel(sub) {
    if (sub.currency === 'VND') return `${formatVnd(sub.amount_vnd)} ₫`;
    return `${sub.amount} ${sub.currency}`;
}
</script>

<template>
    <ul class="space-y-3">
        <li v-for="sub in subscriptions" :key="sub.id">
            <Link
                :href="route('subscriptions.show', sub.id)"
                class="flex items-center gap-2 rounded-2xl border border-white/5 bg-midnight-900 p-4 shadow-lg shadow-black/10 transition hover:border-white/10 sm:gap-3"
            >
                <BrandIcon :name="sub.name" :size="40" />

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <p class="truncate font-semibold text-slate-100">{{ sub.name }}</p>
                        <!--
                            Only flag the states that need attention. An active
                            subscription is the norm, and its badge would squeeze
                            the name into an ellipsis on narrow screens.
                        -->
                        <span
                            v-if="sub.has_ended"
                            class="shrink-0 rounded-full bg-slate-500/15 px-2 py-0.5 text-xs font-medium text-slate-400"
                        >
                            {{ endedLabel }}
                        </span>
                        <span
                            v-else-if="sub.status !== 'active'"
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="statusClass[sub.status] ?? statusClass.cancelled"
                        >
                            {{ statusLabel[sub.status] ?? sub.status }}
                        </span>
                    </div>
                    <p class="mt-1 truncate text-xs text-slate-500">{{ renewsInLabel(sub) }}</p>
                </div>

                <div class="shrink-0 text-right">
                    <p class="font-semibold text-slate-100">{{ priceLabel(sub) }}</p>
                </div>

                <svg class="h-5 w-5 shrink-0 text-slate-600" viewBox="0 0 20 20" fill="currentColor">
                    <path
                        fill-rule="evenodd"
                        d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                        clip-rule="evenodd"
                    />
                </svg>
            </Link>
        </li>
    </ul>
</template>
