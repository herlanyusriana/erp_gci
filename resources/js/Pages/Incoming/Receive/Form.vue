<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import type { IncomingArrivalItem } from '@/types';

const { t } = useI18n();

interface TagRow {
    tag: string;
    bundle_count: number | '';
    bundle_unit: string;
    packing_count: number | '';
    packing_type: string;
    packing_qty: number | '';
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
    weightUnit: string;
    locations: Array<{ code: string; name: string }>;
}>();

const backHref = () => route('receive.show', props.arrivalItem.arrival_id);

const unitLabel = () => (props.weightBasis ? props.weightUnit : (props.arrivalItem.unit_goods ?? '').toUpperCase());
const unitLabelValue = unitLabel();

const subPackingTypes = ['BOX', 'PACK', 'BAG', 'ROLL', 'CAN', 'DRUM', 'PALLET', 'BUNDLE'];

const blank = (): TagRow => ({
    tag: '',
    bundle_count: 1,
    bundle_unit: props.arrivalItem.unit_bundle ?? props.packingUnits[0] ?? 'PALLET',
    packing_count: 1,
    packing_type: 'BOX',
    packing_qty: '',
    qty: '',
    net_weight: props.weightBasis ? props.remainingQty : '',
    gross_weight: '',
});

const initialRow = blank();
initialRow.qty = props.maxReceivable;
if (props.weightBasis) {
    initialRow.net_weight = props.maxReceivable;
}
const rows = ref<TagRow[]>([initialRow]);

const form = useForm({
    receive_date: new Date().toISOString().slice(0, 10),
    truck_no: '',
    location_code: '',
    tags: [] as any[],
});

function calculateTotal(r: TagRow) {
    const bCount = Number(r.bundle_count) || 0;
    const pCount = Number(r.packing_count) || 0;
    const pQty = Number(r.packing_qty) || 0;

    if (bCount > 0 && pCount > 0 && pQty > 0) {
        const total = Math.round(bCount * pCount * pQty * 10000) / 10000;
        r.qty = total;
        if (props.weightBasis && !r.net_weight) {
            r.net_weight = total;
        }
    }
}

function addRow() {
    rows.value.push(blank());
}

function removeRow(i: number) {
    if (rows.value.length > 1) {
        rows.value.splice(i, 1);
    }
}

const totalInput = () => rows.value.reduce((s, r) => s + (r.qty === '' ? 0 : Number(r.qty)), 0);

function submit() {
    form.tags = rows.value.map((r) => ({
        tag: r.tag,
        qty: r.qty === '' ? 0 : Number(r.qty),
        bundle_count: r.bundle_count === '' ? null : Number(r.bundle_count),
        bundle_qty: r.bundle_count === '' ? null : Number(r.bundle_count),
        bundle_unit: r.bundle_unit,
        packing_count: r.packing_count === '' ? 1 : Number(r.packing_count),
        packing_type: r.packing_type,
        packing_qty: r.packing_qty === '' ? null : Number(r.packing_qty),
        net_weight: props.weightBasis ? (r.net_weight === '' ? 0 : Number(r.net_weight)) : (r.net_weight === '' ? null : Number(r.net_weight)),
        gross_weight: r.gross_weight === '' ? null : Number(r.gross_weight),
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
                        <input :aria-label="t('incoming.truckNo')" v-model="form.truck_no" type="text" placeholder="Contoh: B 9123 GCI" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
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
            </div>

            <!-- Bagian Penerimaan Kemasan Bertingkat & Tag -->
            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold text-ink-primary">Kalkulasi Kemasan Bertingkat (Multi-Tier) & Tag</h2>
                        <p class="text-xs text-ink-secondary">Jumlah Bundle × Isi Packing per Bundle × Qty per Packing = Total Qty Fisik.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm tabular-nums text-ink-secondary">
                            {{ t('incoming.totalInput') }} <span class="font-bold text-primary">{{ totalInput() }} {{ unitLabelValue }}</span>
                        </span>
                        <button type="button" @click="addRow" class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-sm font-medium text-primary transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            {{ t('incoming.addTag') }}
                        </button>
                    </div>
                </div>
                <InputError :message="form.errors.tags" class="mb-3" />

                <div v-for="(r, i) in rows" :key="i" class="border-t border-borderline py-4 space-y-3">
                    <!-- Baris 1: Tag & Wadah Besar (Bundle) & Sub-Kemasan (Packing) -->
                    <div class="grid gap-3 sm:grid-cols-12 items-end">
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.tag') }} *</label>
                            <input :aria-label="t('incoming.tag')" v-model="r.tag" type="text" placeholder="Contoh: TAG-001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary font-mono focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">Jml Bundle (Wadah)</label>
                            <input v-model="r.bundle_count" @input="calculateTotal(r)" type="number" step="1" min="0" placeholder="1" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary font-semibold focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">Tipe Bundle</label>
                            <select v-model="r.bundle_unit" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                                <option v-for="code in packingUnits" :key="code" :value="code">{{ code }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">Packing / Bundle</label>
                            <input v-model="r.packing_count" @input="calculateTotal(r)" type="number" step="1" min="0" placeholder="1" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">Tipe Packing</label>
                            <select v-model="r.packing_type" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                                <option v-for="pType in subPackingTypes" :key="pType" :value="pType">{{ pType }}</option>
                            </select>
                        </div>
                        <div class="flex items-center justify-end sm:col-span-1">
                            <button type="button" @click="removeRow(i)" :disabled="rows.length <= 1" class="rounded-lg border border-borderline p-2 text-sm text-danger transition hover:bg-danger/10 disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" :aria-label="t('incoming.delete')">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Baris 2: Isi per Kemasan, Total Kuantitas Fisik, dan Berat KGM -->
                    <div class="grid gap-3 sm:grid-cols-12 bg-background/60 p-3 rounded-lg border border-borderline/60 items-end">
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">Isi Qty per Packing</label>
                            <input v-model="r.packing_qty" @input="calculateTotal(r)" type="number" step="any" min="0" placeholder="Contoh: 2000" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary font-semibold focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">Satuan (UOM)</label>
                            <div class="mt-1 flex min-h-10 items-center rounded-lg border border-borderline bg-surface px-3 py-2 text-sm font-bold text-ink-primary">
                                {{ unitLabelValue || '—' }}
                            </div>
                        </div>
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">Total Qty (Dihitung/Fisik) *</label>
                            <input :aria-label="t('incoming.qty')" v-model="r.qty" type="number" step="any" min="0.0001" class="mt-1 w-full rounded-lg border-primary bg-surface px-3 py-2 text-sm font-bold text-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">Berat Bersih (KGM)</label>
                            <input v-model="r.net_weight" type="number" step="any" min="0" placeholder="KGM" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">Berat Kotor (KGM)</label>
                            <input v-model="r.gross_weight" type="number" step="any" min="0" placeholder="KGM" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tombol Aksi -->
            <div class="flex items-center justify-end gap-3">
                <BackButton :href="backHref()">{{ t('incoming.cancel') }}</BackButton>
                <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                    {{ form.processing ? t('incoming.saving') : t('incoming.saveReceive') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
