<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';

const props = defineProps({ subscription: Object, currencies: Array, paymentMethods: Array });

const paymentMethodOptions = ref([...props.paymentMethods]);
const newPaymentMethodLabel = ref('');
const addingPaymentMethod = ref(false);
const paymentMethodError = ref('');

const authUser = computed(() => usePage().props.auth.user);

const form = useForm({
    name: props.subscription.name,
    amount: props.subscription.amount,
    currency: props.subscription.currency,
    billing_cycle: props.subscription.billing_cycle,
    next_renewal_date: props.subscription.next_renewal_date?.slice(0, 10),
    status: props.subscription.status,
    cancel_url: props.subscription.cancel_url ?? '',
    notes: props.subscription.notes ?? '',
    list: props.subscription.list,
    category: props.subscription.category ?? '',
    payment_method_id: props.subscription.payment_method_id ?? '',
    is_trial: props.subscription.is_trial,
    started_at: props.subscription.started_at?.slice(0, 10) ?? '',
});

async function addPaymentMethod() {
    if (!newPaymentMethodLabel.value.trim()) return;
    addingPaymentMethod.value = true;
    paymentMethodError.value = '';
    try {
        const { data } = await axios.post(route('payment-methods.store'), {
            label: newPaymentMethodLabel.value.trim(),
        });
        paymentMethodOptions.value.push(data);
        form.payment_method_id = data.id;
        newPaymentMethodLabel.value = '';
    } catch (error) {
        paymentMethodError.value =
            error.response?.data?.message ?? 'Không thể thêm phương thức thanh toán. Vui lòng thử lại.';
    } finally {
        addingPaymentMethod.value = false;
    }
}

function submit() {
    form.put(`/subscriptions/${props.subscription.id}`);
}
</script>

<template>
    <Head title="Sửa dịch vụ" />

    <AuthenticatedLayout title="Sửa dịch vụ">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-extrabold tracking-tight text-white">Sửa dịch vụ</h2>
                <Link
                    :href="route('subscriptions.index')"
                    class="text-sm text-violet-400 hover:text-violet-300 hover:underline"
                >
                    Về danh sách
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-lg space-y-4 px-4 sm:px-0">
                <div v-if="!authUser.email_verified_at" class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-300">
                    Xác thực email để nhận nhắc nhở gia hạn ·
                    <Link :href="route('verification.send')" method="post" as="button" class="underline">Gửi lại email</Link>
                </div>
                <div v-else-if="!authUser.renewal_reminders_enabled" class="rounded-lg border border-white/10 bg-midnight-800 p-3 text-sm text-slate-400">
                    Nhắc gia hạn qua email đang tắt ·
                    <Link :href="route('profile.edit')" class="text-violet-400 underline">Bật trong Cài đặt</Link>
                </div>

                <form
                    class="space-y-3 rounded-2xl border border-white/5 bg-midnight-900 p-6 shadow-lg shadow-black/20"
                    @submit.prevent="submit"
                >
                    <input v-model="form.name" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                    <input v-model="form.amount" type="number" step="0.01" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
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

                    <select v-model="form.list" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                        <option value="personal">Cá nhân</option>
                        <option value="business">Công việc</option>
                        <option value="family">Gia đình</option>
                    </select>

                    <input
                        v-model="form.category"
                        list="category-options"
                        placeholder="Danh mục (vd: Streaming)"
                        class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500"
                    />
                    <datalist id="category-options">
                        <option value="Streaming" />
                        <option value="Productivity" />
                        <option value="Utilities" />
                        <option value="Finance" />
                        <option value="Health" />
                        <option value="Education" />
                        <option value="Other" />
                    </datalist>

                    <select v-model="form.payment_method_id" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                        <option value="">Chưa đặt phương thức</option>
                        <option v-for="pm in paymentMethodOptions" :key="pm.id" :value="pm.id">{{ pm.label }}</option>
                    </select>
                    <div class="flex gap-2">
                        <input
                            v-model="newPaymentMethodLabel"
                            placeholder="Thêm phương thức mới"
                            class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-sm text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500"
                        />
                        <button
                            type="button"
                            class="shrink-0 rounded-lg bg-midnight-800 px-3 text-sm text-slate-200 hover:bg-midnight-700 disabled:opacity-50"
                            :disabled="addingPaymentMethod"
                            @click="addPaymentMethod"
                        >
                            + Thêm
                        </button>
                    </div>
                    <p v-if="paymentMethodError" class="text-sm text-red-400">{{ paymentMethodError }}</p>

                    <label class="flex items-center gap-2 text-sm text-slate-300">
                        <input type="checkbox" v-model="form.is_trial" class="rounded border-white/20 bg-midnight-800 text-violet-500 focus:ring-violet-500" />
                        Đang dùng thử miễn phí
                    </label>

                    <div>
                        <label class="text-sm text-slate-500">Bắt đầu từ</label>
                        <input v-model="form.started_at" type="date" class="mt-1 w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                    </div>

                    <input v-model="form.cancel_url" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                    <textarea v-model="form.notes" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500"></textarea>

                    <Link
                        :href="`${route('subscriptions.show', subscription.id)}#history`"
                        class="block text-sm text-violet-400 hover:text-violet-300 hover:underline"
                    >
                        Xem lịch sử →
                    </Link>

                    <button type="submit" class="rounded-lg bg-violet-600 px-4 py-2 text-white hover:bg-violet-500">Cập nhật</button>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
