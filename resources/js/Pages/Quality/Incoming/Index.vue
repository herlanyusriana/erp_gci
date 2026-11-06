<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { Paginated } from '@/types';

const { t } = useI18n();

interface PartItem {
    id: number;
    part_id: number | null;
    qty_goods: number;
    unit_goods: string | null;
    part?: { id: number; part_number: string; part_name: string };
}

interface ArrivalRow {
    id: number;
    arrival_no: string;
    po_no: string | null;
    invoice_no: string | null;
    delivery_note_no: string | null;
    invoice_date: string | null;
    created_at: string;
    is_local: boolean;
    iqc_status: string;
    iqc_passed_at: string | null;
    supplier?: { id: number; supplier_code: string; supplier_name: string };
    purchase_order?: { id: number; po_no: string };
    items?: PartItem[];
    iqc_inspector?: { id: number; name: string };
}

const props = defineProps<{
    arrivals: Paginated<ArrivalRow>;
    filters: { status: string; search: string };
    timezone: string;
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'pending');

let timer: ReturnType<typeof setTimeout> | undefined;
function doFilter() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('incoming-quality.index'), {
            status: status.value,
            search: search.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
}

function pass(row: ArrivalRow) {
    router.post(route('incoming-quality.pass', row.id), {}, { preserveScroll: true });
}

function revert(row: ArrivalRow) {
    router.post(route('incoming-quality.revert', row.id), {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('quality.incomingQuality')" />
        <BackButton :href="route('launcher')" class="mb-4" />

        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('quality.incomingQuality') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('quality.incomingQualityDesc') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded-xl border border-borderline bg-surface px-4 py-2 text-sm text-ink-secondary">
                    Total: <span class="font-bold text-ink-primary">{{ arrivals.total }}</span> kedatangan
                </span>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-borderline bg-surface p-3">
            <input
                v-model="search"
                type="search"
                :placeholder="t('quality.searchArrival')"
                @input="doFilter"
                class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-72"
            />
            <div class="inline-flex rounded-lg border border-borderline bg-background p-1 text-xs font-semibold">
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 transition"
                    :class="status === 'pending' ? 'bg-primary text-white shadow-sm' : 'text-ink-secondary hover:text-ink-primary'"
                    @click="status = 'pending'; doFilter()"
                >
                    {{ t('quality.pendingIqc') }}
                </button>
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 transition"
                    :class="status === 'passed' ? 'bg-primary text-white shadow-sm' : 'text-ink-secondary hover:text-ink-primary'"
                    @click="status = 'passed'; doFilter()"
                >
                    {{ t('quality.passedIqc') }}
                </button>
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 transition"
                    :class="status === 'all' ? 'bg-primary text-white shadow-sm' : 'text-ink-secondary hover:text-ink-primary'"
                    @click="status = 'all'; doFilter()"
                >
                    {{ t('quality.allStatuses') }}
                </button>
            </div>
        </div>

        <!-- Tabel ringkas: Tanggal Arrival | Supp Name | PO No | Item List | Ceklis IQC Pass -->
        <div class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('quality.arrivalDate') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('quality.supplierName') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('quality.poNo') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('quality.itemList') }}</th>
                        <th scope="col" class="px-4 py-3 text-center">{{ t('quality.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('quality.action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="a in arrivals.data" :key="a.id" class="hover:bg-primary-light/40">
                        <!-- Tanggal Arrival -->
                        <td class="px-4 py-3 align-top whitespace-nowrap">
                            <div class="font-medium text-ink-primary">{{ a.invoice_date ?? a.created_at.slice(0, 10) }}</div>
                            <div class="text-xs text-ink-secondary">{{ a.arrival_no }}</div>
                        </td>

                        <!-- Supp Name -->
                        <td class="px-4 py-3 align-top">
                            <div class="font-semibold text-ink-primary">{{ a.supplier?.supplier_name ?? '—' }}</div>
                            <div class="text-xs text-ink-secondary">
                                <span v-if="a.delivery_note_no">SJ: {{ a.delivery_note_no }} · </span>
                                <span v-if="a.invoice_no">Inv: {{ a.invoice_no }}</span>
                            </div>
                        </td>

                        <!-- PO No -->
                        <td class="px-4 py-3 align-top whitespace-nowrap">
                            <span class="rounded bg-background px-2 py-1 font-mono text-xs font-semibold text-primary border border-borderline">
                                {{ a.po_no ?? a.purchase_order?.po_no ?? '—' }}
                            </span>
                        </td>

                        <!-- Item List -->
                        <td class="px-4 py-3 align-top">
                            <div class="space-y-1">
                                <div v-for="(it, i) in a.items ?? []" :key="i" class="text-xs leading-tight">
                                    <span class="font-semibold text-ink-primary">{{ it.part?.part_number ?? '—' }}</span>
                                    <span class="text-ink-secondary"> ({{ it.qty_goods }} {{ it.unit_goods ?? '' }})</span>
                                    <span v-if="it.part?.part_name" class="block text-[11px] text-ink-secondary/80">{{ it.part.part_name }}</span>
                                </div>
                                <div v-if="!a.items || a.items.length === 0" class="text-xs text-ink-secondary">
                                    —
                                </div>
                            </div>
                        </td>

                        <!-- Status IQC -->
                        <td class="px-4 py-3 align-top text-center whitespace-nowrap">
                            <span
                                v-if="a.iqc_status === 'passed'"
                                class="inline-flex items-center gap-1 rounded-full bg-success/15 px-2.5 py-0.5 text-xs font-bold text-success"
                            >
                                ✓ IQC PASS
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center gap-1 rounded-full bg-warning/15 px-2.5 py-0.5 text-xs font-semibold text-warning"
                            >
                                ● PENDING
                            </span>
                            <div v-if="a.iqc_inspector" class="mt-1 text-[11px] text-ink-secondary">
                                by {{ a.iqc_inspector.name }}
                            </div>
                        </td>

                        <!-- Aksi / Ceklis IQC Pass -->
                        <td class="px-4 py-3 align-top text-right whitespace-nowrap">
                            <button
                                v-if="a.iqc_status !== 'passed'"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-success px-3.5 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-success/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-success"
                                @click="pass(a)"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                                {{ t('quality.passButton') }}
                            </button>
                            <button
                                v-else
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-borderline px-2.5 py-1 text-xs text-ink-secondary transition hover:bg-background hover:text-danger"
                                :title="t('quality.revertTitle')"
                                @click="revert(a)"
                            >
                                ✕ Batal Pass
                            </button>
                        </td>
                    </tr>
                    <tr v-if="arrivals.data.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">
                            {{ t('quality.noArrivals') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="arrivals.links" class="mt-4" />
    </AppLayout>
</template>
