<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import { Link } from '@inertiajs/vue3';
import type { IncomingArrival } from '@/types';

interface PendingRow {
    item: IncomingArrival['items'] extends (infer T)[] | undefined ? T : never;
    received: number;
    remaining: number;
}

defineProps<{
    arrival: IncomingArrival;
    pending: PendingRow[];
}>();

const statusTone = (s: string) => {
    switch (s) {
        case 'completed': return 'bg-success/10 text-success';
        case 'cancelled': return 'bg-danger/10 text-danger';
        default: return 'bg-warning/10 text-warning';
    }
};
</script>

<template>
    <AppLayout>
        <BackButton :href="route('local-pos.index')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ arrival.invoice_no }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ arrival.supplier?.supplier_name ?? '—' }}
                    · {{ arrival.arrival_no }}
                    · <span :class="statusTone(arrival.status)" class="rounded-md px-2 py-0.5 text-xs font-semibold uppercase">{{ arrival.status }}</span>
                    <span v-if="arrival.transaction_no" class="ml-2 rounded-md bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">SO: {{ arrival.transaction_no }}</span>
                </p>
            </div>
            <div class="flex gap-2">
                <a :href="route('local-pos.export-detail', arrival.id)" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background">Export Excel</a>
                <Link :href="route('local-pos.edit', arrival.id)" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">Edit</Link>
                <Link :href="route('receive.index')" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background">Receive</Link>
            </div>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">PO Date</div>
                <div class="mt-1 text-ink-primary">{{ arrival.invoice_date ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">Currency</div>
                <div class="mt-1 text-ink-primary">{{ arrival.currency ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">Notes</div>
                <div class="mt-1 text-ink-primary">{{ arrival.notes ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">Supplier</div>
                <div class="mt-1 text-ink-primary">{{ arrival.supplier?.supplier_name ?? '—' }}</div>
            </div>
        </div>

        <h2 class="mb-3 text-base font-semibold text-ink-primary">Items</h2>
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">Part</th>
                        <th class="px-4 py-3">Size</th>
                        <th class="px-4 py-3 text-right">Qty</th>
                        <th class="px-4 py-3">Unit</th>
                        <th class="px-4 py-3 text-right">Price</th>
                        <th class="px-4 py-3 text-right">Received</th>
                        <th class="px-4 py-3 text-right">Sisa</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
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
                                <ActionButton :href="route('receive.create', p.item.id)" label="Terima" variant="view" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!pending.length">
                        <td colspan="8" class="px-4 py-12 text-center text-sm text-ink-secondary">Tidak ada item.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
