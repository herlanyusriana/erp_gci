<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import type { IncomingArrivalItem } from '@/types';

const { t } = useI18n();

interface TagRow {
    tag: string;
    qty: number | '';
    bundle_qty: number | '';
    bundle_unit: string;
    net_weight: number | '';
    gross_weight: number | '';
    qty_unit: string;
}

const props = defineProps<{
    arrivalItem: IncomingArrivalItem;
    remainingQty: number;
    totalPlanned: number;
    totalReceived: number;
    weightBasis: boolean;
    isLocal: boolean;
    uomCodes: string[];
    packingUnits: string[];
    weightUnit: string;
}>();

const backHref = () => props.isLocal
    ? route('local-pos.show', props.arrivalItem.arrival_id)
    : route('incoming-arrivals.show', props.arrivalItem.arrival_id);

const unitLabel = () => (props.weightBasis ? props.weightUnit : (props.arrivalItem.unit_goods ?? '').toUpperCase());

const blank = (): TagRow => ({
    tag: '',
    qty: '',
    bundle_qty: '',
    bundle_unit: props.packingUnits[0] ?? 'PALLET',
    net_weight: '',
    gross_weight: '',
    qty_unit: props.arrivalItem.unit_goods?.toUpperCase()
        ?? props.uomCodes.find((c) => c === props.weightUnit)
        ?? props.uomCodes[0]
        ?? '',
});

const rows = ref<TagRow[]>([blank()]);

const form = useForm({
    receive_date: new Date().toISOString().slice(0, 10),
    truck_no: '',
    tags: [] as any[],
});

function addRow() { rows.value.push(blank()); }
function removeRow(i: number) { if (rows.value.length > 1) rows.value.splice(i, 1); }

const totalInput = () => rows.value.reduce((s, r) => s + (r.qty === '' ? 0 : Number(r.qty)), 0);

function submit() {
    form.tags = rows.value.map((r) => ({
        tag: r.tag,
        qty: r.qty === '' ? 0 : Number(r.qty),
        bundle_qty: r.bundle_qty === '' ? null : Number(r.bundle_qty),
        bundle_unit: r.bundle_unit,
        net_weight: props.weightBasis ? (r.net_weight === '' ? 0 : Number(r.net_weight)) : null,
        gross_weight: r.gross_weight === '' ? null : Number(r.gross_weight),
        qty_unit: r.qty_unit,
    })) as any;

    form.post(route('receive.store', props.arrivalItem.id));
}
</script>

<template>
    <Head :title="t('incoming.receiveGoods')" />
    <AppLayout>
        <BackButton :href="backHref()" class="mb-4" />

        <h1 class="mb-6 text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.receiveGoods') }}</h1>

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.part') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrivalItem.part?.part_number ?? '—' }}</div>
                <div class="text-xs text-ink-secondary">{{ arrivalItem.material_group ?? '' }} · {{ arrivalItem.size ?? '' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.plannedUnit', { unit: unitLabel() }) }}</div>
                <div class="mt-1 text-ink-primary tabular-nums">{{ totalPlanned }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.remainingUnit', { unit: unitLabel() }) }}</div>
                <div class="mt-1 text-lg font-semibold tabular-nums" :class="remainingQty > 0 ? 'text-warning' : 'text-success'">{{ remainingQty.toFixed(2) }}</div>
            </div>
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.receiveDate') }}</label>
                        <input :aria-label="t('incoming.receiveDate')" v-model="form.receive_date" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.receive_date" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.truckNo') }}</label>
                        <input :aria-label="t('incoming.truckNo')" v-model="form.truck_no" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-ink-primary">{{ t('incoming.tags') }}</h2>
                    <div class="flex items-center gap-3">
                        <span class="text-sm tabular-nums text-ink-secondary">{{ t('incoming.totalInput') }} <span class="font-semibold text-ink-primary">{{ totalInput() }}</span></span>
                        <button type="button" @click="addRow" class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-sm font-medium text-primary transition hover:bg-primary-light">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            {{ t('incoming.addTag') }}
                        </button>
                    </div>
                </div>
                <InputError :message="form.errors.tags" class="mb-3" />

                <div v-for="(r, i) in rows" :key="i" class="border-t border-borderline py-3">
                    <div class="grid gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.tag') }}</label>
                            <input :aria-label="t('incoming.tag')" v-model="r.tag" type="text" placeholder="TAG-001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.qty') }}</label>
                            <input :aria-label="t('incoming.qty')" v-model="r.qty" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-1">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.unit') }}</label>
                            <select :aria-label="t('incoming.unit')" v-model="r.qty_unit" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                                <option v-for="code in uomCodes" :key="code" :value="code">{{ code }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.bundleQty') }}</label>
                            <input :aria-label="t('incoming.bundleQty')" v-model="r.bundle_qty" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.bundleUnit') }}</label>
                            <select :aria-label="t('incoming.bundleUnit')" v-model="r.bundle_unit" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                                <option v-for="code in packingUnits" :key="code" :value="code">{{ code }}</option>
                            </select>
                        </div>
                        <div class="flex items-end sm:col-span-3">
                            <button type="button" @click="removeRow(i)" :disabled="rows.length <= 1" class="rounded-lg border border-borderline px-3 py-2 text-sm text-danger transition hover:bg-danger/10 disabled:opacity-40" :aria-label="t('incoming.delete')">✕</button>
                        </div>
                    </div>
                    <div v-if="weightBasis" class="mt-3 grid gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.netUnit', { unit: unitLabel() }) }}</label>
                            <input :aria-label="t('incoming.netUnit', { unit: unitLabel() })" v-model="r.net_weight" type="number" step="0.0001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.grossUnit', { unit: unitLabel() }) }}</label>
                            <input :aria-label="t('incoming.grossUnit', { unit: unitLabel() })" v-model="r.gross_weight" type="number" step="0.0001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <BackButton :href="backHref()">{{ t('incoming.cancel') }}</BackButton>
                <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-5 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                    {{ form.processing ? t('incoming.saving') : t('incoming.saveReceive') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>