<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Orbit from '@/orbit/Orbit.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    subscriptions: { type: Array, default: () => [] },
    gmail_connected: { type: Boolean, default: false },
});

const user = computed(() => usePage().props.auth.user);

// Show a blocking progress popup while the (synchronous) Gmail scan runs.
const scanning = ref(false);

function startScan() {
    router.get(
        route('gmail.scan'),
        {},
        {
            onStart: () => (scanning.value = true),
            onFinish: () => (scanning.value = false),
        },
    );
}
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
                    <button
                        v-if="gmail_connected"
                        type="button"
                        :disabled="scanning"
                        class="rounded-md bg-emerald-600 px-3 py-2 text-white hover:bg-emerald-500 disabled:opacity-60"
                        @click="startScan"
                    >
                        {{ scanning ? 'Đang quét…' : 'Quét Gmail' }}
                    </button>
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

        <Teleport to="body">
            <div
                v-if="scanning"
                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm"
            >
                <div
                    class="mx-4 flex w-full max-w-sm flex-col items-center gap-4 rounded-xl bg-white p-8 text-center shadow-2xl"
                >
                    <span
                        class="h-12 w-12 animate-spin rounded-full border-4 border-emerald-500 border-t-transparent"
                    ></span>
                    <div>
                        <p class="text-lg font-semibold text-gray-900">
                            Đang quét Gmail…
                        </p>
                        <p class="mt-1 text-sm text-gray-500">
                            Đang đọc các email hóa đơn gần đây. Việc này có thể
                            mất một chút, vui lòng chờ.
                        </p>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
