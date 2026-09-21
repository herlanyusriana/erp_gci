<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import { Head, Link } from '@inertiajs/vue3';
import type { IncomingArrival } from '@/types';

const { t } = useI18n();

interface PendingRow {
    item: IncomingArrival['items'] extends (infer T)[] | undefined ? T : never;
    received: number;
    remaining: number;
}

defineProps<{
    arrival: IncomingArrival;
    pending: PendingRow[];
}>();

</script>

<template>
    <Head :title="t('incoming.poTitle', { number: arrival.po_no ?? arrival.arrival_no })" />
    <AppLayout>
        <BackButton :href="route('local-pos.index')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ arrival.po_no ?? '—' }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ arrival.supplier?.supplier_name ?? '—' }}
                    <span v-if="arrival.invoice_no">· {{ t('incoming.invoiceNo') }}: {{ arrival.invoice_no }}</span>
                    · {{ arrival.arrival_no }}
                    · <StatusBadge uppercase :status="arrival.status">{{ t('incoming.status_' + arrival.status) }}</StatusBadge>
                    <span v-if="arrival.transaction_no" class="ml-2 rounded-md bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">{{ t('incoming.salesOrder', { number: arrival.transaction_no }) }}</span>
                </p>
            </div>
            <div class="flex gap-2">
                <a :href="route('local-pos.export-detail', arrival.id)" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background">{{ t('incoming.exportExcel') }}</a>
                <Link :href="route('local-pos.edit', arrival.id)" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">{{ t('incoming.edit') }}</Link>
                <Link :href="route('receive.index')" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background">{{ t('incoming.receive') }}</Link>
            </div>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.invoiceNo') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrival.invoice_no ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.poDate') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrival.invoice_date ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.currency') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrival.currency ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.notes') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrival.notes ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.supplier') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrival.supplier?.supplier_name ?? '—' }}</div>
            </div>
        </div>

        <h2 class="mb-3 text-base font-semibold text-ink-primary">{{ t('incoming.items') }}</h2>
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">{{ t('incoming.part') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.size') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.qty') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.unit') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.price') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.received') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.remaining') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="p in pending" :key="p.item.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">
                            {{ p.item.part?.part_number ?? '—' }}
                            <div class="text-xs text-ink-secondary">{{ p.item.part?.part_name ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">{{ p.item.size ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ p.item.qty_goods }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ p.item.unit_goods ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ p.item.price ?? 0 }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ p.received }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold" :class="p.remaining > 0 ? 'text-warning' : 'text-success'">{{ p.remaining }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :href="route('receive.create', p.item.id)" :label="t('incoming.receiveAction')" variant="view" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!pending.length">
                        <td colspan="8" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noItems') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
