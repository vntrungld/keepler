<script setup>
import { useForm } from '@inertiajs/vue3';

defineProps({ currencies: Array });

const form = useForm({
    name: '',
    amount: '',
    currency: 'VND',
    billing_cycle: 'monthly',
    next_renewal_date: '',
    status: 'active',
    cancel_url: '',
    notes: '',
});

function submit() {
    form.post('/subscriptions');
}
</script>

<template>
    <form class="mx-auto max-w-lg space-y-3 p-6" @submit.prevent="submit">
        <h1 class="text-xl font-semibold">Thêm dịch vụ</h1>
        <input v-model="form.name" placeholder="Tên" class="w-full border p-2" />
        <input v-model="form.amount" type="number" step="0.01" placeholder="Số tiền" class="w-full border p-2" />
        <select v-model="form.currency" class="w-full border p-2">
            <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
        </select>
        <select v-model="form.billing_cycle" class="w-full border p-2">
            <option value="monthly">Hàng tháng</option>
            <option value="yearly">Hàng năm</option>
        </select>
        <input v-model="form.next_renewal_date" type="date" class="w-full border p-2" />
        <select v-model="form.status" class="w-full border p-2">
            <option value="active">Đang hoạt động</option>
            <option value="pending_cancel">Sắp hủy</option>
            <option value="cancelled">Đã hủy</option>
        </select>
        <input v-model="form.cancel_url" placeholder="Link hủy (tùy chọn)" class="w-full border p-2" />
        <textarea v-model="form.notes" placeholder="Ghi chú" class="w-full border p-2"></textarea>
        <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white">Lưu</button>
    </form>
</template>
