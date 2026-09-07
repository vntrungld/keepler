<script setup>
/**
 * Full-screen progress overlay for a running Gmail scan. Pairs with
 * useGmailScan() — pass its refs in, listen for close/retry.
 */
defineProps({
    scanError: { type: String, default: null },
    processed: { type: Number, default: 0 },
    total: { type: Number, default: 0 },
    percent: { type: Number, default: 0 },
});

defineEmits(['close', 'retry']);
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm">
            <div class="mx-4 flex w-full max-w-sm flex-col gap-4 rounded-2xl border border-white/10 bg-midnight-900 p-8 shadow-2xl">
                <template v-if="!scanError">
                    <div class="flex items-center gap-3">
                        <span class="h-6 w-6 shrink-0 animate-spin rounded-full border-4 border-violet-500 border-t-transparent" />
                        <p class="text-lg font-semibold text-white">Đang quét Gmail…</p>
                    </div>

                    <div>
                        <div class="h-2.5 w-full overflow-hidden rounded-full bg-midnight-800">
                            <div
                                class="h-full rounded-full bg-violet-500 transition-all duration-300"
                                :style="{ width: percent + '%' }"
                            />
                        </div>
                        <p class="mt-2 text-center text-sm text-slate-500">
                            <template v-if="total > 0">
                                Đang xử lý {{ processed }}/{{ total }} email — {{ percent }}%
                            </template>
                            <template v-else>Đang tìm email hóa đơn…</template>
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
                            @click="$emit('close')"
                        >
                            Đóng
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-violet-600 px-3 py-2 text-sm text-white hover:bg-violet-500"
                            @click="$emit('retry')"
                        >
                            Thử lại
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </Teleport>
</template>
