<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import type { IncomingArrival } from '@/types';

const { t } = useI18n();

interface PartOpt { id: number; part_number: string; part_name: string; }
interface SupplierOpt { id: number; supplier_code: string | null; supplier_name: string; }
interface ItemRow {
    id: number | null;
    part_id: number | '';
    size: string;
    qty_goods: number | '';
    unit_goods: string;
    qty_bundle: number | '';
    unit_bundle: string;
    weight_nett: number | '';
    weight_gross: number | '';
    price: number | '';
    notes: string;
}

const props = defineProps<{
    arrival?: IncomingArrival | null;
    suppliers: SupplierOpt[];
    parts: PartOpt[];
    uomCodes: string[];
    packingUnits: string[];
    defaultUom?: string;
}>();

const editing = computed(() => !!props.arrival);

const defaultUomCode = computed(() => props.defaultUom || (props.uomCodes[0] ?? ''));

const seedRows = (): ItemRow[] => {
    if (props.arrival?.items?.length) {
        return props.arrival.items.map((it) => ({
            id: it.id,
            part_id: it.part_id ?? '',
            size: it.size ?? '',
            qty_goods: it.qty_goods,
            unit_goods: it.unit_goods ?? defaultUomCode.value,
            qty_bundle: it.qty_bundle ?? '',
            unit_bundle: it.unit_bundle ?? '',
            weight_nett: it.weight_nett ?? '',
            weight_gross: it.weight_gross ?? '',
            price: it.price ?? '',
            notes: it.notes ?? '',
        }));
    }
    return [{ id: null, part_id: '', size: '', qty_goods: '', unit_goods: defaultUomCode.value, qty_bundle: '', unit_bundle: '', weight_nett: '', weight_gross: '', price: '', notes: '' }];
};

const rows = ref<ItemRow[]>(seedRows());

const form = useForm({
    po_no: props.arrival?.po_no ?? '',
    invoice_no: props.arrival?.invoice_no ?? '',
    po_date: props.arrival?.invoice_date?.slice(0, 10) ?? '',
    supplier_id: props.arrival?.supplier_id ?? ('' as number | ''),
    currency: props.arrival?.currency ?? 'IDR',
    notes: props.arrival?.notes ?? '',
    items: [] as any[],
});

function addRow() {
    rows.value.push({ id: null, part_id: '', size: '', qty_goods: '', unit_goods: defaultUomCode.value, qty_bundle: '', unit_bundle: '', weight_nett: '', weight_gross: '', price: '', notes: '' });
}
function removeRow(i: number) {
    if (rows.value.length > 1) rows.value.splice(i, 1);
}
function rowTotal(r: ItemRow) {
    const q = r.qty_goods === '' ? 0 : Number(r.qty_goods);
    const p = r.price === '' ? 0 : Number(r.price);
    return q * p;
}
const grandTotal = () => rows.value.reduce((s, r) => s + rowTotal(r), 0);

function submit() {
    form.items = rows.value
        .filter((r) => r.part_id !== '' && r.qty_goods !== '' && r.weight_nett !== '')
        .map((r) => ({
            id: r.id,
            part_id: Number(r.part_id),
            size: r.size,
            qty_goods: Number(r.qty_goods),
            unit_goods: r.unit_goods,
            qty_bundle: r.qty_bundle === '' ? null : Number(r.qty_bundle),
            unit_bundle: r.unit_bundle === '' ? null : r.unit_bundle,
            weight_nett: r.weight_nett === '' ? null : Number(r.weight_nett),
            weight_gross: r.weight_gross === '' ? null : Number(r.weight_gross),
            price: r.price === '' ? null : Number(r.price),
            notes: r.notes,
        })) as any;

    if (editing.value && props.arrival) {
        form.put(route('local-pos.update', props.arrival.id));
    } else {
        form.post(route('local-pos.store'));
    }
}
</script>

<template>
    <Head :title="editing ? t('incoming.editLocal') : t('incoming.newLocal')" />
    <AppLayout>
        <BackButton :href="route('local-pos.index')" class="mb-4" />

        <h1 class="mb-6 text-2xl font-bold tracking-tight text-ink-primary">{{ editing ? t('incoming.editLocal') : t('incoming.newLocal') }}</h1>

        <form @submit.prevent="submit" class="space-y-6">
            <div class="rounded-xl border border-borderline bg-surface p-5">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ t('incoming.header') }}</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.poNo') }}</label>
                        <input v-model="form.po_no" type="text" placeholder="LPO-001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.po_no" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.invoiceNo') }}</label>
                        <input v-model="form.invoice_no" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.invoice_no" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.poDate') }}</label>
                        <input v-model="form.po_date" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.po_date" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.supplier') }}</label>
                        <select v-model="form.supplier_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('incoming.chooseSupplier') }}</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.supplier_code ? `${s.supplier_code} — ` : '' }}{{ s.supplier_name }}</option>
                        </select>
                        <InputError :message="form.errors.supplier_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.currency') }}</label>
                        <input v-model="form.currency" type="text" placeholder="IDR" maxlength="10" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.notes') }}</label>
                        <input v-model="form.notes" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-ink-primary">{{ t('incoming.items') }}</h2>
                    <div class="flex items-center gap-3">
                        <span class="text-sm tabular-nums text-ink-secondary">{{ t('incoming.grandTotal') }} <span class="font-semibold text-ink-primary">{{ grandTotal().toFixed(2) }}</span></span>
                        <button type="button" @click="addRow" class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-sm font-medium text-primary transition hover:bg-primary-light">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            {{ t('incoming.addItem') }}
                        </button>
                    </div>
                </div>
                <InputError :message="form.errors.items" class="mb-3" />

                <div v-for="(r, i) in rows" :key="i" class="border-t border-borderline py-3">
                    <div class="grid gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-4">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.part') }}</label>
                            <select v-model="r.part_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                                <option value="">{{ t('incoming.choosePart') }}</option>
                                <option v-for="p in parts" :key="p.id" :value="p.id">{{ p.part_number }} · {{ p.part_name }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.size') }}</label>
                            <input v-model="r.size" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.qty') }}</label>
                            <input v-model="r.qty_goods" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.unit') }}</label>
                            <select v-model="r.unit_goods" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                                <option v-for="code in uomCodes" :key="code" :value="code">{{ code }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-1">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.price') }}</label>
                            <input v-model="r.price" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="flex items-end sm:col-span-1">
                            <button type="button" @click="removeRow(i)" :disabled="rows.length <= 1" class="rounded-lg border border-borderline px-3 py-2 text-sm text-danger transition hover:bg-danger/10 disabled:opacity-40" :aria-label="t('incoming.delete')">✕</button>
                        </div>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.packingQty') }}</label>
                            <input v-model="r.qty_bundle" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.packingType') }}</label>
                            <select v-model="r.unit_bundle" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                                <option value="">{{ t('incoming.choose') }}</option>
                                <option v-for="code in packingUnits" :key="code" :value="code">{{ code }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.netWeight') }}</label>
                            <input v-model="r.weight_nett" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.grossWeight') }}</label>
                            <input v-model="r.weight_gross" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-6">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.notes') }}</label>
                            <input v-model="r.notes" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-6 flex items-end justify-end">
                            <span class="text-sm tabular-nums text-ink-secondary">{{ t('incoming.total') }} <span class="font-semibold text-ink-primary">{{ rowTotal(r).toFixed(2) }}</span></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <BackButton :href="route('local-pos.index')">{{ t('incoming.cancel') }}</BackButton>
                <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-5 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                    {{ form.processing ? t('incoming.saving') : t('incoming.save') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
