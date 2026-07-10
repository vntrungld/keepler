<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({ subscriptions: Array });

function destroy(id) {
    if (confirm('Xóa dịch vụ này?')) {
        router.delete(`/subscriptions/${id}`);
    }
}
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
                        class="rounded bg-indigo-600 px-3 py-2 text-white hover:bg-indigo-500"
                    >
                        Thêm
                    </Link>
                </div>
                <div class="overflow-x-auto rounded-lg bg-white p-4 shadow-sm">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b">
                                <th class="py-2">Tên</th>
                                <th>Giá</th>
                                <th>VND</th>
                                <th>Chu kỳ</th>
                                <th>Gia hạn</th>
                                <th>Trạng thái</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in subscriptions" :key="s.id" class="border-b">
                                <td class="py-2">{{ s.name }}</td>
                                <td>{{ s.amount }} {{ s.currency }}</td>
                                <td>{{ s.amount_vnd }}</td>
                                <td>{{ s.billing_cycle }}</td>
                                <td>{{ s.next_renewal_date }}</td>
                                <td>{{ s.status }}</td>
                                <td class="space-x-2">
                                    <Link :href="`/subscriptions/${s.id}/edit`" class="text-indigo-600">Sửa</Link>
                                    <button class="text-red-600" @click="destroy(s.id)">Xóa</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
