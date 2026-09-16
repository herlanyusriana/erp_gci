<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    href: string;
    title: string;
    subtitle?: string;
    disabled?: boolean;
}>();

const iconSlot = 'app-tile-icon';
</script>

<template>
    <Link
        :href="href"
        class="group flex items-center gap-4 rounded-xl border border-borderline bg-surface p-5 transition hover:border-primary hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary-light text-primary">
            <slot name="icon" />
        </div>
        <div class="min-w-0">
            <div class="truncate text-base font-semibold text-ink-primary group-hover:text-primary">
                {{ title }}
            </div>
            <div v-if="subtitle" class="mt-0.5 truncate text-sm text-ink-secondary">
                {{ subtitle }}
            </div>
        </div>
    </Link>
</template>