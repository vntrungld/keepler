<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useForm, usePage } from '@inertiajs/vue3';

const user = usePage().props.auth.user;

const form = useForm({
    renewal_reminders_enabled: user.renewal_reminders_enabled,
    reminder_days_before: user.reminder_days_before,
});

function submit() {
    form.patch(route('profile.notifications.update'), { preserveScroll: true });
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-semibold text-white">Nhắc nhở gia hạn</h2>
            <p class="mt-1 text-sm text-slate-400">
                Nhận email nhắc trước khi một dịch vụ sắp gia hạn.
            </p>
        </header>

        <form @submit.prevent="submit" class="mt-6 space-y-6">
            <label class="flex items-center gap-2">
                <input
                    type="checkbox"
                    v-model="form.renewal_reminders_enabled"
                    class="rounded border-white/20 bg-midnight-800 text-violet-500 focus:ring-violet-500"
                />
                <span class="text-sm text-slate-300">Bật nhắc nhở qua email</span>
            </label>

            <div>
                <InputLabel for="reminder_days_before" value="Nhắc trước (số ngày)" />
                <select
                    id="reminder_days_before"
                    v-model.number="form.reminder_days_before"
                    class="mt-1 rounded-lg border-white/10 bg-midnight-800 text-slate-100 focus:border-violet-500 focus:ring-violet-500"
                >
                    <option :value="1">1 ngày</option>
                    <option :value="3">3 ngày</option>
                    <option :value="7">7 ngày</option>
                </select>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Lưu</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-slate-500">Đã lưu.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
