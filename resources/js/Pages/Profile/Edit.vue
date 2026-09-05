<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import NotificationPreferencesForm from './Partials/NotificationPreferencesForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout title="Cài đặt">
        <template #header>
            <h2
                class="text-2xl font-extrabold tracking-tight text-white"
            >
                Profile
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div
                    class="rounded-2xl border border-white/5 bg-midnight-900 p-4 shadow-lg shadow-black/20 sm:p-8"
                >
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="rounded-2xl border border-white/5 bg-midnight-900 p-4 shadow-lg shadow-black/20 sm:p-8"
                >
                    <NotificationPreferencesForm class="max-w-xl" />
                </div>

                <div
                    class="rounded-2xl border border-white/5 bg-midnight-900 p-4 shadow-lg shadow-black/20 sm:p-8"
                >
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div
                    class="rounded-2xl border border-white/5 bg-midnight-900 p-4 shadow-lg shadow-black/20 sm:p-8"
                >
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
