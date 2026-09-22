<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import LauncherTile from '@/Components/LauncherTile.vue';

const { t } = useI18n();

const items = computed(() => [
    {
        href: '/production-plans',
        title: t('production.plan'),
        subtitle: t('production.planDescription'),
        paths: [
            'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
        ],
    },
    {
        href: '/production-results',
        title: t('production.results'),
        subtitle: t('production.resultsIndexDescription'),
        paths: [
            'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        ],
    },
    {
        href: '/work-orders',
        title: t('production.workOrder'),
        subtitle: t('production.woDescription'),
        paths: [
            'M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z',
        ],
    },
]);
</script>

<template>
    <AppLayout>
        <Head :title="t('production.title')" />
        <BackButton :href="route('launcher')" class="mb-4" />

        <h1 class="mb-2 text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.title') }}</h1>
        <p class="mb-6 text-sm text-ink-secondary">{{ t('production.chooseEntity') }}</p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <LauncherTile v-for="it in items" :key="it.title" :href="it.href" :title="it.title" :subtitle="it.subtitle">
                <template #icon>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path
                            v-for="(d, i) in it.paths"
                            :key="i"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            :d="d"
                        />
                    </svg>
                </template>
            </LauncherTile>
        </div>
    </AppLayout>
</template>
