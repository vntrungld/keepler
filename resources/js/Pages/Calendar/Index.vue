<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrandIcon from '@/orbit/BrandIcon.vue';
import { formatVnd } from '@/orbit/labels.js';
import { projectOccurrences, groupByDate, monthTotals, dayLogos } from '@/orbit/calendar.js';
import { todayLocal } from '@/orbit/layout.js';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({ subscriptions: { type: Array, default: () => [] } });

const today = todayLocal();
const now = new Date();
const viewedYear = ref(now.getFullYear());
const viewedMonth = ref(now.getMonth()); // 0-indexed

const monthNames = [
    'Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6',
    'Tháng 7', 'Tháng 8', 'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12',
];

const occurrences = computed(() => projectOccurrences(props.subscriptions, viewedYear.value, viewedMonth.value));
const grouped = computed(() => groupByDate(occurrences.value));
const totals = computed(() => monthTotals(occurrences.value, today));

const weeks = computed(() => {
    const firstOfMonth = new Date(Date.UTC(viewedYear.value, viewedMonth.value, 1));
    const daysInMonth = new Date(Date.UTC(viewedYear.value, viewedMonth.value + 1, 0)).getUTCDate();
    // Monday-first weekday index (0 = Monday .. 6 = Sunday).
    const leadingBlanks = (firstOfMonth.getUTCDay() + 6) % 7;

    const cells = [];
    for (let i = 0; i < leadingBlanks; i++) cells.push(null);
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = new Date(Date.UTC(viewedYear.value, viewedMonth.value, day)).toISOString().slice(0, 10);
        const occurrences = grouped.value[dateStr] ?? [];
        cells.push({ day, dateStr, occurrences, logos: dayLogos(occurrences) });
    }
    while (cells.length % 7 !== 0) cells.push(null);

    const rows = [];
    for (let i = 0; i < cells.length; i += 7) rows.push(cells.slice(i, i + 7));
    return rows;
});

// Grid cells are ~50px wide on a phone but ~95px on the sm+ layout, so the
// day's logo row scales with the breakpoint rather than leaving wide cells
// sparse or overflowing narrow ones.
const wideViewport = ref(false);
const logoSize = computed(() => (wideViewport.value ? 20 : 14));

let viewportQuery = null;
function syncViewport(event) {
    wideViewport.value = event.matches;
}

onMounted(() => {
    viewportQuery = window.matchMedia('(min-width: 640px)');
    wideViewport.value = viewportQuery.matches;
    viewportQuery.addEventListener('change', syncViewport);
});

onBeforeUnmount(() => {
    viewportQuery?.removeEventListener('change', syncViewport);
});

const selectedDate = ref(null);
const selectedOccurrences = computed(() => selectedDate.value ? (grouped.value[selectedDate.value] ?? []) : []);

function goToMonth(delta) {
    let month = viewedMonth.value + delta;
    let year = viewedYear.value;
    if (month < 0) { month = 11; year -= 1; }
    if (month > 11) { month = 0; year += 1; }
    viewedMonth.value = month;
    viewedYear.value = year;
    selectedDate.value = null;
}

function goToToday() {
    viewedYear.value = now.getFullYear();
    viewedMonth.value = now.getMonth();
    selectedDate.value = null;
}
</script>

<template>
    <Head title="Lịch gia hạn" />

    <AuthenticatedLayout title="Lịch gia hạn">
        <template #header>
            <h2 class="text-2xl font-extrabold tracking-tight text-white">Lịch gia hạn</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xl font-extrabold text-white">{{ monthNames[viewedMonth] }}/{{ viewedYear }}</p>
                        <p class="text-sm text-slate-400">
                            Tổng: {{ formatVnd(totals.total) }} ₫ · Sắp tới: {{ formatVnd(totals.upcoming) }} ₫
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded-lg bg-midnight-800 px-3 py-1.5 text-slate-300 hover:bg-midnight-700" @click="goToMonth(-1)">‹</button>
                        <button type="button" class="rounded-lg bg-midnight-800 px-3 py-1.5 text-sm text-slate-300 hover:bg-midnight-700" @click="goToToday">Hôm nay</button>
                        <button type="button" class="rounded-lg bg-midnight-800 px-3 py-1.5 text-slate-300 hover:bg-midnight-700" @click="goToMonth(1)">›</button>
                    </div>
                </div>

                <div class="overflow-hidden rounded-2xl border border-white/5 bg-midnight-900 shadow-lg shadow-black/20">
                    <div class="grid grid-cols-7 border-b border-white/5 text-center text-xs font-semibold text-slate-500">
                        <div v-for="d in ['T2','T3','T4','T5','T6','T7','CN']" :key="d" class="py-2">{{ d }}</div>
                    </div>
                    <div v-for="(week, wi) in weeks" :key="wi" class="grid grid-cols-7">
                        <button
                            v-for="(cell, ci) in week"
                            :key="ci"
                            type="button"
                            class="flex h-16 flex-col items-center justify-center gap-1 border-b border-r border-white/5 text-sm sm:h-20"
                            :class="[
                                !cell && 'bg-midnight-950/40',
                                cell?.dateStr === today && 'bg-violet-500/10',
                                cell?.dateStr === selectedDate && 'ring-2 ring-inset ring-violet-500',
                            ]"
                            :disabled="!cell || cell.occurrences.length === 0"
                            @click="selectedDate = cell.dateStr"
                        >
                            <template v-if="cell">
                                <span class="leading-none text-slate-300">{{ cell.day }}</span>
                                <span v-if="cell.occurrences.length" class="flex items-center gap-0.5">
                                    <BrandIcon
                                        v-for="sub in cell.logos.shown"
                                        :key="sub.id"
                                        :name="sub.name"
                                        :size="logoSize"
                                    />
                                    <span
                                        v-if="cell.logos.overflow"
                                        class="inline-flex h-3.5 min-w-[0.875rem] items-center justify-center rounded-full bg-midnight-700 px-0.5 text-[9px] font-bold leading-none text-slate-300 sm:h-5 sm:min-w-[1.25rem] sm:px-1 sm:text-[11px]"
                                    >
                                        +{{ cell.logos.overflow }}
                                    </span>
                                </span>
                                <span v-else class="h-3.5 sm:h-5" />
                            </template>
                        </button>
                    </div>
                </div>

                <div v-if="selectedOccurrences.length" class="space-y-2">
                    <p class="text-sm text-slate-500">{{ selectedDate }}</p>
                    <Link
                        v-for="occ in selectedOccurrences"
                        :key="occ.subscription.id"
                        :href="route('subscriptions.show', occ.subscription.id)"
                        class="flex items-center gap-3 rounded-2xl border border-white/5 bg-midnight-900 p-3 shadow-lg shadow-black/10"
                    >
                        <BrandIcon :name="occ.subscription.name" :size="32" />
                        <span class="flex-1 text-slate-100">{{ occ.subscription.name }}</span>
                        <span class="text-slate-400">{{ formatVnd(occ.subscription.amount_vnd) }} ₫</span>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
