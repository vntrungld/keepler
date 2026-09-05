<script setup>
import { Link } from '@inertiajs/vue3';

const tabs = [
    { href: () => route('dashboard'), active: () => route().current('dashboard'), label: 'Dịch vụ', icon: 'M4 6h16M4 12h16M4 18h7' },
    { href: () => route('calendar.index'), active: () => route().current('calendar.index'), label: 'Lịch', icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z' },
    { href: () => route('profile.edit'), active: () => route().current('profile.edit'), label: 'Cài đặt', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z' },
];
</script>

<template>
    <nav
        class="fixed inset-x-4 bottom-4 z-40 flex justify-around rounded-full border border-white/10 bg-midnight-900/95 px-2 py-2 shadow-2xl backdrop-blur sm:hidden"
        style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom))"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.label"
            :href="tab.href()"
            class="flex flex-1 flex-col items-center gap-0.5 rounded-full px-2 py-1.5 text-xs"
            :class="tab.active() ? 'text-white' : 'text-slate-500'"
        >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="tab.icon" />
            </svg>
            {{ tab.label }}
        </Link>
    </nav>
</template>
