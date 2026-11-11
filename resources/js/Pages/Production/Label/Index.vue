<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';
import { formatPeriod } from '@/utils/date';

const { t, locale } = useI18n();

interface LabelRow {
    id: number;
    work_order_id: number;
    wo_no: string | null;
    part_id: number;
    part_number: string | null;
    part_name: string | null;
    is_fg: boolean;
    machine_name: string | null;
    shift: string | null;
    period_label: string;
    is_backfilled: boolean;
    qty_good: number;
    qty_reject: number;
    label_carried_over_qty?: number;
    carry_over_in?: number;
    uom: string | null;
    label_status: string;
    reporter_id?: number | null;
    reporter_name?: string | null;
}

const props = defineProps<{
    rows: LabelRow[];
    filters: { date?: string; status?: string };
    statuses: string[];
    operators?: Array<{ id: number; name: string }>;
    timezone?: string;
}>();

const date = ref(props.filters.date ?? '');
const status = ref(props.filters.status ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;
watch([date, status], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('production-labels.index'), {
            date: date.value || undefined,
            status: status.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
});

const fmt = (n: number | null | undefined) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 4 });

// Modal Packaging
const showPackagingModal = ref(false);
const selectedRow = ref<LabelRow | null>(null);
const packingType = ref<'BOX' | 'TROLI' | 'PALLET'>('BOX');
const packingQty = ref<number | ''>('');
const includeRemainder = ref(true);

const selectedOperatorName = ref('');
const operatorSearch = ref('');
const showOperatorList = ref(false);

const filteredOperators = computed(() => {
    const q = operatorSearch.value.trim().toLowerCase();
    const list = props.operators ?? [];
    if (!q) return list;
    return list.filter((op) => op.name.toLowerCase().includes(q));
});

function selectOperator(name: string) {
    selectedOperatorName.value = name;
    operatorSearch.value = '';
    showOperatorList.value = false;
}

function openPackagingModal(row: LabelRow) {
    selectedRow.value = row;
    packingType.value = 'BOX';
    const total = (row.qty_good ?? 0) + (row.carry_over_in ?? 0);
    packingQty.value = total >= 100 ? 100 : Math.max(1, Math.round(total));
    includeRemainder.value = true;
    selectedOperatorName.value = row.reporter_name ?? (props.operators?.[0]?.name ?? '');
    operatorSearch.value = '';
    showOperatorList.value = false;
    showPackagingModal.value = true;
}

const totalQtyToPack = computed(() => {
    if (!selectedRow.value) return 0;
    return (selectedRow.value.qty_good ?? 0) + (selectedRow.value.carry_over_in ?? 0);
});

const packCalculations = computed(() => {
    const total = totalQtyToPack.value;
    const capacity = Number(packingQty.value) > 0 ? Number(packingQty.value) : 1;
    const fullPacks = Math.floor(total / capacity);
    const remainder = Math.round((total % capacity) * 10000) / 10000;
    const totalLabels = fullPacks + (remainder > 0 && includeRemainder.value ? 1 : 0);

    return {
        fullPacks,
        capacity,
        remainder,
        totalLabels: Math.max(1, totalLabels),
    };
});

function confirmPrintGood() {
    if (!selectedRow.value) return;
    const row = selectedRow.value;
    const capacity = Number(packingQty.value) > 0 ? Number(packingQty.value) : totalQtyToPack.value;
    const defer = !includeRemainder.value && packCalculations.value.remainder > 0;
    const remainder = packCalculations.value.remainder;

    const url = route('production-labels.print', {
        result: row.id,
        kind: 'good',
        packing_type: packingType.value,
        packing_qty: capacity,
        defer_remainder: defer,
        remainder_qty: remainder,
        operator_name: selectedOperatorName.value || undefined,
    });

    window.open(url, '_blank', 'noopener');
    showPackagingModal.value = false;
    setTimeout(() => router.reload({ only: ['rows'] }), 800);
}

function printNgDirect(row: LabelRow) {
    window.open(route('production-labels.print', [row.id, 'ng']), '_blank', 'noopener');
    setTimeout(() => router.reload({ only: ['rows'] }), 800);
}

const hasNgRows = computed(() => props.rows.some((r) => r.qty_reject > 0));

function printAccumulatedNg() {
    const url = route('production-labels.print-accumulated-ng', {
        date: date.value || undefined,
    });
    window.open(url, '_blank', 'noopener');
}

function deliver(row: LabelRow) {
    router.post(route('production-labels.deliver', row.id), {}, { preserveScroll: true });
}

const canPrint = (row: LabelRow) => row.label_status === 'pending' || row.label_status === 'printed';
const canDeliver = (row: LabelRow) => row.label_status === 'printed';
</script>

<template>
    <Head :title="t('production.labelQueue')" />
    <AppLayout>
        <BackButton :href="route('production-data')" class="mb-4" />

        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.labelQueue') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('production.labelQueueDescription') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    v-if="hasNgRows"
                    type="button"
                    class="rounded-md border border-danger px-3 py-1.5 text-xs font-semibold text-danger transition hover:bg-danger/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger"
                    @click="printAccumulatedNg"
                >
                    {{ t('production.printAccumulatedNg') }}
                </button>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-borderline bg-surface p-3">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.receiptDate') }}</label>
                <input v-model="date" type="date" :aria-label="t('production.receiptDate')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.labelStatus') }}</label>
                <select v-model="status" :aria-label="t('production.labelStatus')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-48">
                    <option value="">{{ t('production.allStatuses') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ t(`production.label_${s}`) }}</option>
                </select>
            </div>
            <span class="ml-auto text-sm text-ink-secondary">
                <span class="font-semibold text-ink-primary">{{ rows.length }}</span> {{ t('production.wipRows') }}
            </span>
        </div>

        <div class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('production.shift') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.period') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.machine') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.woNo') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.part') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.good') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.reject') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.labelStatus') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="row in rows" :key="row.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 text-ink-primary">{{ row.shift ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="tabular-nums font-medium text-ink-primary">{{ formatPeriod(row.period_label) }}</div>
                            <span v-if="row.is_backfilled" class="mt-1 inline-flex rounded bg-warning/15 px-1.5 py-0.5 text-[11px] font-semibold text-warning">
                                {{ t('production.backfilled') }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-ink-secondary">{{ row.machine_name ?? '—' }}</td>
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ row.wo_no ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1.5 text-ink-primary">
                                <span>{{ row.part_number ?? '—' }}</span>
                                <span
                                    class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase"
                                    :class="row.is_fg ? 'bg-success/15 text-success' : 'bg-primary/10 text-primary'"
                                >
                                    {{ row.is_fg ? t('production.finishedGood') : t('production.wipPart') }}
                                </span>
                            </div>
                            <div class="text-xs text-ink-secondary">{{ row.part_name ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-success">
                            {{ fmt(row.qty_good) }}
                            <div v-if="(row.carry_over_in ?? 0) > 0" class="text-[11px] font-normal text-ink-secondary">
                                +{{ fmt(row.carry_over_in) }} carry-over
                            </div>
                            <div v-if="(row.label_carried_over_qty ?? 0) > 0" class="text-[11px] font-normal text-warning">
                                ({{ fmt(row.label_carried_over_qty) }} ditunda)
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-danger">{{ fmt(row.qty_reject) }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge :status="row.label_status">{{ t(`production.label_${row.label_status}`) }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap justify-end gap-1.5">
                                <button
                                    type="button"
                                    :disabled="!canPrint(row) || row.qty_good <= 0"
                                    class="rounded-md border border-primary px-2.5 py-1 text-xs font-semibold text-primary transition hover:bg-primary-light disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    @click="openPackagingModal(row)"
                                >
                                    {{ t('production.printGood') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="!canPrint(row) || row.qty_reject <= 0"
                                    class="rounded-md border border-danger px-2.5 py-1 text-xs font-semibold text-danger transition hover:bg-danger/10 disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger"
                                    @click="printNgDirect(row)"
                                >
                                    {{ t('production.printNg') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="!canDeliver(row)"
                                    class="rounded-md border border-borderline px-2.5 py-1 text-xs font-semibold text-ink-secondary transition hover:bg-background disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    @click="deliver(row)"
                                >
                                    {{ t('production.markDelivered') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td colspan="9" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('production.labelQueueEmpty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Modal Pengaturan Kemasan & Cetak Label Multi-Container -->
        <Modal :show="showPackagingModal" @close="showPackagingModal = false" max-width="md">
            <div class="p-5" v-if="selectedRow">
                <h2 class="text-base font-bold text-ink-primary">{{ t('production.packagingModalTitle') }}</h2>
                <p class="mt-1 text-xs text-ink-secondary">
                    {{ selectedRow.wo_no }} · {{ selectedRow.part_number }}
                    <span v-if="(selectedRow.carry_over_in ?? 0) > 0"> (termasuk carry-over {{ fmt(selectedRow.carry_over_in) }})</span>
                </p>

                <div class="mt-4 space-y-4">
                    <!-- Pilihan Operator (Searchable dari daftar User) -->
                    <div>
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                {{ t('production.operator') }}
                            </label>
                            <span v-if="selectedOperatorName" class="text-xs font-bold text-primary">
                                {{ selectedOperatorName }}
                            </span>
                        </div>
                        <div class="relative mt-1">
                            <input
                                v-model="operatorSearch"
                                type="text"
                                :placeholder="selectedOperatorName || t('production.searchOperator')"
                                @focus="showOperatorList = true"
                                class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                            />
                            <div
                                v-if="showOperatorList"
                                class="absolute left-0 right-0 z-20 mt-1 max-h-48 overflow-y-auto rounded-lg border border-borderline bg-surface shadow-lg"
                            >
                                <button
                                    v-for="op in filteredOperators"
                                    :key="op.id"
                                    type="button"
                                    class="w-full px-3 py-2 text-left text-xs font-medium text-ink-primary transition hover:bg-primary-light flex items-center justify-between"
                                    @click="selectOperator(op.name)"
                                >
                                    <span>{{ op.name }}</span>
                                    <span v-if="selectedOperatorName === op.name" class="text-primary font-bold">✓</span>
                                </button>
                                <div v-if="filteredOperators.length === 0" class="px-3 py-3 text-center text-xs text-ink-secondary">
                                    Tidak ditemukan
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dropdown Tipe Kemasan -->
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            {{ t('production.packingType') }}
                        </label>
                        <select
                            v-model="packingType"
                            class="mt-1 w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        >
                            <option value="BOX">{{ t('production.box') }}</option>
                            <option value="TROLI">{{ t('production.troli') }}</option>
                            <option value="PALLET">{{ t('production.pallet') }}</option>
                        </select>
                    </div>

                    <!-- Input Kapasitas Kemasan -->
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            {{ t('production.packingQty') }}
                        </label>
                        <input
                            v-model.number="packingQty"
                            type="number"
                            min="1"
                            class="mt-1 w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                            placeholder="100"
                        />
                    </div>

                    <!-- Ringkasan Kalkulasi -->
                    <div class="rounded-lg border border-borderline bg-background p-3 text-xs space-y-1.5">
                        <div class="flex justify-between font-semibold text-ink-primary">
                            <span>Total Qty:</span>
                            <span>{{ fmt(totalQtyToPack) }} {{ selectedRow.uom ?? 'EA' }}</span>
                        </div>
                        <div class="flex justify-between text-ink-secondary">
                            <span>Kemasan Penuh:</span>
                            <span>{{ packCalculations.fullPacks }} {{ packingType.toLowerCase() }} (@ {{ packCalculations.capacity }} EA)</span>
                        </div>
                        <div v-if="packCalculations.remainder > 0" class="flex justify-between text-warning font-medium">
                            <span>Sisa Ganjil:</span>
                            <span>{{ fmt(packCalculations.remainder) }} EA</span>
                        </div>
                    </div>

                    <!-- Checkbox Sisa Ganjil -->
                    <div v-if="packCalculations.remainder > 0" class="rounded-lg border border-warning/30 bg-warning/5 p-3">
                        <label class="flex items-start gap-2 text-xs text-ink-primary cursor-pointer">
                            <input
                                type="checkbox"
                                v-model="includeRemainder"
                                class="mt-0.5 rounded border-borderline text-primary focus:ring-primary"
                            />
                            <span>
                                <span class="font-semibold" v-if="includeRemainder">
                                    {{ t('production.printRemainderNow') }}
                                </span>
                                <span class="font-semibold text-warning" v-else>
                                    {{ t('production.deferRemainder') }}
                                </span>
                            </span>
                        </label>
                    </div>

                    <!-- Notifikasi Jumlah Label -->
                    <div class="rounded-lg bg-primary-light px-3 py-2 text-xs font-medium text-primary text-center">
                        {{ t('production.printSummaryNotice', { count: packCalculations.totalLabels }) }}
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-borderline px-4 py-2 text-xs font-medium text-ink-secondary transition hover:bg-background"
                        @click="showPackagingModal = false"
                    >
                        {{ t('common.back') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-primary px-4 py-2 text-xs font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        @click="confirmPrintGood"
                    >
                        {{ t('production.printLabelsButton', { count: packCalculations.totalLabels }) }}
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
