<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import LauncherTile from '@/Components/LauncherTile.vue';

const items = [
    { href: '/parts', title: 'Part', subtitle: 'All Parts · Finished Goods · Materials · WIP' },
    { href: '/boms', title: 'BOM', subtitle: 'Bill of Material · Routing + Structure' },
    { href: '/suppliers', title: 'Supplier', subtitle: 'Master Supplier' },
    { href: '/trucking-companies', title: 'Trucking Company', subtitle: 'Master perusahaan trucking' },
    { href: '/substitutes', title: 'Material Substitute', subtitle: 'Substitute parts + supplier + source' },
    { href: '/machines', title: 'Machine', subtitle: 'Master Machine' },
    { href: '/processes', title: 'Process', subtitle: 'Master Process' },
    { href: '/uoms', title: 'UOM', subtitle: 'Unit of Measure' },
];
</script>

<template>
    <AppLayout>
        <BackButton :href="route('launcher')" class="mb-4" />

        <h1 class="mb-2 text-2xl font-bold tracking-tight text-ink-primary">MASTER DATA</h1>
        <p class="mb-6 text-sm text-ink-secondary">Pilih entitas master data.</p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <LauncherTile v-for="it in items" :key="it.title" :href="it.href" :title="it.title" :subtitle="it.subtitle">
                <template #icon>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                    </svg>
                </template>
            </LauncherTile>
        </div>
    </AppLayout>
</template>