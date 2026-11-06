<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import type { IncomingArrival, Supplier, PurchaseOrder, TruckingCompany } from '@/types';

const { t } = useI18n();

interface ItemRow {
    id: number | null;
    part_id: number | null;
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
    po_qty_ref?: number; // referensi kuantitas asli PO
}

const props = defineProps<{
    arrival: IncomingArrival | null;
    suppliers: Array<Pick<Supplier, 'id' | 'supplier_code' | 'supplier_name' | 'source'>>;
    truckings: Array<Pick<TruckingCompany, 'id' | 'company_code' | 'company_name'>>;
    materialOptions: Array<{ supplier_id: number; group: string; size: string; part_id: number; unit: string | null }>;
    purchaseOrders: Array<any>;
    uomCodes: string[];
    packingUnits: string[];
}>();

const isLocalArrival = ref(Boolean(props.arrival?.is_local ?? false));

const blankItem = (): ItemRow => ({
    id: null, part_id: null, material_group: '', size: '',
    qty_goods: '', unit_goods: '', qty_bundle: '', unit_bundle: '',
    weight_nett: '', unit_weight: '', weight_gross: '', price: '', total_price: '',
    is_foc: false, notes: '',
});

const itemRows = ref<ItemRow[]>(
    props.arrival?.items?.map((i) => ({
        id: i.id ?? null,
        part_id: i.part_id ?? null,
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

const form = useForm({
    is_local: isLocalArrival.value,
    invoice_no: props.arrival?.invoice_no ?? '',
    delivery_note_no: (props.arrival as any)?.delivery_note_no ?? '',
    invoice_date: props.arrival?.invoice_date ?? new Date().toISOString().slice(0, 10),
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
    currency: props.arrival?.currency ?? 'IDR',
    notes: props.arrival?.notes ?? '',
    status: props.arrival?.status ?? 'pending',
    items: [] as any[],
});

// Filter PO berdasarkan Supplier terpilih dan switcher tab: Lokal vs Impor
const filteredPurchaseOrders = computed(() => {
    return props.purchaseOrders.filter((po) => {
        // Jika supplier sudah dipilih, hanya tampilkan PO milik supplier tersebut
        if (form.supplier_id && Number(po.supplier_id) !== Number(form.supplier_id)) {
            return false;
        }

        const supp = props.suppliers.find((s) => s.id === po.supplier_id);
        const suppSource = supp?.source ?? (po.supplier?.source ?? 'import');
        return isLocalArrival.value ? suppSource === 'local' : suppSource === 'import';
    });
});

function onSupplierChange() {
    resetMaterialRows();
    // Jika PO yang saat ini terpilih bukan milik supplier baru, reset purchase_order_id
    if (form.purchase_order_id) {
        const po = props.purchaseOrders.find((p) => p.id === Number(form.purchase_order_id));
        if (po && Number(po.supplier_id) !== Number(form.supplier_id)) {
            form.purchase_order_id = '';
        }
    }
}

// Auto-populate item saat PO dipilih
function onPurchaseOrderChange() {
    const poId = Number(form.purchase_order_id);
    if (!poId) return;

    const po = props.purchaseOrders.find((p) => p.id === poId);
    if (!po) return;

    // Auto-set supplier jika belum diset
    if (po.supplier_id) {
        form.supplier_id = po.supplier_id;
    }

    // Auto-populate items dari PO
    if (po.items && Array.isArray(po.items) && po.items.length > 0) {
        itemRows.value = po.items.map((it: any) => ({
            id: null,
            part_id: it.part_id ?? null,
            material_group: it.part?.part_number ?? it.material_group ?? '',
            size: it.size ?? it.part?.size ?? 'STD',
            qty_goods: it.qty ?? '',
            po_qty_ref: it.qty ?? 0,
            unit_goods: it.unit ?? it.part?.uom?.code ?? 'KGM',
            qty_bundle: '',
            unit_bundle: '',
            weight_nett: it.unit === 'KGM' || !it.unit ? it.qty : '',
            unit_weight: 'KGM',
            weight_gross: '',
            price: it.price ?? '',
            total_price: (Number(it.qty) || 0) * (Number(it.price) || 0) || '',
            is_foc: false,
            notes: it.notes ?? '',
        }));
    }
}

watch(isLocalArrival, (val) => {
    form.is_local = val;
    form.currency = val ? 'IDR' : 'USD';
    form.purchase_order_id = '';
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
    if (match?.part_id) row.part_id = match.part_id;
    recalcPrice(row);
}

function resetMaterialRows() {
    itemRows.value.forEach((row) => {
        row.material_group = '';
        row.size = '';
        row.part_id = null;
    });
}

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

function isRowEmpty(r: ItemRow) {
    return !r.material_group && !r.size && r.qty_goods === '' && r.total_price === '';
}

function submit() {
    form.items = itemRows.value
        .filter((r) => !isRowEmpty(r))
        .map((r) => ({
            id: r.id,
            part_id: r.part_id,
            material_group: r.material_group,
            size: r.size || 'STD',
            qty_goods: r.qty_goods === '' ? 0 : Number(r.qty_goods),
            unit_goods: r.unit_goods || 'KGM',
            qty_bundle: r.qty_bundle === '' ? null : Number(r.qty_bundle),
            unit_bundle: r.unit_bundle,
            weight_nett: r.weight_nett === '' ? (r.unit_goods === 'KGM' ? Number(r.qty_goods) : null) : Number(r.weight_nett),
            unit_weight: r.unit_weight || 'KGM',
            weight_gross: r.weight_gross === '' ? null : Number(r.weight_gross),
            price: r.price === '' ? null : Number(r.price),
            total_price: r.total_price === '' ? null : Number(r.total_price),
            is_foc: r.is_foc,
            notes: r.notes,
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

        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">
                {{ arrival ? t('incoming.editArrival', { number: arrival.arrival_no }) : t('incoming.newArrival') }}
            </h1>

            <!-- Switcher Tab: Lokal vs Impor (sesuai keputusan Q2 = B) -->
            <div class="inline-flex rounded-xl border border-borderline bg-surface p-1 shadow-sm">
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :class="!isLocalArrival ? 'bg-primary text-white' : 'text-ink-secondary hover:bg-primary-light'"
                    @click="isLocalArrival = false"
                >
                    {{ t('incoming.tabImportArrival') }}
                </button>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :class="isLocalArrival ? 'bg-primary text-white' : 'text-ink-secondary hover:bg-primary-light'"
                    @click="isLocalArrival = true"
                >
                    {{ t('incoming.tabLocalArrival') }}
                </button>
            </div>
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <datalist id="uom-codes">
                <option v-for="code in uomCodes" :key="code" :value="code" />
            </datalist>

            <!-- FORM KEDATANGAN LOKAL (RINGKAS) -->
            <div v-if="isLocalArrival" class="rounded-xl border border-borderline bg-surface p-5 space-y-4">
                <div class="border-b border-borderline pb-3">
                    <h2 class="text-sm font-bold text-ink-primary">Data Kedatangan Lokal</h2>
                    <p class="text-xs text-ink-secondary">Pilih nomor PO untuk memuat item otomatis, masukkan No. Surat Jalan, No. PEN, dan No. AJU.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.supplier') }} <span class="text-danger">*</span></label>
                        <select
                            :aria-label="t('incoming.supplier')"
                            v-model="form.supplier_id"
                            @change="onSupplierChange"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        >
                            <option value="">{{ t('incoming.choose') }}</option>
                            <option v-for="s in suppliers.filter(s => s.source === 'local')" :key="s.id" :value="s.id">{{ s.supplier_name }}</option>
                        </select>
                        <InputError :message="form.errors.supplier_id" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">
                            {{ t('incoming.purchaseOrder') }}
                            <span v-if="!form.supplier_id" class="text-[11px] text-ink-secondary italic font-normal">(pilih supplier dahulu)</span>
                        </label>
                        <select
                            :aria-label="t('incoming.purchaseOrder')"
                            v-model="form.purchase_order_id"
                            @change="onPurchaseOrderChange"
                            :disabled="!form.supplier_id && filteredPurchaseOrders.length === 0"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-60"
                        >
                            <option value="">{{ form.supplier_id ? t('incoming.choose') : '— Pilih Supplier dahulu —' }}</option>
                            <option v-for="po in filteredPurchaseOrders" :key="po.id" :value="po.id">
                                {{ po.po_no }} (Sisa: {{ Number(po.remaining_qty || 0).toLocaleString() }})
                            </option>
                        </select>
                        <InputError :message="form.errors.purchase_order_id" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.deliveryNoteInvoiceNo') }}</label>
                        <input
                            :aria-label="t('incoming.deliveryNoteInvoiceNo')"
                            v-model="form.delivery_note_no"
                            type="text"
                            placeholder="Contoh: SJ-2026/001"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.delivery_note_no" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.arrivalDate') }}</label>
                        <input
                            :aria-label="t('incoming.arrivalDate')"
                            v-model="form.invoice_date"
                            type="date"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.invoice_date" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.penNo') }}</label>
                        <input
                            :aria-label="t('incoming.penNo')"
                            v-model="form.pen_no"
                            type="text"
                            placeholder="No. PEN Bea Cukai"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.pen_no" class="mt-1" />
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
                        <InputError :message="form.errors.aju_no" class="mt-1" />
                    </div>

                    <div class="lg:col-span-3">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.notes') }}</label>
                        <input
                            :aria-label="t('incoming.notes')"
                            v-model="form.notes"
                            type="text"
                            placeholder="Catatan kedatangan..."
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                    </div>
                </div>
            </div>

            <!-- FORM KEDATANGAN IMPOR (LENGKAP KONT/VESSEL) -->
            <div v-else class="rounded-xl border border-borderline bg-surface p-5 space-y-4">
                <div class="border-b border-borderline pb-3">
                    <h2 class="text-sm font-bold text-ink-primary">Data Kedatangan Impor</h2>
                    <p class="text-xs text-ink-secondary">Lengkapi data kepabeanan impor, BL, kapal, dan kontainer.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.supplier') }} <span class="text-danger">*</span></label>
                        <select :aria-label="t('incoming.supplier')" v-model="form.supplier_id" @change="onSupplierChange" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('incoming.choose') }}</option>
                            <option v-for="s in suppliers.filter(s => s.source === 'import')" :key="s.id" :value="s.id">{{ s.supplier_name }}</option>
                        </select>
                        <InputError :message="form.errors.supplier_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">
                            {{ t('incoming.purchaseOrder') }}
                            <span v-if="!form.supplier_id" class="text-[11px] text-ink-secondary italic font-normal">(pilih supplier dahulu)</span>
                        </label>
                        <select :aria-label="t('incoming.purchaseOrder')" v-model="form.purchase_order_id" @change="onPurchaseOrderChange" :disabled="!form.supplier_id && filteredPurchaseOrders.length === 0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-60">
                            <option value="">{{ form.supplier_id ? t('incoming.choose') : '— Pilih Supplier dahulu —' }}</option>
                            <option v-for="po in filteredPurchaseOrders" :key="po.id" :value="po.id">
                                {{ po.po_no }} (Sisa: {{ Number(po.remaining_qty || 0).toLocaleString() }})
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.invoiceNo') }}</label>
                        <input :aria-label="t('incoming.invoiceNo')" v-model="form.invoice_no" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.invoice_no" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.invoiceDate') }}</label>
                        <input :aria-label="t('incoming.invoiceDate')" v-model="form.invoice_date" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.trucking') }}</label>
                        <select :aria-label="t('incoming.trucking')" v-model="form.trucking_company_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('incoming.choose') }}</option>
                            <option v-for="tr in truckings" :key="tr.id" :value="tr.id">{{ tr.company_name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.vessel') }}</label>
                        <input :aria-label="t('incoming.vessel')" v-model="form.vessel" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.etaGci') }}</label>
                        <input :aria-label="t('incoming.etaGci')" v-model="form.eta_gci" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.etd') }}</label>
                        <input :aria-label="t('incoming.etd')" v-model="form.etd" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.eta') }}</label>
                        <input :aria-label="t('incoming.eta')" v-model="form.eta" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.billOfLading') }}</label>
                        <input :aria-label="t('incoming.billOfLading')" v-model="form.bill_of_lading" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.penNo') }}</label>
                        <input :aria-label="t('incoming.penNo')" v-model="form.pen_no" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.ajuNo') }}</label>
                        <input :aria-label="t('incoming.ajuNo')" v-model="form.aju_no" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.currency') }}</label>
                        <select :aria-label="t('incoming.currency')" v-model="form.currency" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="USD">USD</option>
                            <option value="IDR">IDR</option>
                            <option value="KRW">KRW</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Items Kedatangan (Auto-populated dari PO & bisa diedit parsial) -->
            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-ink-primary">{{ t('incoming.items') }}</h2>
                        <p class="text-xs text-ink-secondary">Periksa dan sesuaikan kuantitas datang jika pengiriman parsial/bertahap.</p>
                    </div>
                    <button type="button" @click="addItem" class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-sm font-medium text-primary transition hover:bg-primary-light">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        {{ t('incoming.addRow') }}
                    </button>
                </div>
                <InputError :message="form.errors.items" class="mb-3" />

                <div v-for="(r, i) in itemRows" :key="i" class="border-t border-borderline py-3">
                    <div class="grid gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.materialGroup') }}</label>
                            <input
                                v-if="r.part_id"
                                :value="r.material_group"
                                readonly
                                class="mt-1 w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary font-medium"
                            />
                            <select
                                v-else
                                v-model="r.material_group"
                                @change="r.size = ''; syncMaterial(r)"
                                class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                            >
                                <option value="">{{ t('incoming.chooseGroup') }}</option>
                                <option v-for="group in materialGroups" :key="group" :value="group">{{ group }}</option>
                            </select>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.size') }}</label>
                            <input
                                v-if="r.part_id"
                                :value="r.size"
                                readonly
                                class="mt-1 w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-secondary"
                            />
                            <select
                                v-else
                                v-model="r.size"
                                @change="syncMaterial(r)"
                                :disabled="!form.supplier_id || !r.material_group"
                                class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-50"
                            >
                                <option value="">{{ t('incoming.chooseSize') }}</option>
                                <option v-for="option in sizesFor(r.material_group)" :key="option.part_id" :value="option.size">{{ option.size }}</option>
                            </select>
                        </div>

                        <div class="sm:col-span-2">
                            <div class="flex justify-between">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.qtyGoods') }}</label>
                                <span v-if="r.po_qty_ref" class="text-[11px] text-ink-secondary font-mono">PO: {{ r.po_qty_ref }}</span>
                            </div>
                            <input v-model="r.qty_goods" type="number" step="0.0001" min="0" @input="recalcPrice(r)" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary font-bold text-primary" />
                        </div>

                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.unitGoods') }}</label>
                            <input v-model="r.unit_goods" list="uom-codes" type="text" placeholder="KGM / PCS" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>

                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.netWeight') }}</label>
                            <input v-model="r.weight_nett" type="number" step="0.0001" @input="recalcPrice(r)" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        </div>

                        <div class="flex items-end justify-end sm:col-span-1">
                            <button type="button" @click="removeItem(i)" :disabled="itemRows.length <= 1" class="rounded-lg border border-borderline px-3 py-2 text-sm text-danger transition hover:bg-danger/10 disabled:opacity-40">✕</button>
                        </div>
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
