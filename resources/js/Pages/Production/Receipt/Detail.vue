<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';

const { t, locale } = useI18n();

interface InvoiceRow {
    invoice: string | null;
    supplier: string | null;
    uom: string | null;
    qty: number;
}

interface Detail {
    machine: { id: number; name: string; code: string | null };
    part: { id: number; number: string | null; name: string | null; model: string | null; size: string | null; type: string | null };
    uom: string | null;
    from: string;
    to: string;
    beginning: InvoiceRow[];
    received: InvoiceRow[];
    issued: InvoiceRow[];
    totals: { beginning: number; received: number; issued: number; ending: number };
}

const props = defineProps<{
    detail: Detail;
    from: string;
    to: string;
    timezone: string;
}>();

const periodLabel = () => (props.from === props.to ? props.from : `${props.from} - ${props.to}`);

const fmt = (n: number | null | undefined) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 4 });
</script>

<template>
    <AppLayout>
        <Head :title="`${detail.part.number ?? ''} · ${detail.machine.name}`" />
        <BackButton :href="route('production-receipts.index', { machine_id: detail.machine.id, from, to })" class="mb-4" />

        <div class="mb-5">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ detail.part.number ?? '—' }}</h1>
            <p class="mt-1 text-sm text-ink-secondary">
                {{ detail.part.name ?? '' }}
                <span v-if="detail.machine.name"> · {{ detail.machine.name }}</span>
                <span v-if="detail.machine.code" class="text-xs"> ({{ detail.machine.code }})</span>
                · {{ periodLabel() }}
            </p>
            <div class="mt-2 flex flex-wrap gap-1 text-[11px] text-ink-secondary">
                <span v-if="detail.part.type" class="rounded bg-primary/10 px-1.5 py-0.5 font-semibold uppercase text-primary">{{ detail.part.type }}</span>
                <span v-if="detail.part.model" class="rounded bg-background px-1.5 py-0.5">{{ detail.part.model }}</span>
                <span v-if="detail.part.size" class="rounded bg-background px-1.5 py-0.5">{{ detail.part.size }}</span>
            </div>
        </div>

        <!-- Ringkasan -->
        <div class="mb-5 grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.beginningStock') }}</div>
                <div class="mt-1 text-lg font-bold tabular-nums text-ink-primary">{{ fmt(detail.totals.beginning) }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.receivedQty') }}</div>
                <div class="mt-1 text-lg font-bold tabular-nums text-success">{{ fmt(detail.totals.received) }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.issuedQty') }}</div>
                <div class="mt-1 text-lg font-bold tabular-nums text-warning">{{ fmt(detail.totals.issued) }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.endingStock') }}</div>
                <div class="mt-1 text-lg font-bold tabular-nums text-ink-primary">
                    {{ fmt(detail.totals.ending) }} <span class="text-xs font-normal text-ink-secondary">{{ detail.uom ?? '' }}</span>
                </div>
            </div>
        </div>

        <!-- Tiga bagian per sumber -->
        <div class="space-y-5">
            <section
                v-for="block in [
                    { key: 'beginning', title: t('production.detailBegin'), rows: detail.beginning, tone: 'text-ink-primary' },
                    { key: 'received', title: t('production.detailReceived'), rows: detail.received, tone: 'text-success' },
                    { key: 'issued', title: t('production.detailIssued'), rows: detail.issued, tone: 'text-warning' },
                ]"
                :key="block.key"
                class="overflow-hidden rounded-xl border border-borderline bg-surface"
            >
                <div class="border-b border-borderline px-4 py-3 text-sm font-semibold text-ink-primary">{{ block.title }}</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-borderline text-sm">
                        <thead class="bg-background">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                <th scope="col" class="px-4 py-2.5">{{ t('production.invoice') }}</th>
                                <th scope="col" class="px-4 py-2.5">{{ t('production.supplier') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right">{{ t('production.qty') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-borderline">
                            <tr v-for="(row, i) in block.rows" :key="`${block.key}-${i}`">
                                <td class="px-4 py-2.5 font-medium text-ink-primary">{{ row.invoice ?? t('production.noInvoice') }}</td>
                                <td class="px-4 py-2.5 text-ink-secondary">{{ row.supplier ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums" :class="block.tone">
                                    {{ fmt(row.qty) }} <span class="text-xs font-normal text-ink-secondary">{{ row.uom ?? '' }}</span>
                                </td>
                            </tr>
                            <tr v-if="block.rows.length === 0">
                                <td colspan="3" class="px-4 py-8 text-center text-sm text-ink-secondary">{{ t('production.detailEmpty') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <p class="mt-4 text-xs text-ink-secondary">{{ timezone }}</p>
    </AppLayout>
</template>