<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatDate } from '@/utils/date';
import type { IncomingArrival, IncomingReceive } from '@/types';

const { t } = useI18n();

interface PendingRow {
    item: IncomingArrival['items'] extends (infer T)[] | undefined ? T : never;
    planned: number;
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
    weightUnit: string;
}>();

const receiveRows = computed<ReceiveRow[]>(() =>
    (props.arrival.items ?? []).flatMap((item) =>
        (item.receives ?? []).map((receive) => ({ item, receive })),
    ),
);

function removeReceive(r: IncomingReceive) {
    if (confirm(t('incoming.deleteReceiveConfirm', { tag: r.tag }))) {
        router.delete(route('receive.destroy', r.id));
    }
}
</script>

<template>
    <Head :title="t('incoming.receiveArrivalTitle', { number: arrival.arrival_no })" />
    <AppLayout>
        <BackButton :href="route('receive.index')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">
                    {{ t('incoming.receiveArrivalTitle', { number: arrival.arrival_no }) }}
                </h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ arrival.supplier?.supplier_name ?? '—' }} · {{ arrival.invoice_no ?? '—' }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span
                    v-if="arrival.iqc_status === 'passed'"
                    class="inline-flex items-center gap-1 rounded-full bg-success/15 px-3 py-1 text-xs font-bold text-success"
                >
                    ✓ IQC PASS
                </span>
                <span
                    v-else
                    class="inline-flex items-center gap-1 rounded-full bg-warning/15 px-3 py-1 text-xs font-semibold text-warning"
                >
                    ● MENUNGGU IQC
                </span>
            </div>
        </div>

        <!-- Info Cards -->
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.arrivalNo') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ arrival.arrival_no }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.supplier') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ arrival.supplier?.supplier_name ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.invoice') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ arrival.invoice_no ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.arrivalDate') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ formatDate(arrival.invoice_date) }}</div>
            </div>
        </div>

        <!-- Tabel Item Siap Diterima -->
        <div class="mb-8">
            <h2 class="mb-3 text-base font-semibold text-ink-primary">{{ t('incoming.itemsReadyToReceive') }}</h2>
            <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-borderline text-sm">
                        <thead class="bg-background">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                <th scope="col" class="px-4 py-3">{{ t('incoming.part') }}</th>
                                <th scope="col" class="px-4 py-3">{{ t('incoming.groupSize') }}</th>
                                <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.plannedQty') }}</th>
                                <th scope="col" class="px-4 py-3">{{ t('incoming.unit') }}</th>
                                <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.received') }}</th>
                                <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.remaining') }}</th>
                                <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-borderline">
                            <tr v-for="p in pending" :key="p.item.id" class="hover:bg-primary-light/40">
                                <td class="px-4 py-3 font-medium text-ink-primary">
                                    {{ p.item.part?.part_number ?? '—' }}
                                    <div class="text-xs text-ink-secondary">{{ p.item.part?.part_name ?? '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-ink-primary">{{ p.item.material_group ?? '—' }} · {{ p.item.size ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ p.planned }}</td>
                                <td class="px-4 py-3 text-ink-primary">{{ p.item.unit_goods ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ p.received }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold" :class="p.remaining > 0 ? 'text-warning' : 'text-success'">
                                    {{ p.remaining }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link
                                        v-if="p.remaining > 0 && arrival.iqc_status === 'passed'"
                                        :href="route('receive.create', p.item.id)"
                                        class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                    >
                                        {{ t('incoming.receiveGoods') }}
                                    </Link>
                                    <span v-else-if="p.remaining <= 0" class="inline-flex items-center text-xs font-semibold text-success">
                                        ✓ Selesai
                                    </span>
                                    <span v-else class="text-xs text-ink-secondary italic">
                                        Menunggu QC
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!pending.length">
                                <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noItems') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tabel Riwayat Tag yang Sudah Diterima -->
        <div>
            <h2 class="mb-3 text-base font-semibold text-ink-primary">{{ t('incoming.receiveTagHistory') }}</h2>
            <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-borderline text-sm">
                        <thead class="bg-background">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                <th scope="col" class="px-4 py-3">{{ t('incoming.part') }}</th>
                                <th scope="col" class="px-4 py-3">{{ t('incoming.tag') }}</th>
                                <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.qty') }}</th>
                                <th scope="col" class="px-4 py-3 text-right">Berat Bersih (KGM)</th>
                                <th scope="col" class="px-4 py-3 text-right">Kemasan</th>
                                <th scope="col" class="px-4 py-3">{{ t('incoming.date') }}</th>
                                <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-borderline">
                            <tr v-for="r in receiveRows" :key="r.receive.id" class="hover:bg-primary-light/40">
                                <td class="px-4 py-3 font-medium text-ink-primary">
                                    {{ r.item.part?.part_number ?? '—' }}
                                    <div class="text-xs text-ink-secondary">{{ r.item.material_group ?? '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-ink-primary font-mono font-medium">{{ r.receive.tag ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-ink-primary font-semibold">
                                    {{ r.receive.qty }} {{ r.receive.qty_unit ?? '' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-ink-primary">
                                    {{ r.receive.net_weight ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right text-xs text-ink-secondary">
                                    <span v-if="r.receive.bundle_count || r.receive.bundle_qty">
                                        {{ r.receive.bundle_count ?? r.receive.bundle_qty }} {{ r.receive.bundle_unit ?? 'PALLET' }}
                                        <span v-if="r.receive.packing_count"> @ {{ r.receive.packing_count }} {{ r.receive.packing_type ?? 'BOX' }}</span>
                                    </span>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-4 py-3 text-ink-primary whitespace-nowrap">{{ formatDate(r.receive.ata_date) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-1.5">
                                        <a
                                            :href="route('receive.label', r.receive.id)"
                                            target="_blank"
                                            rel="noopener"
                                            class="rounded-md border border-borderline px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                        >
                                            {{ t('incoming.label') }}
                                        </a>
                                        <ActionButton :href="route('receive.edit', r.receive.id)" :label="t('incoming.edit')" variant="edit" />
                                        <ActionButton :label="t('incoming.delete')" variant="delete" @click="removeReceive(r.receive)" />
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!receiveRows.length">
                                <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noReceipts') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
