<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GmailScanProgress from '@/orbit/GmailScanProgress.vue';
import Orbit from '@/orbit/Orbit.vue';
import SubscriptionList from '@/orbit/SubscriptionList.vue';
import { annualizedVnd } from '@/orbit/layout.js';
import { formatVnd } from '@/orbit/labels.js';
import { useGmailScan } from '@/orbit/useGmailScan.js';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    subscriptions: { type: Array, default: () => [] },
    gmail_connected: { type: Boolean, default: false },
});

const user = computed(() => usePage().props.auth.user);

const { scanning, scanError, processed, total, percent, startScan, closeScan } = useGmailScan();

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

</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout title="Vũ trụ của bạn">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-extrabold tracking-tight text-white">
                    Vũ trụ của bạn
                </h2>
                <Link
                    :href="route('subscriptions.index')"
                    class="text-sm text-violet-400 hover:text-violet-300 hover:underline"
                >
                    Quản lý danh sách
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div
                    v-if="subscriptions.length === 0"
                    class="rounded-2xl border border-white/5 bg-midnight-900 p-6 shadow-lg shadow-black/20 sm:p-10"
                >
                    <p class="text-center text-xl font-extrabold tracking-tight text-white">
                        Bắt đầu từ đâu?
                    </p>
                    <p class="mx-auto mt-2 max-w-md text-center text-sm text-slate-400">
                        Keepler có thể tự tìm các dịch vụ bạn đang trả tiền từ email hóa
                        đơn trong Gmail, hoặc bạn tự thêm từng dịch vụ.
                    </p>

                    <div class="mt-8 grid gap-3 sm:grid-cols-2">
                        <button
                            v-if="gmail_connected"
                            type="button"
                            :disabled="scanning"
                            class="flex flex-col gap-1 rounded-2xl bg-violet-600 p-5 text-left text-white transition hover:bg-violet-500 disabled:opacity-60"
                            @click="startScan"
                        >
                            <span class="font-semibold">Bắt đầu quét</span>
                            <span class="text-sm text-violet-200">
                                Gmail đã kết nối — tìm hóa đơn ngay.
                            </span>
                        </button>
                        <a
                            v-else
                            :href="route('gmail.connect')"
                            class="flex flex-col gap-1 rounded-2xl bg-violet-600 p-5 text-white transition hover:bg-violet-500"
                        >
                            <span class="font-semibold">Quét Gmail</span>
                            <span class="text-sm text-violet-200">
                                Tự tìm dịch vụ từ email hóa đơn.
                            </span>
                        </a>

                        <Link
                            :href="route('subscriptions.create')"
                            class="flex flex-col gap-1 rounded-2xl border border-white/10 bg-midnight-800 p-5 transition hover:bg-midnight-700"
                        >
                            <span class="font-semibold text-slate-100">Nhập tay</span>
                            <span class="text-sm text-slate-400">
                                Thêm dịch vụ đầu tiên của bạn.
                            </span>
                        </Link>
                    </div>

                    <p class="mt-6 text-center text-xs text-slate-500">
                        Keepler chỉ xin quyền <span class="text-slate-400">đọc</span> Gmail
                        và chỉ đọc email hóa đơn. Bạn ngắt kết nối bất cứ lúc nào trong
                        Cài đặt.
                    </p>
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

        <GmailScanProgress
            v-if="scanning"
            :scan-error="scanError"
            :processed="processed"
            :total="total"
            :percent="percent"
            @close="closeScan"
            @retry="startScan"
        />

    </AuthenticatedLayout>
</template>
