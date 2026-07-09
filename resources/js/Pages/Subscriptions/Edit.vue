<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({ subscription: Object, currencies: Array });

const form = useForm({
    name: props.subscription.name,
    amount: props.subscription.amount,
    currency: props.subscription.currency,
    billing_cycle: props.subscription.billing_cycle,
    next_renewal_date: props.subscription.next_renewal_date?.slice(0, 10),
    status: props.subscription.status,
    cancel_url: props.subscription.cancel_url ?? '',
    notes: props.subscription.notes ?? '',
});

function submit() {
    form.put(`/subscriptions/${props.subscription.id}`);
}
</script>

<template>
    <form class="mx-auto max-w-lg space-y-3 p-6" @submit.prevent="submit">
        <h1 class="text-xl font-semibold">Sửa dịch vụ</h1>
        <input v-model="form.name" class="w-full border p-2" />
        <input v-model="form.amount" type="number" step="0.01" class="w-full border p-2" />
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
        <input v-model="form.cancel_url" class="w-full border p-2" />
        <textarea v-model="form.notes" class="w-full border p-2"></textarea>
        <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white">Cập nhật</button>
    </form>
</template>
