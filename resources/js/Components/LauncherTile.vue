<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    href: string;
    title: string;
    subtitle?: string;
    disabled?: boolean;
    /** Teks badge saat disabled; default `common.soon`. */
    badge?: string;
}>();

const { t } = useI18n();

const iconSlot = 'app-tile-icon';

const baseClass =
    'group flex items-center gap-4 rounded-xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary';
const activeClass = 'border-borderline bg-surface hover:border-primary hover:shadow-md';
const disabledClass = 'cursor-not-allowed select-none border-borderline bg-surface/50 opacity-60';
</script>

<template>
    <!-- Tile nonaktif: sengaja bukan <Link> agar tidak memicu navigasi/riwayat. -->
    <div
        v-if="props.disabled"
        :class="[baseClass, disabledClass]"
        aria-disabled="true"
        data-disabled="true"
    >
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary-light/60 text-primary/60">
            <slot name="icon" />
        </div>
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <div class="truncate text-base font-semibold text-ink-secondary">{{ title }}</div>
                <span
                    class="shrink-0 rounded-full bg-ink-secondary/10 px-2 py-0.5 text-xs font-semibold text-ink-secondary"
                >
                    {{ badge ?? t('common.soon') }}
                </span>
            </div>
            <div v-if="subtitle" class="mt-0.5 truncate text-sm text-ink-secondary">{{ subtitle }}</div>
        </div>
    </div>

    <Link
        v-else
        :href="href"
        :class="[baseClass, activeClass]"
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