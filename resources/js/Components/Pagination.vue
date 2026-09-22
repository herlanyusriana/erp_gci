<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{
    links: Array<{ url: string | null; label: string; active: boolean }>;
}>();
</script>

<template>
    <nav v-if="links.length > 3" :aria-label="t('common.pagination')" class="mt-4 flex items-center justify-between">
        <div class="flex flex-1 flex-wrap justify-between gap-1 sm:hidden">
            <Link
                v-for="(link, i) in links"
                :key="i"
                :href="link.url ?? '#'"
                :class="[
                    'relative inline-flex items-center rounded-md border px-4 py-2 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1',
                    link.active
                        ? 'border-primary bg-primary text-white'
                        : link.url
                          ? 'border-borderline bg-surface text-ink-primary hover:bg-primary-light'
                          : 'border-borderline bg-background text-ink-secondary opacity-60',
                ]"
                :aria-disabled="!link.url"
            >
                <span>{{ i === 0 ? `« ${t('common.previous')}` : i === links.length - 1 ? `${t('common.next')} »` : link.label }}</span>
            </Link>
        </div>
        <div class="hidden flex-1 items-center justify-between sm:flex">
            <div class="text-sm text-ink-secondary">
                {{ t('common.page') }}
                <span class="font-medium text-ink-primary">{{
                    links.find((l) => l.active)?.label ?? '1'
                }}</span>
            </div>
            <div class="flex gap-1">
                <Link
                    v-for="(link, i) in links"
                    :key="i"
                    :href="link.url ?? '#'"
                    :class="[
                        'inline-flex min-w-[2.25rem] items-center justify-center rounded-md border px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1',
                        link.active
                            ? 'border-primary bg-primary text-white'
                            : link.url
                              ? 'border-borderline bg-surface text-ink-primary hover:bg-primary-light'
                              : 'border-borderline bg-background text-ink-secondary opacity-60',
                    ]"
                    :aria-disabled="!link.url"
                >
                    <span>{{ i === 0 ? `« ${t('common.previous')}` : i === links.length - 1 ? `${t('common.next')} »` : link.label }}</span>
                </Link>
            </div>
        </div>
    </nav>
</template>