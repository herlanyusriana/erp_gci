<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { IncomingArrival, IncomingReceive } from '@/types';

const { t } = useI18n();

interface PendingRow {
    item: IncomingArrival['items'] extends (infer T)[] | undefined ? T : never;
    received: number;
    remaining: number;
}

interface ReceiveRow {
    item: PendingRow['item'];
    receive: IncomingReceive;
}

const props = defineProps<{
    arrival: IncomingArrival;
    pending: PendingRow[];
}>();

const receiveRows = computed<ReceiveRow[]>(() =>
    (props.arrival.items ?? []).flatMap((item) =>
        (item.receives ?? []).map((receive) => ({ item, receive })),
    ),
);

const statusTone = (s: string) => {
    switch (s) {
        case 'completed':
            return 'bg-success/10 text-success';
        case 'cancelled':
            return 'bg-danger/10 text-danger';
        default:
            return 'bg-warning/10 text-warning';
    }
};

function removeItem(id: number) {
    // placeholder no-op; keep for future per-item delete wiring
}
</script>

<template>
    <Head :title="t('incoming.arrivalTitle', { number: arrival.arrival_no })" />
    <AppLayout>
        <BackButton :href="route('incoming-arrivals.index')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ arrival.arrival_no }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ arrival.supplier?.supplier_name ?? '—' }}
                    <span v-if="arrival.trucking">· {{ t('incoming.truckingValue', { name: arrival.trucking.company_name }) }}</span>
                    · {{ t('incoming.invoiceValue', { number: arrival.invoice_no ?? '—' }) }}
                    · <span :class="statusTone(arrival.status)" class="rounded-md px-2 py-0.5 text-xs font-semibold uppercase">{{ t('incoming.status_' + arrival.status) }}</span>
                    <span v-if="arrival.transaction_no" class="ml-2 rounded-md bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">{{ t('incoming.salesOrder', { number: arrival.transaction_no }) }}</span>
                </p>
            </div>
            <div class="flex gap-2">
                <a :href="route('incoming-arrivals.invoice', arrival.id)" target="_blank" rel="noopener" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">{{ t('incoming.printInvoice') }}</a>
                <a :href="route('incoming-arrivals.export', arrival.id)" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background">{{ t('incoming.exportExcel') }}</a>
                <a :href="route('incoming-arrivals.pdf', arrival.id)" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background">{{ t('incoming.invoicePdf') }}</a>
                <Link :href="route('incoming-arrivals.edit', arrival.id)" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background">{{ t('incoming.edit') }}</Link>
                <Link :href="route('receive.index')" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background">{{ t('incoming.receive') }}</Link>
            </div>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.vessel') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrival.vessel ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.etaGci') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrival.eta_gci ?? arrival.eta ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.billOfLading') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrival.bill_of_lading ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.penAju') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrival.pen_no ?? '—' }} / {{ arrival.aju_no ?? '—' }}</div>
            </div>
        </div>

        <!-- Items -->
        <h2 class="mb-3 text-base font-semibold text-ink-primary">{{ t('incoming.items') }}</h2>
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">{{ t('incoming.part') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.groupSize') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.qtyGoods') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.unit') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.receivedUnit', { unit: 'KGM' }) }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.remainingUnit', { unit: 'KGM' }) }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="p in pending" :key="p.item.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">
                            {{ p.item.part?.part_number ?? '—' }}
                            <div class="text-xs text-ink-secondary">{{ p.item.part?.part_name ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">{{ p.item.material_group ?? '—' }} · {{ p.item.size ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ p.item.qty_goods }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ p.item.unit_goods ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ p.received }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold" :class="p.remaining > 0 ? 'text-warning' : 'text-success'">{{ p.remaining }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :href="route('receive.create', p.item.id)" :label="t('incoming.receiveAction')" variant="view" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!pending.length">
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noItems') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Containers -->
        <h2 class="mb-3 mt-8 text-base font-semibold text-ink-primary">{{ t('incoming.receiveTag') }}</h2>
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">{{ t('incoming.part') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.tag') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.qty') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.netUnit', { unit: 'KGM' }) }}</th>
                        <th class="px-4 py-3">{{ t('incoming.date') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="r in receiveRows" :key="r.receive.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">
                            {{ r.item.part?.part_number ?? '—' }}
                            <div class="text-xs text-ink-secondary">{{ r.item.material_group ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">{{ r.receive.tag ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ r.receive.qty }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ r.receive.net_weight }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ r.receive.ata_date ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <a :href="route('receive.label', r.receive.id)" target="_blank" rel="noopener" class="rounded-md border border-borderline px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-light">{{ t('incoming.label') }}</a>
                                <ActionButton :href="route('receive.edit', r.receive.id)" :label="t('incoming.edit')" variant="edit" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!receiveRows.length">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noReceipts') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Containers -->
        <h2 class="mb-3 mt-8 text-base font-semibold text-ink-primary">{{ t('incoming.containers') }}</h2>
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">{{ t('incoming.containerNo') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.seal') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.size') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.inspection') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="c in arrival.containers" :key="c.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ c.container_no }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ c.seal_code ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ c.size ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span v-if="c.inspection" :class="c.inspection.status === 'ok' ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'" class="rounded-md px-2 py-0.5 text-xs font-semibold uppercase">{{ t('incoming.status_' + c.inspection.status) }}</span>
                            <span v-else class="rounded-md bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">{{ t('incoming.notInspected') }}</span>
                        </td>
                    </tr>
                    <tr v-if="!arrival.containers?.length">
                        <td colspan="4" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noContainers') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>