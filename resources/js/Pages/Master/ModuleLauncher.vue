<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import LauncherTile from '@/Components/LauncherTile.vue';

const { t } = useI18n();

const items = [
    { href: '/parts', title: 'master.part', subtitle: 'master.launcherPartSubtitle' },
    { href: '/boms', title: 'master.bom', subtitle: 'master.launcherBomSubtitle' },
    { href: '/suppliers', title: 'master.supplier', subtitle: 'master.supplierTitle' },
    { href: '/trucking-companies', title: 'master.trucking', subtitle: 'master.truckingTitle' },
    { href: '/substitutes', title: 'master.substituteTitle', subtitle: 'master.launcherSubstituteSubtitle' },
    { href: '/machines', title: 'master.machine', subtitle: 'master.machineTitle' },
    { href: '/processes', title: 'master.process', subtitle: 'master.processTitle' },
    { href: '/uoms', title: 'master.uom', subtitle: 'master.unitOfMeasure' },
];
</script>

<template>
    <AppLayout>
        <BackButton :href="route('launcher')" class="mb-4" />

        <h1 class="mb-2 text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.launcherTitle') }}</h1>
        <p class="mb-6 text-sm text-ink-secondary">{{ t('master.launcherSubtitle') }}</p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <LauncherTile v-for="it in items" :key="it.title" :href="it.href" :title="t(it.title)" :subtitle="t(it.subtitle)">
                <template #icon>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                    </svg>
                </template>
            </LauncherTile>
        </div>
    </AppLayout>
</template>