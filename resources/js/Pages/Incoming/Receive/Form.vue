<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, reactive, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import type { IncomingArrivalItem } from '@/types';

const { t } = useI18n();

interface TagRow {
    tag: string;
    qty: number | '';
    net_weight: number | '';
    gross_weight: number | '';
}

const props = defineProps<{
    arrivalItem: IncomingArrivalItem;
    remainingQty: number;
    maxReceivable: number;
    poLimit: { po_qty: number; remaining: number } | null;
    totalPlanned: number;
    totalReceived: number;
    weightBasis: boolean;
    isLocal: boolean;
    uomCodes: string[];
    packingUnits: string[];
    packingTypes: string[];
    weightUnit: string;
    locations: Array<{ code: string; name: string }>;
}>();

const backHref = () => route('receive.show', props.arrivalItem.arrival_id);

const unitLabel = () => (props.weightBasis ? props.weightUnit : (props.arrivalItem.unit_goods ?? '').toUpperCase());
const unitLabelValue = unitLabel();

const packingTypeOptions = computed(() =>
    props.packingTypes?.length ? props.packingTypes : props.packingUnits,
);

const bundleUnitOptions = computed(() =>
    props.packingUnits?.length ? props.packingUnits : packingTypeOptions.value,
);

const defaultPackingType = () =>
    packingTypeOptions.value.includes('BOX') ? 'BOX' : (packingTypeOptions.value[0] ?? 'BOX');

const initialBundleUnit = () => {
    const fromArrival = (props.arrivalItem.unit_bundle ?? '').toUpperCase();
    const list = bundleUnitOptions.value;

    return list.includes(fromArrival) ? fromArrival : (list[0] ?? 'PALLET');
};

const initialBundleCount = () => {
    const count = Math.floor(Number(props.arrivalItem.qty_bundle) || 0);

    return count > 0 ? Math.min(count, 100) : 1;
};

const round4 = (value: number) => Math.round(value * 10000) / 10000;

const plan = reactive({
    bundle_count: initialBundleCount() as number | '',
    bundle_unit: initialBundleUnit(),
    packing_count: 1 as number | '',
    packing_type: defaultPackingType(),
    packing_qty: '' as number | '',
    net_total: (props.weightBasis ? props.maxReceivable : '') as number | '',
    gross_total: '' as number | '',
});

/** Prefiks kode tag: nomor faktur, atau nomor part bila faktur kosong. */
const tagPrefix = computed(() => {
    const raw = props.arrivalItem.arrival?.invoice_no || props.arrivalItem.part?.part_number || 'TAG';
    const cleaned = String(raw).toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-+|-+$/g, '');

    return cleaned.slice(0, 24) || 'TAG';
});

const bundleTarget = computed(() => {
    const count = Math.floor(Number(plan.bundle_count) || 0);

    return Math.min(Math.max(count, 0), 100);
});

const qtyPerTag = computed(() =>
    round4((Number(plan.packing_count) || 0) * (Number(plan.packing_qty) || 0)),
);

const planTotalQty = computed(() =>
    round4((Number(plan.bundle_count) || 0) * qtyPerTag.value),
);

const suggestedTag = (index: number) => `${tagPrefix.value}-B${String(index + 1).padStart(2, '0')}`;

/** Bagi rata total ke sejumlah baris; sisa pembulatan masuk ke baris terakhir. */
function distribute(total: number, count: number): number[] {
    if (count <= 0 || total <= 0) {
        return Array.from({ length: Math.max(count, 0) }, () => 0);
    }

    const each = round4(Math.floor((total * 10000) / count) / 10000);
    const values = Array.from({ length: count }, () => each);
    values[count - 1] = round4(values[count - 1] + (total - each * count));

    return values;
}

const rows = ref<TagRow[]>([]);

function makeRow(index: number): TagRow {
    return {
        tag: suggestedTag(index),
        qty: qtyPerTag.value || '',
        net_weight: (props.weightBasis ? 0 : '') as number | '',
        gross_weight: '' as number | '',
    };
}

/** Sesuaikan qty & berat tiap tag dengan rencana kemasan. */
function syncAmounts() {
    const count = rows.value.length;
    if (count === 0) {
        return;
    }

    const netShares = distribute(Number(plan.net_total) || 0, count);
    const grossShares = distribute(Number(plan.gross_total) || 0, count);

    rows.value.forEach((row, index) => {
        row.qty = qtyPerTag.value;
        if (props.weightBasis || plan.net_total !== '') {
            row.net_weight = round4(netShares[index]);
        }
        if (plan.gross_total !== '') {
            row.gross_weight = round4(grossShares[index]);
        }
    });
}

/** Satu tag = satu bundle: jumlah baris tag mengikuti jumlah bundle. */
function rebuildTags() {
    const target = bundleTarget.value;
    const current = rows.value;

    if (current.length > target) {
        rows.value = current.slice(0, target);
    } else if (current.length < target) {
        const additions = Array.from({ length: target - current.length }, (_, offset) =>
            makeRow(current.length + offset),
        );
        rows.value = [...current, ...additions];
    }

    syncAmounts();
}

watch(() => plan.bundle_count, rebuildTags);
watch(() => [plan.packing_count, plan.packing_qty], syncAmounts);
watch(() => [plan.net_total, plan.gross_total], syncAmounts);

rebuildTags();

const tagQtyTotal = computed(() =>
    round4(rows.value.reduce((sum, row) => sum + (Number(row.qty) || 0), 0)),
);

const tagNetTotal = computed(() =>
    round4(rows.value.reduce((sum, row) => sum + (Number(row.net_weight) || 0), 0)),
);

const qtyMismatch = computed(() => Math.abs(tagQtyTotal.value - planTotalQty.value) > 0.00005);

const receivedBasis = computed(() =>
    props.weightBasis ? tagNetTotal.value : tagQtyTotal.value,
);

const exceedsRemaining = computed(() => receivedBasis.value > props.maxReceivable + 1e-9);

const form = useForm({
    receive_date: new Date().toISOString().slice(0, 10),
    truck_no: '',
    location_code: '',
    pen_no: props.arrivalItem.arrival?.pen_no ?? '',
    aju_no: props.arrivalItem.arrival?.aju_no ?? '',
    tags: [] as any[],
});

const clientError = ref('');

function submit() {
    clientError.value = '';

    if (rows.value.length === 0) {
        clientError.value = t('incoming.noTagsYet');
        return;
    }

    if (tagQtyTotal.value <= 0) {
        clientError.value = t('incoming.tagQtyRequired');
        return;
    }

    if (props.weightBasis && tagNetTotal.value <= 0) {
        clientError.value = t('incoming.netWeightRequired');
        return;
    }

    form.tags = rows.value.map((row) => ({
        tag: row.tag.trim(),
        qty: row.qty === '' ? 0 : Number(row.qty),
        bundle_count: 1,
        bundle_qty: 1,
        bundle_unit: plan.bundle_unit,
        packing_count: Number(plan.packing_count) || 1,
        packing_type: plan.packing_type,
        packing_qty: plan.packing_qty === '' ? null : Number(plan.packing_qty),
        net_weight: props.weightBasis
            ? (row.net_weight === '' ? 0 : Number(row.net_weight))
            : (row.net_weight === '' ? null : Number(row.net_weight)),
        gross_weight: row.gross_weight === '' ? null : Number(row.gross_weight),
        qty_unit: unitLabelValue,
    })) as any;

    form.post(route('receive.store', props.arrivalItem.id));
}
</script>

<template>
    <Head :title="t('incoming.receiveGoods')" />
    <AppLayout>
        <BackButton :href="backHref()" class="mb-4" />

        <h1 class="mb-6 text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.receiveGoods') }}</h1>

        <!-- Info Material & Rencana -->
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.part') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ arrivalItem.part?.part_number ?? '—' }}</div>
                <div class="text-xs text-ink-secondary">{{ arrivalItem.material_group ?? '' }} · {{ arrivalItem.size ?? '' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.plannedUnit', { unit: unitLabel() }) }}</div>
                <div class="mt-1 font-semibold tabular-nums text-ink-primary">{{ totalPlanned }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.remainingUnit', { unit: unitLabel() }) }}</div>
                <div class="mt-1 text-lg font-bold tabular-nums" :class="remainingQty > 0 ? 'text-warning' : 'text-success'">{{ remainingQty.toFixed(2) }}</div>
            </div>
            <div v-if="poLimit" class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.poQty') }}</div>
                <div class="mt-1 font-semibold text-ink-primary tabular-nums">{{ poLimit.po_qty }}</div>
                <div class="text-xs text-ink-secondary">{{ t('incoming.maxReceivable', { unit: unitLabel() }) }}: <span class="font-semibold tabular-nums text-ink-primary">{{ maxReceivable.toFixed(2) }}</span></div>
            </div>
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <!-- Header Dokumen Penerimaan -->
            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.receiveDate') }}</label>
                        <input :aria-label="t('incoming.receiveDate')" v-model="form.receive_date" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.receive_date" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.truckNo') }}</label>
                        <input :aria-label="t('incoming.truckNo')" v-model="form.truck_no" type="text" placeholder="B 9123 GCI" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.location') }}</label>
                        <div class="mt-1">
                            <SearchableSelect
                                v-model="form.location_code"
                                :options="locations"
                                value-key="code"
                                label-key="code"
                                sublabel-key="name"
                                :placeholder="t('incoming.locationNone')"
                                :aria-label="t('incoming.location')"
                                :error="form.errors.location_code"
                            />
                        </div>
                        <InputError :message="form.errors.location_code" class="mt-1" />
                    </div>
                </div>

                <!-- Dokumen kepabeanan diisi saat penerimaan -->
                <div class="mt-4 grid gap-4 border-t border-borderline pt-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.penNo') }}</label>
                        <input
                            :aria-label="t('incoming.penNo')"
                            v-model="form.pen_no"
                            type="text"
                            placeholder="No. PEN Bea Cukai"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="(form.errors as any).pen_no" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.ajuNo') }}</label>
                        <input
                            :aria-label="t('incoming.ajuNo')"
                            v-model="form.aju_no"
                            type="text"
                            placeholder="No. AJU Bea Cukai"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="(form.errors as any).aju_no" class="mt-1" />
                    </div>
                </div>
            </div>

            <!-- Tahap 1: Rencana Kemasan -->
            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-4">
                    <h2 class="text-base font-bold text-ink-primary">{{ t('incoming.packagingPlan') }}</h2>
                    <p class="text-xs text-ink-secondary">{{ t('incoming.packagingPlanHint') }}</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.bundleQty') }}</label>
                        <input
                            v-model="plan.bundle_count"
                            type="number"
                            step="1"
                            min="1"
                            max="100"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-semibold text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <p class="mt-1 text-[11px] text-ink-secondary">{{ t('incoming.bundleCountHint') }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.bundleUnit') }}</label>
                        <select
                            v-model="plan.bundle_unit"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        >
                            <option v-for="unit in bundleUnitOptions" :key="unit" :value="unit">{{ unit }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.packingPerBundle') }}</label>
                        <input
                            v-model="plan.packing_count"
                            type="number"
                            step="1"
                            min="1"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-semibold text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <p class="mt-1 text-[11px] text-ink-secondary">{{ t('incoming.packingPerBundleHint') }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.packingType') }}</label>
                        <select
                            v-model="plan.packing_type"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        >
                            <option v-for="type in packingTypeOptions" :key="type" :value="type">{{ type }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.qtyPerPacking') }} ({{ unitLabelValue || '—' }})</label>
                        <input
                            v-model="plan.packing_qty"
                            type="number"
                            step="any"
                            min="0"
                            placeholder="2000"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-semibold text-ink-primary focus:border-primary focus:ring-primary"
                        />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.packagingTotal') }}</label>
                        <div class="mt-1 flex min-h-10 items-center rounded-lg border border-primary/40 bg-primary-light/40 px-3 py-2 text-sm font-bold tabular-nums text-primary">
                            {{ planTotalQty.toLocaleString() }} {{ unitLabelValue }}
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.netWeightTotal') }}</label>
                        <input
                            v-model="plan.net_total"
                            type="number"
                            step="any"
                            min="0"
                            placeholder="KGM"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.grossWeightTotal') }}</label>
                        <input
                            v-model="plan.gross_total"
                            type="number"
                            step="any"
                            min="0"
                            placeholder="KGM"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                    </div>
                </div>
            </div>

            <!-- Tahap 2: Identifikasi per Tag -->
            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold text-ink-primary">{{ t('incoming.tagIdentification') }}</h2>
                        <p class="text-xs text-ink-secondary">{{ t('incoming.autoTagHint') }}</p>
                    </div>
                    <div class="flex items-center gap-3 text-sm tabular-nums">
                        <span class="rounded-full bg-background px-3 py-1 text-xs font-semibold text-ink-secondary">
                            {{ t('incoming.tagCount') }}: <span class="text-ink-primary">{{ rows.length }}</span>
                        </span>
                        <span class="text-ink-secondary">
                            {{ t('incoming.tagTotal') }}:
                            <span class="font-bold" :class="qtyMismatch ? 'text-warning' : 'text-success'">{{ tagQtyTotal.toLocaleString() }} {{ unitLabelValue }}</span>
                        </span>
                    </div>
                </div>

                <p v-if="rows.length" class="mb-3 rounded-lg border border-borderline bg-background/60 px-3 py-2 text-xs text-ink-secondary">
                    {{ t('incoming.tagPackSummary', {
                        tags: rows.length,
                        bundleUnit: plan.bundle_unit,
                        packingCount: Number(plan.packing_count) || 0,
                        packingType: plan.packing_type,
                        packingQty: Number(plan.packing_qty) || 0,
                        perTag: qtyPerTag,
                        unit: unitLabelValue,
                    }) }}
                </p>

                <InputError :message="form.errors.tags" class="mb-3" />
                <p v-if="clientError" class="mb-3 rounded-lg bg-danger/10 px-3 py-2 text-xs font-medium text-danger">{{ clientError }}</p>

                <div v-if="qtyMismatch" class="mb-3 rounded-lg bg-warning/10 px-3 py-2 text-xs font-medium text-warning">
                    {{ t('incoming.tagMismatch', { total: tagQtyTotal, expected: planTotalQty }) }}
                </div>
                <div v-if="exceedsRemaining" class="mb-3 rounded-lg bg-danger/10 px-3 py-2 text-xs font-medium text-danger">
                    {{ t('incoming.exceedsRemaining', { remaining: maxReceivable, unit: unitLabel() }) }}
                </div>

                <div v-if="!rows.length" class="rounded-lg border border-dashed border-borderline bg-background/60 px-4 py-10 text-center text-sm text-ink-secondary">
                    {{ t('incoming.noTagsYet') }}
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                <th scope="col" class="px-2 py-2">#</th>
                                <th scope="col" class="px-2 py-2">{{ t('incoming.tag') }}</th>
                                <th scope="col" class="px-2 py-2 text-right">{{ t('incoming.tagQty') }} ({{ unitLabelValue }})</th>
                                <th scope="col" class="px-2 py-2 text-right">{{ t('incoming.netWeight') }} ({{ weightUnit }})</th>
                                <th scope="col" class="px-2 py-2 text-right">{{ t('incoming.grossWeight') }} ({{ weightUnit }})</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-borderline">
                            <tr v-for="(row, index) in rows" :key="index" class="hover:bg-primary-light/30">
                                <td class="px-2 py-2">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{{ index + 1 }}</span>
                                </td>
                                <td class="px-2 py-2">
                                    <input
                                        v-model="row.tag"
                                        type="text"
                                        :aria-label="t('incoming.tag')"
                                        class="w-full rounded-lg border-borderline bg-surface px-2.5 py-1.5 font-mono text-sm text-ink-primary focus:border-primary focus:ring-primary"
                                    />
                                </td>
                                <td class="px-2 py-2">
                                    <input
                                        v-model="row.qty"
                                        type="number"
                                        step="any"
                                        min="0"
                                        :aria-label="t('incoming.tagQty')"
                                        class="w-28 rounded-lg border-borderline bg-surface px-2.5 py-1.5 text-right text-sm font-semibold text-ink-primary focus:border-primary focus:ring-primary"
                                    />
                                </td>
                                <td class="px-2 py-2">
                                    <input
                                        v-model="row.net_weight"
                                        type="number"
                                        step="any"
                                        min="0"
                                        :aria-label="t('incoming.netWeight')"
                                        class="w-28 rounded-lg border-borderline bg-surface px-2.5 py-1.5 text-right text-sm text-ink-primary focus:border-primary focus:ring-primary"
                                    />
                                </td>
                                <td class="px-2 py-2">
                                    <input
                                        v-model="row.gross_weight"
                                        type="number"
                                        step="any"
                                        min="0"
                                        :aria-label="t('incoming.grossWeight')"
                                        class="w-28 rounded-lg border-borderline bg-surface px-2.5 py-1.5 text-right text-sm text-ink-primary focus:border-primary focus:ring-primary"
                                    />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-borderline text-xs font-semibold text-ink-secondary">
                                <td colspan="2" class="px-2 py-2 text-right">{{ t('incoming.tagTotal') }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-primary">{{ tagQtyTotal.toLocaleString() }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-ink-primary">{{ tagNetTotal.toLocaleString() }}</td>
                                <td class="px-2 py-2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Tombol Aksi -->
            <div class="flex items-center justify-end gap-3">
                <BackButton :href="backHref()">{{ t('incoming.cancel') }}</BackButton>
                <button type="submit" :disabled="form.processing || !rows.length" class="rounded-md bg-primary px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                    {{ form.processing ? t('incoming.saving') : t('incoming.saveReceive') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>