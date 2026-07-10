<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Orbit from '@/orbit/Orbit.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import axios from 'axios';

defineProps({
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
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Vũ trụ của bạn
                </h2>
                <div class="flex items-center gap-3 text-sm">
                    <button
                        v-if="gmail_connected"
                        type="button"
                        :disabled="scanning"
                        class="rounded-md bg-emerald-600 px-3 py-2 text-white hover:bg-emerald-500 disabled:opacity-60"
                        @click="startScan"
                    >
                        {{ scanning ? 'Đang quét…' : 'Quét Gmail' }}
                    </button>
                    <a
                        v-else
                        :href="route('gmail.connect')"
                        class="rounded-md bg-emerald-600 px-3 py-2 text-white hover:bg-emerald-500"
                    >
                        Kết nối Gmail
                    </a>
                    <Link
                        v-if="gmail_connected"
                        :href="route('gmail.disconnect')"
                        method="delete"
                        as="button"
                        class="text-gray-500 hover:underline"
                    >
                        Ngắt kết nối
                    </Link>
                    <Link
                        :href="route('subscriptions.index')"
                        class="text-indigo-600 hover:underline"
                    >
                        Quản lý danh sách
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <div
                    v-if="subscriptions.length === 0"
                    class="rounded-lg bg-white p-12 text-center shadow-sm"
                >
                    <p class="text-gray-600">
                        Chưa có dịch vụ nào trong vũ trụ của bạn.
                    </p>
                    <Link
                        :href="route('subscriptions.create')"
                        class="mt-4 inline-block rounded-md bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-500"
                    >
                        Thêm dịch vụ đầu tiên
                    </Link>
                </div>

                <div
                    v-else
                    class="rounded-lg bg-gradient-to-b from-slate-900 to-slate-800 p-4 shadow-sm"
                >
                    <Orbit :subscriptions="subscriptions" :user="user" />
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="scanning"
                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm"
            >
                <div
                    class="mx-4 flex w-full max-w-sm flex-col gap-4 rounded-xl bg-white p-8 shadow-2xl"
                >
                    <template v-if="!scanError">
                        <div class="flex items-center gap-3">
                            <span
                                class="h-6 w-6 shrink-0 animate-spin rounded-full border-4 border-emerald-500 border-t-transparent"
                            ></span>
                            <p class="text-lg font-semibold text-gray-900">
                                Đang quét Gmail…
                            </p>
                        </div>

                        <div>
                            <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-200">
                                <div
                                    class="h-full rounded-full bg-emerald-500 transition-all duration-300"
                                    :style="{ width: percent + '%' }"
                                ></div>
                            </div>
                            <p class="mt-2 text-center text-sm text-gray-500">
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
                        <p class="text-lg font-semibold text-gray-900">Quét thất bại</p>
                        <p class="text-sm text-gray-600">{{ scanError }}</p>
                        <div class="flex justify-end gap-2">
                            <button
                                type="button"
                                class="rounded-md px-3 py-2 text-sm text-gray-600 hover:underline"
                                @click="closeScan"
                            >
                                Đóng
                            </button>
                            <button
                                type="button"
                                class="rounded-md bg-emerald-600 px-3 py-2 text-sm text-white hover:bg-emerald-500"
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
