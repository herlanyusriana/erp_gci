<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import { Link } from '@inertiajs/vue3';
import type { PurchaseOrder } from '@/types';

defineProps<{
    purchaseOrder: PurchaseOrder;
}>();

const statusTone = (s: string) => {
    switch (s) {
        case 'confirmed':
            return 'bg-info/10 text-info';
        case 'received':
            return 'bg-success/10 text-success';
        case 'cancelled':
            return 'bg-danger/10 text-danger';
        default:
            return 'bg-warning/10 text-warning';
    }
};
</script>

<template>
    <AppLayout>
        <BackButton :href="route('purchase-orders.index')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">PO {{ purchaseOrder.po_no }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ purchaseOrder.supplier?.supplier_name ?? '—' }}
                    · <span :class="statusTone(purchaseOrder.status)" class="rounded-md px-2 py-0.5 text-xs font-semibold uppercase">{{ purchaseOrder.status }}</span>
                </p>
            </div>
            <Link
                :href="route('purchase-orders.edit', purchaseOrder.id)"
                class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
            >
                Edit
            </Link>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">PO Date</div>
                <div class="mt-1 text-ink-primary">{{ purchaseOrder.po_date ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">Expected</div>
                <div class="mt-1 text-ink-primary">{{ purchaseOrder.expected_date ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">Notes</div>
                <div class="mt-1 text-ink-primary">{{ purchaseOrder.notes ?? '—' }}</div>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">Part</th>
                        <th class="px-4 py-3 text-right">Qty</th>
                        <th class="px-4 py-3">Unit</th>
                        <th class="px-4 py-3 text-right">Harga</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="item in purchaseOrder.items" :key="item.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ item.part?.part_number ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ item.qty }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ item.unit ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ item.price ?? '—' }}</td>
                    </tr>
                    <tr v-if="!purchaseOrder.items?.length">
                        <td colspan="4" class="px-4 py-12 text-center text-sm text-ink-secondary">Tidak ada item.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>