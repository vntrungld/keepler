<script setup>
import GmailScanProgress from '@/orbit/GmailScanProgress.vue';
import { useGmailScan } from '@/orbit/useGmailScan.js';
import { Link } from '@inertiajs/vue3';

defineProps({
    gmailConnected: { type: Boolean, default: false },
});

const { scanning, scanError, processed, total, percent, startScan, closeScan } = useGmailScan();
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-slate-100">Gmail</h2>

            <p class="mt-1 text-sm text-slate-400">
                Keepler đọc email hóa đơn trong Gmail để tự tìm các dịch vụ bạn đang
                trả tiền. Quyền được xin là chỉ đọc, và bạn ngắt kết nối bất cứ lúc nào.
            </p>
        </header>

        <div v-if="gmailConnected" class="mt-6">
            <p class="text-sm text-slate-400">
                <span class="font-medium text-slate-100">Đã kết nối.</span>
                Quét lại khi bạn nghĩ có dịch vụ mới trong hộp thư.
            </p>

            <div class="mt-4 flex items-center gap-4">
                <button
                    type="button"
                    :disabled="scanning"
                    class="rounded-lg bg-violet-600 px-4 py-2 text-sm text-white hover:bg-violet-500 disabled:opacity-60"
                    @click="startScan"
                >
                    {{ scanning ? 'Đang quét…' : 'Quét ngay' }}
                </button>

                <Link
                    :href="route('gmail.disconnect')"
                    method="delete"
                    as="button"
                    class="text-sm text-slate-500 hover:text-slate-300 hover:underline"
                >
                    Ngắt kết nối
                </Link>
            </div>
        </div>

        <div v-else class="mt-6">
            <a
                :href="route('gmail.connect')"
                class="inline-block rounded-lg bg-violet-600 px-4 py-2 text-sm text-white hover:bg-violet-500"
            >
                Kết nối Gmail
            </a>
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
    </section>
</template>
