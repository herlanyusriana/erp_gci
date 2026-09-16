<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import LauncherTile from '@/Components/LauncherTile.vue';

const items = [
    { href: '/purchase-orders', title: 'Purchase Order', subtitle: 'PO supplier + item (qty, harga)' },
    { href: '/incoming-arrivals', title: 'Arrival', subtitle: 'Incoming shipment · item · container · BL/PEN/AJU' },
    { href: '/local-pos', title: 'Local PO', subtitle: 'PO lokal · tanpa vessel/container · receive langsung' },
    { href: '/receive', title: 'Receive', subtitle: 'Terima barang (FIFO tag) → posting stok' },
    { href: '/stocks', title: 'Stock', subtitle: 'Stok per part + tag' },
];
</script>

<template>
    <AppLayout>
        <BackButton :href="route('launcher')" class="mb-4" />

        <h1 class="mb-2 text-2xl font-bold tracking-tight text-ink-primary">INCOMING</h1>
        <p class="mb-6 text-sm text-ink-secondary">Pilih entitas incoming / receiving.</p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <LauncherTile v-for="it in items" :key="it.title" :href="it.href" :title="it.title" :subtitle="it.subtitle">
                <template #icon>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                    </svg>
                </template>
            </LauncherTile>
        </div>
    </AppLayout>
</template>