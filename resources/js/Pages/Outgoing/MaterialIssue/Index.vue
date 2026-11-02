<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import Modal from '@/Components/Modal.vue';

const { t, locale } = useI18n();

interface WoRow {
    work_order_id: number;
    wo_no: string;
    wo_status: string;
    fg_part_number: string | null;
    fg_part_name: string | null;
    slip_count: number;
    first_issued_date: string | null;
    last_issued_date: string | null;
    materials: Array<{ part_id: number; part_number: string | null; part_name: string | null; qty: number; uom: string | null }>;
    status_by_uom: Array<{ uom: string; issued_qty: number; required_qty: number }>;
}

interface DetailLine {
    slip_no: string;
    issue_date: string | null;
    part_number: string | null;
    part_name: string | null;
    tag: string | null;
    qty: number;
    uom: string | null;
    invoice: string | null;
    supplier: string | null;
}

const props = defineProps<{
    rows: WoRow[];
    filters: { search?: string; date_from?: string; date_to?: string };
    summary: { work_order_count: number; slip_count: number; tag_count: number };
}>();

const search = ref(props.filters.search ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;

function applyFilters() {
    clearTimeout(timer);
    timer = setTimeout(
        () => router.get(route('material-issues.index'), {
            search: search.value || undefined,
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
        }, { preserveState: true, replace: true }),
        300,
    );
}
watch([search, dateFrom, dateTo], applyFilters);

const fmt = (n: number | null | undefined) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 4 });

/** "400 / 700 KGM · 5 / 20 PCS" — rasio issued terhadap required. */
const statusLabel = (row: WoRow): string =>
    row.status_by_uom.length === 0
        ? '—'
        : row.status_by_uom.map((s) => `${fmt(s.issued_qty)} / ${fmt(s.required_qty)} ${s.uom ?? ''}`).join(' · ');

const itemLabel = (row: WoRow): string =>
    row.materials.length === 0
        ? '—'
        : row.materials.map((m) => m.part_number ?? '—').join(', ');

// ── Modal detail ──
const detailOpen = ref(false);
const detailRow = ref<WoRow | null>(null);
const detailLines = ref<DetailLine[]>([]);
const detailLoading = ref(false);
const detailError = ref('');

async function openDetail(row: WoRow) {
    detailRow.value = row;
    detailOpen.value = true;
    detailLines.value = [];
    detailError.value = '';
    detailLoading.value = true;

    const params = new URLSearchParams();
    if (dateFrom.value) params.set('date_from', dateFrom.value);
    if (dateTo.value) params.set('date_to', dateTo.value);

    try {
        const res = await fetch(route('material-issues.detail', row.work_order_id) + (params.toString() ? `?${params}` : ''), {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error(String(res.status));
        const body = await res.json();
        detailLines.value = body.data ?? [];
    } catch {
        detailError.value = t('outgoing.detailFailed');
    } finally {
        detailLoading.value = false;
    }
}

function closeDetail() {
    detailOpen.value = false;
    detailRow.value = null;
    detailLines.value = [];
}
</script>

<template>
    <Head :title="t('outgoing.monitoringIssueOut')" />
    <AppLayout>
        <BackButton :href="route('outgoing-data')" class="mb-4" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('outgoing.monitoringIssueOut') }}</h1>
            <p class="mt-1 text-sm text-ink-secondary">{{ t('outgoing.monitoringDescription') }}</p>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <label class="text-sm text-ink-secondary">
                <span class="mb-1 block font-medium">{{ t('outgoing.search') }}</span>
                <input v-model="search" type="search" :placeholder="t('outgoing.searchWo')" :aria-label="t('outgoing.search')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </label>
            <label class="text-sm text-ink-secondary">
                <span class="mb-1 block font-medium">{{ t('outgoing.dateFrom') }}</span>
                <input v-model="dateFrom" type="date" :aria-label="t('outgoing.dateFrom')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </label>
            <label class="text-sm text-ink-secondary">
                <span class="mb-1 block font-medium">{{ t('outgoing.dateTo') }}</span>
                <input v-model="dateTo" type="date" :aria-label="t('outgoing.dateTo')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </label>
        </div>

        <div class="mb-6 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.summaryWorkOrders') }}</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-ink-primary">{{ summary.work_order_count }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.summarySlips') }}</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-ink-primary">{{ summary.slip_count }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.summaryTags') }}</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-ink-primary">{{ summary.tag_count }}</div>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.workOrder') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.issueDate') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.item') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.status') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.lastIssued') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('outgoing.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="row in rows" :key="row.work_order_id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-ink-primary">{{ row.wo_no }}</div>
                                <div class="text-xs text-ink-secondary">{{ row.fg_part_number ?? '' }} {{ row.fg_part_name ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 tabular-nums text-ink-primary">{{ row.first_issued_date ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="text-ink-primary">{{ itemLabel(row) }}</div>
                                <div class="text-xs text-ink-secondary">{{ t('outgoing.slipCount', { n: row.slip_count }) }}</div>
                            </td>
                            <td class="px-4 py-3 tabular-nums text-ink-primary">{{ statusLabel(row) }}</td>
                            <td class="px-4 py-3 tabular-nums text-ink-primary">{{ row.last_issued_date ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                    @click="openDetail(row)"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                    {{ t('outgoing.viewDetail') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('outgoing.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal detail per WO -->
        <Modal :show="detailOpen" max-width="2xl" @close="closeDetail">
            <div class="p-6">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-ink-primary">{{ detailRow?.wo_no }}</h2>
                        <p class="text-sm text-ink-secondary">{{ detailRow?.fg_part_number }} · {{ detailRow?.fg_part_name }}</p>
                    </div>
                    <button type="button" class="rounded-md p-1.5 text-ink-secondary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" :aria-label="t('common.close')" @click="closeDetail">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <p v-if="detailLoading" class="py-8 text-center text-sm text-ink-secondary">{{ t('common.loading') }}</p>
                <p v-else-if="detailError" class="py-8 text-center text-sm text-danger">{{ detailError }}</p>

                <div v-else class="overflow-x-auto rounded-lg border border-borderline">
                    <table class="min-w-full divide-y divide-borderline text-sm">
                        <thead class="bg-background">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                <th scope="col" class="px-3 py-2">{{ t('outgoing.slipNo') }}</th>
                                <th scope="col" class="px-3 py-2">{{ t('outgoing.materialPartNo') }}</th>
                                <th scope="col" class="px-3 py-2">{{ t('outgoing.invoiceSupplier') }}</th>
                                <th scope="col" class="px-3 py-2">{{ t('outgoing.tagNo') }}</th>
                                <th scope="col" class="px-3 py-2 text-right">{{ t('outgoing.qty') }}</th>
                                <th scope="col" class="px-3 py-2">{{ t('outgoing.uom') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-borderline">
                            <tr v-for="(line, idx) in detailLines" :key="`${line.slip_no}-${line.tag}-${idx}`">
                                <td class="px-3 py-2">
                                    <div class="font-medium text-ink-primary">{{ line.slip_no }}</div>
                                    <div class="text-xs text-ink-secondary">{{ line.issue_date ?? '' }}</div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="text-ink-primary">{{ line.part_number ?? '—' }}</div>
                                    <div class="text-xs text-ink-secondary">{{ line.part_name ?? '' }}</div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="text-ink-primary">{{ line.invoice ?? '—' }}</div>
                                    <div class="text-xs text-ink-secondary">{{ line.supplier ?? '—' }}</div>
                                </td>
                                <td class="px-3 py-2 font-mono text-ink-primary">{{ line.tag ?? '—' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-ink-primary">{{ fmt(line.qty) }}</td>
                                <td class="px-3 py-2 text-ink-secondary">{{ line.uom ?? '—' }}</td>
                            </tr>
                            <tr v-if="detailLines.length === 0">
                                <td colspan="6" class="px-3 py-8 text-center text-sm text-ink-secondary">{{ t('outgoing.empty') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
