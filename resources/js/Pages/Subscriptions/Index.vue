<script setup>
import { Link, router } from '@inertiajs/vue3';

defineProps({ subscriptions: Array });

function destroy(id) {
    if (confirm('Xóa dịch vụ này?')) {
        router.delete(`/subscriptions/${id}`);
    }
}
</script>

<template>
    <div class="mx-auto max-w-3xl p-6">
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-xl font-semibold">Dịch vụ đăng ký</h1>
            <Link href="/subscriptions/create" class="rounded bg-indigo-600 px-3 py-2 text-white">Thêm</Link>
        </div>
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
</template>
