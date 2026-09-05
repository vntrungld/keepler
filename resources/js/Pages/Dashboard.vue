<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Orbit from '@/orbit/Orbit.vue';
import SubscriptionList from '@/orbit/SubscriptionList.vue';
import { annualizedVnd } from '@/orbit/layout.js';
import { formatVnd } from '@/orbit/labels.js';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    subscriptions: { type: Array, default: () => [] },
    gmail_connected: { type: Boolean, default: false },
});

const user = computed(() => usePage().props.auth.user);

// Background scan + polling state.
const scanning = ref(false);
const scanError = ref(null);
const processed = ref(0);
const total = ref(0);
const percent = ref(0);
let pollTimer = null;

const listFilter = ref('all');
const sortMode = ref('active');

const filteredSubscriptions = computed(() => {
    let list = props.subscriptions;
    if (listFilter.value !== 'all') {
        list = list.filter((s) => s.list === listFilter.value);
    }
    list = [...list];
    if (sortMode.value === 'next') {
        list.sort((a, b) => a.next_renewal_date.localeCompare(b.next_renewal_date));
    } else {
        const order = { active: 0, pending_cancel: 1, cancelled: 2 };
        list.sort((a, b) => (order[a.status] ?? 3) - (order[b.status] ?? 3));
    }
    return list;
});

const totalYearlyVnd = computed(() =>
    filteredSubscriptions.value.reduce((sum, s) => sum + annualizedVnd(s), 0),
);

function resetScanState() {
    scanError.value = null;
    processed.value = 0;
    total.value = 0;
    percent.value = 0;
}

async function startScan() {
    scanning.value = true;
    resetScanState();

    try {
        const { data } = await axios.post(route('gmail.scans.store'));
        pollScan(data.id);
    } catch (e) {
        if (e.response?.status === 409 && e.response.data?.connect_url) {
            window.location.href = e.response.data.connect_url;
            return;
        }
        scanError.value = 'Không bắt đầu quét được. Vui lòng thử lại.';
    }
}

function pollScan(id) {
    pollTimer = setInterval(async () => {
        try {
            const { data } = await axios.get(route('gmail.scans.show', id));
            processed.value = data.processed;
            total.value = data.total;
            percent.value = data.percent;

            if (data.status === 'done') {
                stopPolling();
                router.visit(route('gmail.scans.results', id));
            } else if (data.status === 'failed') {
                stopPolling();
                scanError.value =
                    'Quét Gmail thất bại. Vui lòng thử kết nối lại và quét lại.';
            }
        } catch (e) {
            stopPolling();
            scanError.value = 'Mất kết nối khi theo dõi tiến trình.';
        }
    }, 1000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function closeScan() {
    stopPolling();
    scanning.value = false;
}

onBeforeUnmount(stopPolling);
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-extrabold tracking-tight text-white">
                    Vũ trụ của bạn
                </h2>
                <div class="flex items-center gap-3 text-sm">
                    <button
                        v-if="gmail_connected"
                        type="button"
                        :disabled="scanning"
                        class="rounded-lg bg-violet-600 px-3 py-2 text-white hover:bg-violet-500 disabled:opacity-60"
                        @click="startScan"
                    >
                        {{ scanning ? 'Đang quét…' : 'Quét Gmail' }}
                    </button>
                    <a
                        v-else
                        :href="route('gmail.connect')"
                        class="rounded-lg bg-violet-600 px-3 py-2 text-white hover:bg-violet-500"
                    >
                        Kết nối Gmail
                    </a>
                    <Link
                        v-if="gmail_connected"
                        :href="route('gmail.disconnect')"
                        method="delete"
                        as="button"
                        class="text-slate-500 hover:text-slate-300 hover:underline"
                    >
                        Ngắt kết nối
                    </Link>
                    <Link
                        :href="route('subscriptions.index')"
                        class="text-violet-400 hover:text-violet-300 hover:underline"
                    >
                        Quản lý danh sách
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div
                    v-if="subscriptions.length === 0"
                    class="rounded-2xl border border-white/5 bg-midnight-900 p-12 text-center shadow-lg shadow-black/20"
                >
                    <p class="text-slate-400">
                        Chưa có dịch vụ nào trong vũ trụ của bạn.
                    </p>
                    <Link
                        :href="route('subscriptions.create')"
                        class="mt-4 inline-block rounded-lg bg-violet-600 px-4 py-2 text-white hover:bg-violet-500"
                    >
                        Thêm dịch vụ đầu tiên
                    </Link>
                </div>

                <template v-else>
                    <div class="relative rounded-2xl border border-white/5 bg-gradient-to-b from-midnight-800 to-midnight-950 p-4 shadow-lg shadow-black/30">
                        <Link
                            :href="route('subscriptions.create')"
                            class="absolute right-4 top-4 z-10 flex h-10 w-10 items-center justify-center rounded-full bg-violet-600 text-white shadow-lg hover:bg-violet-500"
                            aria-label="Thêm dịch vụ"
                        >
                            +
                        </Link>
                        <Orbit :subscriptions="subscriptions" :user="user" />
                    </div>

                    <div class="mt-6 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-extrabold text-white">{{ filteredSubscriptions.length }}</span>
                            <select
                                v-model="listFilter"
                                class="rounded-lg border border-white/10 bg-midnight-800 px-2 py-1 text-sm text-slate-300 focus:border-violet-500 focus:ring-violet-500"
                            >
                                <option value="all">Tất cả</option>
                                <option value="personal">Cá nhân</option>
                                <option value="business">Công việc</option>
                                <option value="family">Gia đình</option>
                            </select>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold text-white">{{ formatVnd(totalYearlyVnd) }} ₫</p>
                            <p class="text-xs text-slate-500">Tổng chi phí/năm</p>
                        </div>
                    </div>

                    <div class="mt-4 flex gap-2 text-sm">
                        <button
                            type="button"
                            class="rounded-full px-3 py-1"
                            :class="sortMode === 'active' ? 'bg-violet-600 text-white' : 'bg-midnight-800 text-slate-400'"
                            @click="sortMode = 'active'"
                        >
                            Hoạt động
                        </button>
                        <button
                            type="button"
                            class="rounded-full px-3 py-1"
                            :class="sortMode === 'next' ? 'bg-violet-600 text-white' : 'bg-midnight-800 text-slate-400'"
                            @click="sortMode = 'next'"
                        >
                            Sắp tới
                        </button>
                    </div>

                    <SubscriptionList :subscriptions="filteredSubscriptions" class="mt-4" />
                </template>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="scanning"
                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm"
            >
                <div
                    class="mx-4 flex w-full max-w-sm flex-col gap-4 rounded-2xl border border-white/10 bg-midnight-900 p-8 shadow-2xl"
                >
                    <template v-if="!scanError">
                        <div class="flex items-center gap-3">
                            <span
                                class="h-6 w-6 shrink-0 animate-spin rounded-full border-4 border-violet-500 border-t-transparent"
                            ></span>
                            <p class="text-lg font-semibold text-white">
                                Đang quét Gmail…
                            </p>
                        </div>

                        <div>
                            <div class="h-2.5 w-full overflow-hidden rounded-full bg-midnight-800">
                                <div
                                    class="h-full rounded-full bg-violet-500 transition-all duration-300"
                                    :style="{ width: percent + '%' }"
                                ></div>
                            </div>
                            <p class="mt-2 text-center text-sm text-slate-500">
                                <template v-if="total > 0">
                                    Đang xử lý {{ processed }}/{{ total }} email — {{ percent }}%
                                </template>
                                <template v-else>
                                    Đang tìm email hóa đơn…
                                </template>
                            </p>
                        </div>
                    </template>

                    <template v-else>
                        <p class="text-lg font-semibold text-white">Quét thất bại</p>
                        <p class="text-sm text-slate-400">{{ scanError }}</p>
                        <div class="flex justify-end gap-2">
                            <button
                                type="button"
                                class="rounded-lg px-3 py-2 text-sm text-slate-400 hover:underline"
                                @click="closeScan"
                            >
                                Đóng
                            </button>
                            <button
                                type="button"
                                class="rounded-lg bg-violet-600 px-3 py-2 text-sm text-white hover:bg-violet-500"
                                @click="startScan"
                            >
                                Thử lại
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
