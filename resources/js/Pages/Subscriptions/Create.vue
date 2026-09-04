<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

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
    <Head title="Thêm dịch vụ" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-extrabold tracking-tight text-white">
                    Thêm dịch vụ
                </h2>
                <Link
                    :href="route('subscriptions.index')"
                    class="text-sm text-violet-400 hover:text-violet-300 hover:underline"
                >
                    Về danh sách
                </Link>
            </div>
        </template>

        <div class="py-8">
            <form
                class="mx-auto max-w-lg space-y-3 rounded-2xl border border-white/5 bg-midnight-900 p-6 shadow-lg shadow-black/20"
                @submit.prevent="submit"
            >
                <input v-model="form.name" placeholder="Tên" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500" />
                <input v-model="form.amount" type="number" step="0.01" placeholder="Số tiền" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500" />
                <select v-model="form.currency" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                    <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
                </select>
                <select v-model="form.billing_cycle" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                    <option value="monthly">Hàng tháng</option>
                    <option value="yearly">Hàng năm</option>
                </select>
                <input v-model="form.next_renewal_date" type="date" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                <select v-model="form.status" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                    <option value="active">Đang hoạt động</option>
                    <option value="pending_cancel">Sắp hủy</option>
                    <option value="cancelled">Đã hủy</option>
                </select>
                <input v-model="form.cancel_url" placeholder="Link hủy (tùy chọn)" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500" />
                <textarea v-model="form.notes" placeholder="Ghi chú" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500"></textarea>
                <button type="submit" class="rounded-lg bg-violet-600 px-4 py-2 text-white hover:bg-violet-500">Lưu</button>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
