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
</script>

<template>
    <Head title="Kết quả quét Gmail" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Kết quả quét Gmail
                </h2>
                <Link :href="route('dashboard')" class="text-sm text-indigo-600 hover:underline">
                    Về vũ trụ
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <p v-if="rows.length === 0" class="rounded-lg bg-white p-8 text-center text-gray-600 shadow-sm">
                    Không tìm thấy dịch vụ nào trong hộp thư gần đây.
                </p>

                <div v-else class="space-y-3">
                    <div
                        v-for="(row, i) in rows"
                        :key="i"
                        class="rounded-lg bg-white p-4 shadow-sm"
                        :class="{ 'opacity-50': row.action === 'skip' }"
                    >
                        <div class="flex items-start gap-3">
                            <input
                                type="checkbox"
                                v-model="row.selected"
                                :disabled="row.action === 'skip'"
                                class="mt-1"
                            />
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-semibold text-gray-900">{{ row.name }}</span>
                                    <span class="text-xs text-gray-500">
                                        {{ actionLabel[row.action] }}
                                        <span v-if="row.duplicate_of">· 🔁 đã có</span>
                                    </span>
                                </div>

                                <div v-if="row.action !== 'update_status'" class="mt-2 grid grid-cols-2 gap-2 text-sm">
                                    <label class="flex flex-col">
                                        <span class="text-gray-500">Số tiền</span>
                                        <input v-model="row.amount" type="number" step="0.01" class="rounded border-gray-300" />
                                    </label>
                                    <label class="flex flex-col">
                                        <span class="text-gray-500">Tiền tệ</span>
                                        <input v-model="row.currency" type="text" class="rounded border-gray-300" />
                                    </label>
                                    <label class="flex flex-col">
                                        <span class="text-gray-500">Chu kỳ</span>
                                        <select v-model="row.billing_cycle" class="rounded border-gray-300">
                                            <option value="monthly">Hàng tháng</option>
                                            <option value="yearly">Hàng năm</option>
                                        </select>
                                    </label>
                                    <label class="flex flex-col">
                                        <span class="text-gray-500">Gia hạn</span>
                                        <input v-model="row.next_renewal_date" type="date" class="rounded border-gray-300" />
                                    </label>
                                </div>
                                <p v-else class="mt-1 text-sm text-gray-600">
                                    Gói này đang có trong danh sách — sẽ được đánh dấu đã hủy.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button
                            type="button"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-500 disabled:opacity-50"
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
