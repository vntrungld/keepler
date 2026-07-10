<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Orbit from '@/orbit/Orbit.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    subscriptions: { type: Array, default: () => [] },
    gmail_connected: { type: Boolean, default: false },
});

const user = computed(() => usePage().props.auth.user);
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Vũ trụ của bạn
                </h2>
                <div class="flex items-center gap-3 text-sm">
                    <Link
                        v-if="gmail_connected"
                        :href="route('gmail.scan')"
                        class="rounded-md bg-emerald-600 px-3 py-2 text-white hover:bg-emerald-500"
                    >
                        Quét Gmail
                    </Link>
                    <a
                        v-else
                        :href="route('gmail.connect')"
                        class="rounded-md bg-emerald-600 px-3 py-2 text-white hover:bg-emerald-500"
                    >
                        Kết nối Gmail
                    </a>
                    <Link
                        v-if="gmail_connected"
                        :href="route('gmail.disconnect')"
                        method="delete"
                        as="button"
                        class="text-gray-500 hover:underline"
                    >
                        Ngắt kết nối
                    </Link>
                    <Link
                        :href="route('subscriptions.index')"
                        class="text-indigo-600 hover:underline"
                    >
                        Quản lý danh sách
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <div
                    v-if="subscriptions.length === 0"
                    class="rounded-lg bg-white p-12 text-center shadow-sm"
                >
                    <p class="text-gray-600">
                        Chưa có dịch vụ nào trong vũ trụ của bạn.
                    </p>
                    <Link
                        :href="route('subscriptions.create')"
                        class="mt-4 inline-block rounded-md bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-500"
                    >
                        Thêm dịch vụ đầu tiên
                    </Link>
                </div>

                <div
                    v-else
                    class="rounded-lg bg-gradient-to-b from-slate-900 to-slate-800 p-4 shadow-sm"
                >
                    <Orbit :subscriptions="subscriptions" :user="user" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
