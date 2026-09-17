<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import type { IncomingArrival, Supplier, PurchaseOrder, TruckingCompany } from '@/types';

const { t } = useI18n();

interface ItemRow {
    id: number | null;
    material_group: string;
    size: string;
    qty_goods: number | '';
    unit_goods: string;
    qty_bundle: number | '';
    unit_bundle: string;
    weight_nett: number | '';
    unit_weight: string;
    weight_gross: number | '';
    price: number | '';
    total_price: number | '';
    is_foc: boolean;
    notes: string;
}

interface ContainerRow {
    container_no: string;
    seal_code: string;
    size: string;
}

const props = defineProps<{
    arrival: IncomingArrival | null;
    suppliers: Array<Pick<Supplier, 'id' | 'supplier_code' | 'supplier_name'>>;
    truckings: Array<Pick<TruckingCompany, 'id' | 'company_code' | 'company_name'>>;
    materialOptions: Array<{ supplier_id: number; group: string; size: string; part_id: number; unit: string | null }>;
    purchaseOrders: Array<Pick<PurchaseOrder, 'id' | 'po_no' | 'supplier_id'>>;
}>();

const blankItem = (): ItemRow => ({
    id: null, material_group: '', size: '',
    qty_goods: '', unit_goods: '', qty_bundle: '', unit_bundle: '',
    weight_nett: '', unit_weight: '', weight_gross: '', price: '', total_price: '',
    is_foc: false, notes: '',
});
const blankContainer = (): ContainerRow => ({ container_no: '', seal_code: '', size: '' });

const itemRows = ref<ItemRow[]>(
    props.arrival?.items?.map((i) => ({
        id: i.id ?? null,
        material_group: i.material_group ?? '',
        size: i.size ?? '',
        qty_goods: i.qty_goods,
        unit_goods: i.unit_goods ?? '',
        qty_bundle: i.qty_bundle ?? '',
        unit_bundle: i.unit_bundle ?? '',
        weight_nett: i.weight_nett ?? '',
        unit_weight: i.unit_weight ?? '',
        weight_gross: i.weight_gross ?? '',
        price: i.price ?? '',
        total_price: i.total_price ?? '',
        is_foc: i.is_foc ?? false,
        notes: i.notes ?? '',
    })) ?? [blankItem()],
);

const containerRows = ref<ContainerRow[]>(
    props.arrival?.containers?.map((c) => ({ container_no: c.container_no, seal_code: c.seal_code ?? '', size: c.size ?? '' })) ?? [],
);

const form = useForm({
    invoice_no: props.arrival?.invoice_no ?? '',
    invoice_date: props.arrival?.invoice_date ?? '',
    supplier_id: props.arrival?.supplier_id ?? ('' as number | ''),
    trucking_company_id: props.arrival?.trucking_company_id ?? ('' as number | ''),
    purchase_order_id: props.arrival?.purchase_order_id ?? ('' as number | ''),
    vessel: props.arrival?.vessel ?? '',
    etd: props.arrival?.etd ?? '',
    eta: props.arrival?.eta ?? '',
    eta_gci: props.arrival?.eta_gci ?? '',
    bill_of_lading: props.arrival?.bill_of_lading ?? '',
    pen_no: props.arrival?.pen_no ?? '',
    pen_date: props.arrival?.pen_date ?? '',
    aju_no: props.arrival?.aju_no ?? '',
    price_term: props.arrival?.price_term ?? '',
    hs_code: props.arrival?.hs_code ?? '',
    port_of_loading: props.arrival?.port_of_loading ?? '',
    country: props.arrival?.country ?? '',
    currency: props.arrival?.currency ?? 'USD',
    notes: props.arrival?.notes ?? '',
    status: props.arrival?.status ?? 'pending',
    items: [] as any[],
    containers: [] as any[],
});

const materialGroups = computed(() => Array.from(new Set(
    props.materialOptions.filter((m) => !form.supplier_id || m.supplier_id === Number(form.supplier_id)).map((m) => m.group),
)));

function sizesFor(group: string) {
    return props.materialOptions.filter((m) => m.supplier_id === Number(form.supplier_id) && m.group === group);
}

function syncMaterial(row: ItemRow) {
    const match = sizesFor(row.material_group).find((m) => m.size === row.size);
    if (match?.unit && !row.unit_goods) row.unit_goods = match.unit;
    recalcPrice(row);
}

function resetMaterialRows() {
    itemRows.value.forEach((row) => {
        row.material_group = '';
        row.size = '';
    });
}

// Ported from material_incoming arrivals/create: price is never typed by the user.
// Total is divided by net weight (per KGM) when present, otherwise by qty_goods,
// floored to 3 decimals using integer cents to avoid float drift.
function toCents(value: number | ''): number {
    if (value === '' || value === null) return 0;
    const n = Number(value);
    if (!Number.isFinite(n)) return 0;
    return Math.round(n * 100);
}

function recalcPrice(row: ItemRow) {
    const totalCents = toCents(row.total_price);
    const weightCenti = toCents(row.weight_nett);
    const qty = Number(row.qty_goods) || 0;
    if (qty <= 0 || totalCents <= 0) {
        row.price = '';
        return;
    }
    const priceMilli = weightCenti > 0
        ? Math.floor((totalCents * 1000 + weightCenti / 2) / weightCenti)
        : Math.floor((totalCents * 10) / qty);
    row.price = priceMilli / 1000;
}

function weightInvalid(row: ItemRow) {
    const nett = Number(row.weight_nett);
    const gross = Number(row.weight_gross);
    return Number.isFinite(nett) && Number.isFinite(gross) && nett > 0 && gross > 0 && nett > gross;
}

function formatMilli(value: number | ''): string {
    if (value === '' || value === null) return '';
    const milli = Math.floor(Number(value) * 1000);
    if (!Number.isFinite(milli)) return '';
    let s = String(milli);
    if (s.length <= 3) s = s.padStart(4, '0');
    const intPart = s.slice(0, -3).replace(/^0+/, '') || '0';
    return intPart + '.' + s.slice(-3);
}

const rowErrorList = computed(() =>
    Object.entries(form.errors)
        .filter(([k]) => k.startsWith('items.'))
        .map(([k, v]) => ({ row: Number(k.split('.')[1]) + 1, msg: v })),
);

function addItem() { itemRows.value.push(blankItem()); }
function removeItem(i: number) { if (itemRows.value.length > 1) itemRows.value.splice(i, 1); }
function addContainer() { containerRows.value.push(blankContainer()); }
function removeContainer(i: number) { containerRows.value.splice(i, 1); }

function isRowEmpty(r: ItemRow) {
    return !r.material_group && !r.size && r.qty_goods === '' && r.total_price === '';
}

function submit() {
    form.items = itemRows.value
        .filter((r) => !isRowEmpty(r))
        .map((r) => ({
            id: r.id,
            material_group: r.material_group,
            size: r.size,
            qty_goods: r.qty_goods === '' ? 0 : Number(r.qty_goods),
            unit_goods: r.unit_goods,
            qty_bundle: r.qty_bundle === '' ? null : Number(r.qty_bundle),
            unit_bundle: r.unit_bundle,
            weight_nett: r.weight_nett === '' ? null : Number(r.weight_nett),
            unit_weight: r.unit_weight,
            weight_gross: r.weight_gross === '' ? null : Number(r.weight_gross),
            price: r.price === '' ? null : Number(r.price),
            total_price: r.total_price === '' ? null : Number(r.total_price),
            is_foc: r.is_foc,
            notes: r.notes,
        })) as any;

    form.containers = containerRows.value.map((c) => ({
        container_no: c.container_no,
        seal_code: c.seal_code,
        size: c.size,
    })) as any;

    if (props.arrival) {
        form.put(route('incoming-arrivals.update', props.arrival.id));
    } else {
        form.post(route('incoming-arrivals.store'));
    }
}
</script>

<template>
    <Head :title="arrival ? t('incoming.editArrival', { number: arrival.arrival_no }) : t('incoming.newArrival')" />
    <AppLayout>
        <BackButton :href="route('incoming-arrivals.index')" class="mb-4" />

        <h1 class="mb-6 text-2xl font-bold tracking-tight text-ink-primary">
            {{ arrival ? t('incoming.editArrival', { number: arrival.arrival_no }) : t('incoming.newArrival') }}
        </h1>

        <form @submit.prevent="submit" class="space-y-6">
            <!-- Header -->
            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.invoiceNo') }}</label>
                        <input v-model="form.invoice_no" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.invoice_no" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.invoiceDate') }}</label>
                        <input v-model="form.invoice_date" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.supplier') }}</label>
                        <select v-model="form.supplier_id" @change="resetMaterialRows" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('incoming.choose') }}</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.supplier_code }} · {{ s.supplier_name }}</option>
                        </select>
                        <InputError :message="form.errors.supplier_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.trucking') }}</label>
                        <select v-model="form.trucking_company_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('incoming.choose') }}</option>
                            <option v-for="t in truckings" :key="t.id" :value="t.id">{{ t.company_name }}</option>
                        </select>
                        <InputError :message="form.errors.trucking_company_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.purchaseOrder') }}</label>
                        <select v-model="form.purchase_order_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('incoming.choose') }}</option>
                            <option v-for="po in purchaseOrders" :key="po.id" :value="po.id">{{ po.po_no }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.vessel') }}</label>
                        <input v-model="form.vessel" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.etaGci') }}</label>
                        <input v-model="form.eta_gci" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.etd') }}</label>
                        <input v-model="form.etd" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.eta') }}</label>
                        <input v-model="form.eta" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.billOfLading') }}</label>
                        <input v-model="form.bill_of_lading" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.penNo') }}</label>
                        <input v-model="form.pen_no" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.penDate') }}</label>
                        <input v-model="form.pen_date" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.ajuNo') }}</label>
                        <input v-model="form.aju_no" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.priceTerm') }}</label>
                        <select v-model="form.price_term" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">—</option>
                            <option value="FOB">FOB</option>
                            <option value="CIF">CIF</option>
                            <option value="CFR">CFR</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.hsCode') }}</label>
                        <input v-model="form.hs_code" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.portLoading') }}</label>
                        <input v-model="form.port_of_loading" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.country') }}</label>
                        <input v-model="form.country" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.currency') }}</label>
                        <select v-model="form.currency" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="USD">USD</option>
                            <option value="IDR">IDR</option>
                            <option value="KRW">KRW</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Items -->
            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-ink-primary">{{ t('incoming.items') }}</h2>
                    <button type="button" @click="addItem" class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-sm font-medium text-primary transition hover:bg-primary-light">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        {{ t('incoming.addRow') }}
                    </button>
                </div>
                <InputError :message="form.errors.items" class="mb-3" />
                <p v-if="rowErrorList.length" class="mb-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">
                    <span v-for="(err, ei) in rowErrorList" :key="ei" class="block">{{ t('incoming.itemError', { row: err.row, message: err.msg }) }}</span>
                </p>
                <div v-for="(r, i) in itemRows" :key="i" class="border-t border-borderline py-3">
                        <div class="grid gap-3 sm:grid-cols-12">
                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.materialGroup') }}</label>
                                <select v-model="r.material_group" @change="r.size = ''; syncMaterial(r)" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                                    <option value="">{{ t('incoming.chooseGroup') }}</option>
                                    <option v-for="group in materialGroups" :key="group" :value="group">{{ group }}</option>
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.size') }}</label>
                                <select v-model="r.size" @change="syncMaterial(r)" :disabled="!form.supplier_id || !r.material_group" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-50">
                                    <option value="">{{ t('incoming.chooseSize') }}</option>
                                    <option v-for="option in sizesFor(r.material_group)" :key="option.part_id" :value="option.size">{{ option.size }}</option>
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.qtyGoods') }}</label>
                                <input v-model="r.qty_goods" type="number" step="0.0001" min="0" @input="recalcPrice(r)" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.unitGoods') }}</label>
                                <input v-model="r.unit_goods" type="text" placeholder="KGM / PCS" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.packingQty') }}</label>
                                <input v-model="r.qty_bundle" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.packingType') }}</label>
                                <select v-model="r.unit_bundle" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                                    <option value="">{{ t('incoming.choose') }}</option>
                                    <option value="PALLET">{{ t('incoming.unit_PALLET') }}</option>
                                    <option value="BUNDLE">{{ t('incoming.unit_BUNDLE') }}</option>
                                    <option value="BOX">{{ t('incoming.unit_BOX') }}</option>
                                    <option value="BAG">{{ t('incoming.unit_BAG') }}</option>
                                    <option value="ROLL">{{ t('incoming.unit_ROLL') }}</option>
                                    <option value="PACKAGES">{{ t('incoming.unit_PACKAGES') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-12">
                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.netWeight') }}</label>
                                <input v-model="r.weight_nett" type="number" step="0.0001" @input="recalcPrice(r)" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.grossWeight') }}</label>
                                <input v-model="r.weight_gross" type="number" step="0.0001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.totalPrice') }}</label>
                                <input v-model="r.total_price" type="number" step="0.01" @input="recalcPrice(r)" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.autoPrice') }}</label>
                                <input :value="formatMilli(r.price)" type="text" readonly class="mt-1 w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-secondary" />
                            </div>
                            <div class="flex items-end sm:col-span-3">
                                <label class="flex items-center gap-2 text-sm text-ink-primary">
                                    <input v-model="r.is_foc" type="checkbox" class="h-4 w-4 rounded border-borderline text-primary focus:ring-primary" />
                                    {{ t('incoming.foc') }}
                                </label>
                                <p v-if="weightInvalid(r)" class="ml-4 text-xs font-semibold text-danger">
                                    {{ t('incoming.invalidWeight') }}
                                </p>
                            </div>
                            <div class="flex items-end justify-end sm:col-span-1">
                                <button type="button" @click="removeItem(i)" :disabled="itemRows.length <= 1" class="rounded-lg border border-borderline px-3 py-2 text-sm text-danger transition hover:bg-danger/10 disabled:opacity-40" :aria-label="t('incoming.delete')">✕</button>
                            </div>
                        </div>
                    </div>
                </div>

            <!-- Containers -->
            <div v-if="!arrival" class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-base font-semibold text-ink-primary">{{ t('incoming.containers') }}</h2>
                        <button type="button" @click="addContainer" class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-sm font-medium text-primary transition hover:bg-primary-light">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            {{ t('incoming.addContainer') }}
                        </button>
                    </div>
                    <div v-for="(c, i) in containerRows" :key="i" class="grid gap-3 border-t border-borderline py-3 sm:grid-cols-12">
                        <div class="sm:col-span-4">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.containerNo') }}</label>
                            <input v-model="c.container_no" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.sealCode') }}</label>
                            <input v-model="c.seal_code" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.size') }}</label>
                            <input v-model="c.size" type="text" :placeholder="t('incoming.containerSize')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>
                        <div class="flex items-end sm:col-span-2">
                            <button type="button" @click="removeContainer(i)" class="rounded-lg border border-borderline px-3 py-2 text-sm text-danger transition hover:bg-danger/10">{{ t('incoming.delete') }}</button>
                        </div>
                    </div>
                </div>

            <div class="flex items-center justify-end gap-3">
                <BackButton :href="route('incoming-arrivals.index')">{{ t('incoming.cancel') }}</BackButton>
                <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-5 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                    {{ form.processing ? t('incoming.saving') : t('incoming.save') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
