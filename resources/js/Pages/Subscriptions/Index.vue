<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SubscriptionList from '@/orbit/SubscriptionList.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({ subscriptions: Array });
</script>

<template>
    <Head title="Dịch vụ đăng ký" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Dịch vụ đăng ký
                </h2>
                <Link
                    :href="route('dashboard')"
                    class="text-sm text-indigo-600 hover:underline"
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
                        class="rounded-md bg-indigo-600 px-3 py-2 text-white hover:bg-indigo-500"
                    >
                        Thêm
                    </Link>
                </div>

                <div
                    v-if="subscriptions.length === 0"
                    class="rounded-lg bg-white p-12 text-center shadow-sm"
                >
                    <p class="text-gray-600">Chưa có dịch vụ nào.</p>
                </div>

                <SubscriptionList v-else :subscriptions="subscriptions" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
