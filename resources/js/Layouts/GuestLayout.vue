<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-midnight-950 pt-6 sm:justify-center sm:pt-0"
    >
        <div>
            <Link href="/">
                <ApplicationLogo class="h-20 w-20 fill-current text-violet-400" />
            </Link>
        </div>

        <div
            class="mt-6 w-full overflow-hidden rounded-2xl border border-white/5 bg-midnight-900 px-6 py-4 shadow-2xl sm:max-w-md"
        >
            <slot />
        </div>
    </div>
</template>
