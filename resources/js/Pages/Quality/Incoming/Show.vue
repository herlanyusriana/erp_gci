<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import { formatDate } from '@/utils/date';

const { t } = useI18n();

interface PartItem {
    id: number;
    arrival_id: number;
    part_id: number | null;
    material_group: string | null;
    size: string | null;
    qty_goods: number;
    unit_goods: string | null;
    weight_nett: number | null;
    iqc_status: string;
    iqc_passed_at: string | null;
    part?: { id: number; part_number: string; part_name: string };
    iqc_inspector?: { id: number; name: string };
}

interface ArrivalDetail {
    id: number;
    arrival_no: string;
    po_no: string | null;
    invoice_no: string | null;
    delivery_note_no: string | null;
    invoice_date: string | null;
    is_local: boolean;
    supplier?: { id: number; supplier_code: string; supplier_name: string };
    purchase_order?: { id: number; po_no: string };
    items?: PartItem[];
}

const props = defineProps<{
    arrival: ArrivalDetail;
}>();

function passItem(item: PartItem) {
    router.post(route('incoming-quality.items.pass', item.id), {}, { preserveScroll: true });
}

function revertItem(item: PartItem) {
    router.post(route('incoming-quality.items.revert', item.id), {}, { preserveScroll: true });
}

function passAll() {
    router.post(route('incoming-quality.pass-all', props.arrival.id), {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('quality.detailTitle', { number: arrival.arrival_no })" />
        <BackButton :href="route('incoming-quality.index')" class="mb-4" />

        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">
                    {{ t('quality.detailTitle', { number: arrival.arrival_no }) }}
                </h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ arrival.supplier?.supplier_name ?? '—' }} · {{ t('quality.materialDetailDesc') }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="rounded-md bg-success px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-success/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-success"
                    @click="passAll"
                >
                    ✓ {{ t('quality.passAllButton') }}
                </button>
            </div>
        </div>

        <!-- Ringkasan Info Kedatangan -->
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('quality.supplierName') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ arrival.supplier?.supplier_name ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('quality.poNo') }}</div>
                <div class="mt-1 font-mono font-semibold text-primary">{{ arrival.po_no ?? arrival.purchase_order?.po_no ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">Dokumen Surat Jalan / Inv</div>
                <div class="mt-1 text-ink-primary">{{ arrival.delivery_note_no ?? arrival.invoice_no ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('quality.arrivalDate') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ formatDate(arrival.invoice_date) }}</div>
            </div>
        </div>

        <!-- Tabel Per Material -->
        <div class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">Part Number</th>
                        <th scope="col" class="px-4 py-3">Nama Material</th>
                        <th scope="col" class="px-4 py-3 text-right">Qty Kirim</th>
                        <th scope="col" class="px-4 py-3 text-right">Berat Bersih</th>
                        <th scope="col" class="px-4 py-3 text-center">Status IQC</th>
                        <th scope="col" class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="it in arrival.items ?? []" :key="it.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3.5 align-top font-bold text-ink-primary">
                            {{ it.part?.part_number ?? it.material_group ?? '—' }}
                        </td>
                        <td class="px-4 py-3.5 align-top text-ink-primary">
                            <div>{{ it.part?.part_name ?? '—' }}</div>
                            <div v-if="it.size" class="text-xs text-ink-secondary font-mono">{{ it.size }}</div>
                        </td>
                        <td class="px-4 py-3.5 align-top text-right tabular-nums font-semibold text-ink-primary">
                            {{ it.qty_goods }} <span class="text-xs font-normal text-ink-secondary">{{ it.unit_goods ?? '' }}</span>
                        </td>
                        <td class="px-4 py-3.5 align-top text-right tabular-nums text-ink-secondary">
                            {{ it.weight_nett ? `${it.weight_nett} KGM` : '—' }}
                        </td>
                        <td class="px-4 py-3.5 align-top text-center whitespace-nowrap">
                            <span
                                v-if="it.iqc_status === 'passed'"
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
                            <div v-if="it.iqc_inspector" class="mt-1 text-[11px] text-ink-secondary">
                                by {{ it.iqc_inspector.name }}
                            </div>
                        </td>
                        <td class="px-4 py-3.5 align-top text-right whitespace-nowrap">
                            <button
                                v-if="it.iqc_status !== 'passed'"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-success px-3 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-success/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-success"
                                @click="passItem(it)"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                                IQC Pass
                            </button>
                            <button
                                v-else
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-borderline px-2.5 py-1 text-xs text-ink-secondary transition hover:bg-background hover:text-danger"
                                title="Batal IQC Pass"
                                @click="revertItem(it)"
                            >
                                ✕ Batal Pass
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!arrival.items || arrival.items.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">
                            Tidak ada material dalam kedatangan ini.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
