<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import { Head, Link } from '@inertiajs/vue3';
import { formatDate } from '@/utils/date';
import type { PurchaseOrder } from '@/types';

const { t } = useI18n();

defineProps<{
    purchaseOrder: PurchaseOrder;
}>();

</script>

<template>
    <Head :title="t('incoming.poTitle', { number: purchaseOrder.po_no })" />
    <AppLayout>
        <BackButton :href="route('purchase-orders.index')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.poTitle', { number: purchaseOrder.po_no }) }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ purchaseOrder.supplier?.supplier_name ?? '—' }}
                    · <StatusBadge uppercase :status="purchaseOrder.status">{{ t('incoming.status_' + purchaseOrder.status) }}</StatusBadge>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a
                    :href="route('purchase-orders.print', purchaseOrder.id)"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1.5 rounded-md border border-borderline bg-surface px-4 py-2 text-sm font-semibold text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5z" />
                    </svg>
                    {{ t('incoming.printPo') }}
                </a>
                <Link
                    :href="route('purchase-orders.edit', purchaseOrder.id)"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                >
                    {{ t('incoming.edit') }}
                </Link>
            </div>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.poDate') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ formatDate(purchaseOrder.po_date) }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.expected') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ formatDate(purchaseOrder.expected_date) }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.notes') }}</div>
                <div class="mt-1 text-ink-primary">{{ purchaseOrder.notes ?? '—' }}</div>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
                        <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('incoming.part') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('incoming.size') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.qty') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('incoming.unit') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.price') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="item in purchaseOrder.items" :key="item.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3 font-medium text-ink-primary">{{ item.part?.part_number ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-primary">{{ item.size ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ item.qty }}</td>
                            <td class="px-4 py-3 text-ink-primary">{{ item.unit ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ item.price ?? '—' }}</td>
                        </tr>
                        <tr v-if="!purchaseOrder.items?.length">
                            <td colspan="5" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noItems') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>