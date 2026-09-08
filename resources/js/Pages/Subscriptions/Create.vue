<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrandIcon from '@/orbit/BrandIcon.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({ currencies: Array, paymentMethods: Array, services: Array });

const paymentMethodOptions = ref([...props.paymentMethods]);
const newPaymentMethodLabel = ref('');
const addingPaymentMethod = ref(false);
const paymentMethodError = ref('');

const form = useForm({
    name: '',
    amount: '',
    currency: 'VND',
    billing_cycle: 'monthly',
    next_renewal_date: '',
    status: 'active',
    cancel_url: '',
    notes: '',
    list: 'personal',
    category: '',
    payment_method_id: '',
    is_trial: false,
    started_at: '',
});

// Picking a known service fills in what the catalog already knows, so the
// user only has to type what is personal to them (amount, renewal date).
// Currency is left alone on purpose: the catalog's prices are USD list
// prices, but most people here are billed in VND.
watch(
    () => form.name,
    (name) => {
        const service = props.services.find((s) => s.name === name);
        if (!service) return;

        form.billing_cycle = service.default_cycle;
        form.cancel_url = service.cancel_url;
    },
);

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
    form.post('/subscriptions');
}
</script>

<template>
    <Head title="Thêm dịch vụ" />

    <AuthenticatedLayout title="Thêm dịch vụ">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-extrabold tracking-tight text-white">Thêm dịch vụ</h2>
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
                <div class="flex items-center gap-2">
                    <BrandIcon :name="form.name || '?'" :size="40" />
                    <input
                        v-model="form.name"
                        list="service-options"
                        placeholder="Tên dịch vụ (chọn hoặc tự nhập)"
                        class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500"
                    />
                </div>
                <datalist id="service-options">
                    <option v-for="service in services" :key="service.name" :value="service.name" />
                </datalist>
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

                <div class="flex gap-2">
                    <select v-model="form.payment_method_id" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500">
                        <option value="">Chưa đặt phương thức</option>
                        <option v-for="pm in paymentMethodOptions" :key="pm.id" :value="pm.id">{{ pm.label }}</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <input
                        v-model="newPaymentMethodLabel"
                        placeholder="Thêm phương thức mới (vd: Visa •••• 1234)"
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
                    <label class="text-sm text-slate-500">Bắt đầu từ (tùy chọn)</label>
                    <input v-model="form.started_at" type="date" class="mt-1 w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 focus:border-violet-500 focus:ring-violet-500" />
                </div>

                <input v-model="form.cancel_url" placeholder="Link hủy (tùy chọn)" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500" />
                <textarea v-model="form.notes" placeholder="Ghi chú" class="w-full rounded-lg border border-white/10 bg-midnight-800 p-2 text-slate-100 placeholder:text-slate-500 focus:border-violet-500 focus:ring-violet-500"></textarea>
                <button type="submit" class="rounded-lg bg-violet-600 px-4 py-2 text-white hover:bg-violet-500">Lưu</button>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
