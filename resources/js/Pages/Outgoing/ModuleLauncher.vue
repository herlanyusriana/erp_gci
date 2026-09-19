<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import LauncherTile from '@/Components/LauncherTile.vue';

const { t } = useI18n();

const items = [
    { href: '/material-issues', title: 'outgoing.materialIssue', subtitle: 'outgoing.materialIssueTile' },
];
</script>

<template>
    <Head :title="t('outgoing.title')" />
    <AppLayout>
        <BackButton :href="route('launcher')" class="mb-4" />

        <h1 class="mb-2 text-2xl font-bold tracking-tight text-ink-primary">{{ t('outgoing.title') }}</h1>
        <p class="mb-6 text-sm text-ink-secondary">{{ t('outgoing.chooseEntity') }}</p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <LauncherTile v-for="it in items" :key="it.title" :href="it.href" :title="t(it.title)" :subtitle="t(it.subtitle)">
                <template #icon>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                </template>
            </LauncherTile>
        </div>
    </AppLayout>
</template>
