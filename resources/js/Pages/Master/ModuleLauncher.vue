<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import LauncherTile from '@/Components/LauncherTile.vue';
import { computed } from 'vue';
import { hasAnyPermission } from '@/utils/permissions';

const { t } = useI18n();

const items = computed(() =>
    [
        { href: '/parts', title: 'master.part', subtitle: 'master.launcherPartSubtitle', permission: ['part.view'] },
        { href: '/boms', title: 'master.bom', subtitle: 'master.launcherBomSubtitle', permission: ['bom.view'] },
        { href: '/suppliers', title: 'master.supplier', subtitle: 'master.supplierTitle', permission: ['supplier.view'] },
        { href: '/customers', title: 'outgoing.customerTitle', subtitle: 'outgoing.customerSubtitle', permission: ['customer.view'] },
        { href: '/trucking-companies', title: 'master.trucking', subtitle: 'master.truckingTitle', permission: ['trucking.view'] },
        { href: '/substitutes', title: 'master.substituteTitle', subtitle: 'master.launcherSubstituteSubtitle', permission: ['part_substitute.view'] },
        { href: '/machines', title: 'master.machine', subtitle: 'master.machineTitle', permission: ['machine.view'] },
        { href: '/locations', title: 'master.location', subtitle: 'master.locationTitle', permission: ['location.view'] },
        { href: '/cycle-times', title: 'master.cycleTimeTitle', subtitle: 'master.launcherCycleTimeSubtitle', permission: ['machine.view', 'process.view'] },
        { href: '/prices', title: 'master.priceMaster', subtitle: 'master.launcherPriceSubtitle', permission: ['part.view'] },
        { href: '/processes', title: 'master.process', subtitle: 'master.processTitle', permission: ['process.view'] },
        { href: '/uoms', title: 'master.uom', subtitle: 'master.unitOfMeasure', permission: ['uom.view'] },
    ].filter((it) => hasAnyPermission(it.permission)),
);
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