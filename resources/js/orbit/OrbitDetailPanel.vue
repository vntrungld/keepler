<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    sub: { type: Object, required: true },
});

defineEmits(['close']);

const vnd = computed(() =>
    new Intl.NumberFormat('vi-VN').format(props.sub.amount_vnd),
);

const statusLabel = {
    active: 'Đang hoạt động',
    pending_cancel: 'Sắp hủy',
    cancelled: 'Đã hủy',
};

const form = useForm({});

function destroy() {
    if (!confirm(`Xóa "${props.sub.name}"?`)) return;
    form.delete(route('subscriptions.destroy', props.sub.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div
        class="absolute right-0 top-0 z-20 flex h-full w-72 max-w-full flex-col gap-4 rounded-l-lg bg-white p-5 shadow-xl"
    >
        <div class="flex items-start justify-between">
            <h3 class="text-lg font-semibold text-gray-900">{{ sub.name }}</h3>
            <button
                type="button"
                class="text-gray-400 hover:text-gray-600"
                @click="$emit('close')"
            >
                ✕
            </button>
        </div>

        <dl class="space-y-2 text-sm text-gray-700">
            <div class="flex justify-between">
                <dt class="text-gray-500">Số tiền</dt>
                <dd>{{ sub.amount }} {{ sub.currency }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">Quy đổi</dt>
                <dd>{{ vnd }} ₫</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">Chu kỳ</dt>
                <dd>{{ sub.billing_cycle === 'yearly' ? 'Hàng năm' : 'Hàng tháng' }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">Gia hạn</dt>
                <dd>{{ sub.next_renewal_date?.slice(0, 10) }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">Trạng thái</dt>
                <dd>{{ statusLabel[sub.status] ?? sub.status }}</dd>
            </div>
        </dl>

        <div class="mt-auto flex gap-2">
            <Link
                :href="route('subscriptions.edit', sub.id)"
                class="flex-1 rounded-md bg-indigo-600 px-3 py-2 text-center text-sm text-white hover:bg-indigo-500"
            >
                Sửa
            </Link>
            <button
                type="button"
                class="flex-1 rounded-md bg-red-600 px-3 py-2 text-sm text-white hover:bg-red-500 disabled:opacity-50"
                :disabled="form.processing"
                @click="destroy"
            >
                Xóa
            </button>
        </div>
    </div>
</template>
