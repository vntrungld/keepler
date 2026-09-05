<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, computed } from 'vue';

const props = defineProps({
    candidates: { type: Array, default: () => [] },
});

const actionLabel = {
    create: 'Sẽ thêm mới',
    update_status: 'Đề xuất đánh dấu đã hủy',
    skip: 'Bỏ qua',
};

// Local editable rows; skip rows start unticked.
const rows = reactive(
    props.candidates.map((c) => ({
        ...c,
        selected: c.action === 'create' || c.action === 'update_status',
    })),
);

const selectedCount = computed(() => rows.filter((r) => r.selected).length);

function submit() {
    const items = rows
        .filter((r) => r.selected && r.action !== 'skip')
        .map((r) => ({
            action: r.action,
            provider_key: r.provider_key,
            name: r.name,
            amount: r.amount,
            currency: r.currency,
            billing_cycle: r.billing_cycle,
            next_renewal_date: r.next_renewal_date,
            cancel_url: r.cancel_url,
            duplicate_of: r.duplicate_of,
        }));

    router.post(route('gmail.import'), { items });
}

// Deep-link to the original message in Gmail so the user can review it.
function gmailUrl(id) {
    return `https://mail.google.com/mail/u/0/#all/${id}`;
}
</script>

<template>
    <Head title="Kết quả quét Gmail" />

    <AuthenticatedLayout title="Kết quả quét Gmail">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-extrabold tracking-tight text-white">
                    Kết quả quét Gmail
                </h2>
                <Link :href="route('dashboard')" class="text-sm text-violet-400 hover:text-violet-300 hover:underline">
                    Về vũ trụ
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <p v-if="rows.length === 0" class="rounded-2xl border border-white/5 bg-midnight-900 p-8 text-center text-slate-400 shadow-lg shadow-black/20">
                    Không tìm thấy dịch vụ nào trong hộp thư gần đây.
                </p>

                <div v-else class="space-y-3">
                    <div
                        v-for="(row, i) in rows"
                        :key="i"
                        class="rounded-2xl border border-white/5 bg-midnight-900 p-4 shadow-lg shadow-black/10"
                        :class="{ 'opacity-50': row.action === 'skip' }"
                    >
                        <div class="flex items-start gap-3">
                            <input
                                type="checkbox"
                                v-model="row.selected"
                                :disabled="row.action === 'skip'"
                                class="mt-1 rounded border-white/20 bg-midnight-800 text-violet-500 focus:ring-violet-500"
                            />
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-semibold text-slate-100">{{ row.name }}</span>
                                    <span class="text-xs text-slate-500">
                                        {{ actionLabel[row.action] }}
                                        <span v-if="row.duplicate_of">· 🔁 đã có</span>
                                    </span>
                                </div>

                                <div v-if="row.action !== 'update_status'" class="mt-2 grid grid-cols-2 gap-2 text-sm">
                                    <label class="flex flex-col">
                                        <span class="text-slate-500">Số tiền</span>
                                        <input v-model="row.amount" type="number" step="0.01" class="rounded-lg border-white/10 bg-midnight-800 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                                    </label>
                                    <label class="flex flex-col">
                                        <span class="text-slate-500">Tiền tệ</span>
                                        <input v-model="row.currency" type="text" class="rounded-lg border-white/10 bg-midnight-800 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                                    </label>
                                    <label class="flex flex-col">
                                        <span class="text-slate-500">Chu kỳ</span>
                                        <select v-model="row.billing_cycle" class="rounded-lg border-white/10 bg-midnight-800 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                                            <option value="monthly">Hàng tháng</option>
                                            <option value="yearly">Hàng năm</option>
                                        </select>
                                    </label>
                                    <label class="flex flex-col">
                                        <span class="text-slate-500">Gia hạn</span>
                                        <input v-model="row.next_renewal_date" type="date" class="rounded-lg border-white/10 bg-midnight-800 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                                    </label>
                                </div>
                                <p v-else class="mt-1 text-sm text-slate-400">
                                    Gói này đang có trong danh sách — sẽ được đánh dấu đã hủy.
                                </p>

                                <a
                                    v-if="row.source_email_id"
                                    :href="gmailUrl(row.source_email_id)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="mt-2 inline-flex items-center gap-1 text-xs text-violet-400 hover:text-violet-300 hover:underline"
                                >
                                    ✉️ Xem email gốc trong Gmail
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button
                            type="button"
                            class="rounded-lg bg-violet-600 px-4 py-2 text-white hover:bg-violet-500 disabled:opacity-50"
                            :disabled="selectedCount === 0"
                            @click="submit"
                        >
                            Nhập {{ selectedCount }} mục đã chọn
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
