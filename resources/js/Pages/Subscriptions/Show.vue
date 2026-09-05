<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrandIcon from '@/orbit/BrandIcon.vue';
import { statusLabel, cycleLabel, formatVnd } from '@/orbit/labels.js';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    subscription: { type: Object, required: true },
});

const eventLabel = {
    subscribed: 'Đã đăng ký',
    price_changed: 'Đổi giá',
    cancelled: 'Đã hủy',
};

const vnd = computed(() => formatVnd(props.subscription.amount_vnd));

const cancelForm = useForm({
    name: props.subscription.name,
    amount: props.subscription.amount,
    currency: props.subscription.currency,
    billing_cycle: props.subscription.billing_cycle,
    next_renewal_date: props.subscription.next_renewal_date?.slice(0, 10),
    status: 'cancelled',
    category: props.subscription.category,
    list: props.subscription.list,
});

function markCancelled() {
    if (!confirm(`Đánh dấu "${props.subscription.name}" là đã hủy?`)) return;
    cancelForm.put(route('subscriptions.update', props.subscription.id));
}

const deleteForm = useForm({});

function destroy() {
    if (!confirm(`Xóa "${props.subscription.name}"? Hành động này không thể hoàn tác.`)) return;
    deleteForm.delete(route('subscriptions.destroy', props.subscription.id));
}
</script>

<template>
    <Head :title="subscription.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-extrabold tracking-tight text-white">{{ subscription.name }}</h2>
                <Link
                    :href="route('subscriptions.edit', subscription.id)"
                    class="text-sm text-violet-400 hover:text-violet-300 hover:underline"
                >
                    Sửa
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-4 rounded-2xl border border-white/5 bg-midnight-900 p-6 shadow-lg shadow-black/20">
                    <BrandIcon :name="subscription.name" :size="56" />
                    <div>
                        <p class="text-xl font-bold text-white">{{ subscription.name }}</p>
                        <p class="text-lg text-slate-300">
                            {{ subscription.amount }} {{ subscription.currency }}
                            <span v-if="subscription.currency !== 'VND'" class="text-sm text-slate-500">({{ vnd }} ₫)</span>
                        </p>
                    </div>
                </div>

                <dl class="grid grid-cols-2 gap-4 rounded-2xl border border-white/5 bg-midnight-900 p-6 text-sm shadow-lg shadow-black/20">
                    <div>
                        <dt class="text-slate-500">Chu kỳ</dt>
                        <dd class="text-slate-100">{{ cycleLabel[subscription.billing_cycle] ?? subscription.billing_cycle }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Gia hạn tiếp theo</dt>
                        <dd class="text-slate-100">{{ subscription.next_renewal_date?.slice(0, 10) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Đã chi</dt>
                        <dd class="text-slate-100">
                            <template v-if="subscription.currency === 'VND'">{{ formatVnd(subscription.total_spent) }} ₫</template>
                            <template v-else>{{ subscription.total_spent }} {{ subscription.currency }}</template>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Đã đăng ký</dt>
                        <dd class="text-slate-100">{{ subscription.subscribed_days }} ngày</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Danh mục</dt>
                        <dd class="text-slate-100">{{ subscription.category ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Phương thức</dt>
                        <dd class="text-slate-100">{{ subscription.payment_method?.label ?? 'Chưa đặt' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Trạng thái</dt>
                        <dd class="text-slate-100">{{ statusLabel[subscription.status] ?? subscription.status }}</dd>
                    </div>
                    <div v-if="subscription.is_trial">
                        <dt class="text-slate-500">Dùng thử</dt>
                        <dd class="text-slate-100">Có</dd>
                    </div>
                </dl>

                <div v-if="subscription.events?.length" id="history" class="rounded-2xl border border-white/5 bg-midnight-900 p-6 shadow-lg shadow-black/20">
                    <h3 class="mb-3 text-sm font-semibold text-slate-300">Lịch sử</h3>
                    <ul class="space-y-2 text-sm">
                        <li v-for="event in subscription.events" :key="event.id" class="flex justify-between text-slate-400">
                            <span>{{ eventLabel[event.kind] ?? event.kind }}</span>
                            <span>
                                <template v-if="event.amount">{{ event.amount }} {{ event.currency }} · </template>
                                {{ event.occurred_at?.slice(0, 10) }}
                            </span>
                        </li>
                    </ul>
                </div>

                <div class="flex flex-col gap-2">
                    <button
                        v-if="subscription.status !== 'cancelled'"
                        type="button"
                        class="rounded-lg bg-violet-600 px-4 py-2 text-white hover:bg-violet-500 disabled:opacity-50"
                        :disabled="cancelForm.processing"
                        @click="markCancelled"
                    >
                        Đánh dấu đã hủy
                    </button>
                    <button
                        type="button"
                        class="text-center text-sm text-red-400 hover:text-red-300 hover:underline"
                        :disabled="deleteForm.processing"
                        @click="destroy"
                    >
                        Xóa dịch vụ
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
