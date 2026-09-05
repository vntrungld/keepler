<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SubscriptionList from '@/orbit/SubscriptionList.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({ subscriptions: Array });
</script>

<template>
    <Head title="Dịch vụ đăng ký" />

    <AuthenticatedLayout title="Dịch vụ đăng ký">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-extrabold tracking-tight text-white">
                    Dịch vụ đăng ký
                </h2>
                <Link
                    :href="route('dashboard')"
                    class="text-sm text-violet-400 hover:text-violet-300 hover:underline"
                >
                    Về vũ trụ
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <div class="mb-4 flex justify-end">
                    <Link
                        href="/subscriptions/create"
                        class="rounded-lg bg-violet-600 px-3 py-2 text-white hover:bg-violet-500"
                    >
                        Thêm
                    </Link>
                </div>

                <div
                    v-if="subscriptions.length === 0"
                    class="rounded-2xl border border-white/5 bg-midnight-900 p-12 text-center shadow-lg shadow-black/20"
                >
                    <p class="text-slate-400">Chưa có dịch vụ nào.</p>
                </div>

                <SubscriptionList v-else :subscriptions="subscriptions" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
