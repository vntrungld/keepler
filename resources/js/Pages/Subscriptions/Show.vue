<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({ subscription: Object });
</script>

<template>
    <Head :title="props.subscription.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-extrabold tracking-tight text-white">
                    {{ props.subscription.name }}
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
            <div class="mx-auto max-w-lg space-y-6 rounded-2xl border border-white/5 bg-midnight-900 p-6 shadow-lg shadow-black/20">
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <dt class="text-slate-400">Số tiền</dt>
                    <dd class="text-slate-100">{{ props.subscription.amount }} {{ props.subscription.currency }}</dd>

                    <dt class="text-slate-400">Chu kỳ</dt>
                    <dd class="text-slate-100">{{ props.subscription.billing_cycle }}</dd>

                    <dt class="text-slate-400">Trạng thái</dt>
                    <dd class="text-slate-100">{{ props.subscription.status }}</dd>

                    <dt class="text-slate-400">Danh mục</dt>
                    <dd class="text-slate-100">{{ props.subscription.category ?? '—' }}</dd>

                    <dt class="text-slate-400">Phương thức thanh toán</dt>
                    <dd class="text-slate-100">{{ props.subscription.payment_method?.label ?? '—' }}</dd>

                    <dt class="text-slate-400">Số ngày đã dùng</dt>
                    <dd class="text-slate-100">{{ props.subscription.subscribed_days }}</dd>

                    <dt class="text-slate-400">Tổng chi tiêu</dt>
                    <dd class="text-slate-100">{{ props.subscription.total_spent }}</dd>
                </dl>

                <div>
                    <h3 class="mb-2 text-sm font-semibold text-slate-300">Lịch sử</h3>
                    <ul class="space-y-1 text-sm text-slate-400">
                        <li v-for="event in props.subscription.events" :key="event.id">
                            {{ event.occurred_at }} — {{ event.kind }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
