<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import type { IncomingArrival, Supplier, PurchaseOrder, TruckingCompany } from '@/types';

const { t } = useI18n();

interface ItemRow {
    id: number | null;
    part_id: number | null;
    part_number?: string;
    part_name?: string;
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

interface ContainerRow {
    container_no: string;
    seal_code: string;
    size: string;
}

const props = defineProps<{
    arrival: IncomingArrival | null;
    suppliers: Array<Pick<Supplier, 'id' | 'supplier_code' | 'supplier_name' | 'source'>>;
    truckings: Array<Pick<TruckingCompany, 'id' | 'company_code' | 'company_name'>>;
    materialOptions: Array<{
        supplier_id: number;
        group: string;
        size: string;
        part_id: number;
        part_number?: string;
        part_name?: string;
        unit: string | null;
    }>;
    purchaseOrders: Array<any>;
    uomCodes: string[];
    packingUnits: string[];
}>();

const isLocalArrival = ref(Boolean(props.arrival?.is_local ?? false));

const blankItem = (): ItemRow => ({
    id: null,
    part_id: null,
    part_number: '',
    part_name: '',
    material_group: '',
    size: '',
    qty_goods: '',
    unit_goods: 'KGM',
    qty_bundle: '',
    unit_bundle: props.packingUnits[0] ?? 'PALLET',
    weight_nett: '',
    unit_weight: 'KGM',
    weight_gross: '',
    price: '',
    total_price: '',
    is_foc: false,
    notes: '',
});

const blankContainer = (): ContainerRow => ({ container_no: '', seal_code: '', size: '' });

const containerRows = ref<ContainerRow[]>(
    props.arrival?.containers?.map((c) => ({
        container_no: c.container_no ?? '',
        seal_code: c.seal_code ?? '',
        size: c.size ?? '',
    })) ?? [blankContainer()],
);

function addContainer() { containerRows.value.push(blankContainer()); }
function removeContainer(i: number) {
    if (containerRows.value.length > 1) containerRows.value.splice(i, 1);
}
const containerSizes = ['20 FT', '40 FT', '40 HC', '45 HC'];

const itemRows = ref<ItemRow[]>(
    props.arrival?.items?.map((i: any) => ({
        id: i.id ?? null,
        part_id: i.part_id ?? null,
        part_number: i.part?.part_number ?? '',
        part_name: i.part?.part_name ?? '',
        material_group: i.material_group ?? i.part?.part_number ?? '',
        size: i.size ?? i.part?.size ?? '',
        qty_goods: i.qty_goods,
        unit_goods: i.unit_goods ?? i.part?.uom?.code ?? 'KGM',
        qty_bundle: i.qty_bundle ?? '',
        unit_bundle: i.unit_bundle ?? (props.packingUnits[0] ?? 'PALLET'),
        weight_nett: i.weight_nett ?? '',
        unit_weight: i.unit_weight ?? 'KGM',
        weight_gross: i.weight_gross ?? '',
        price: i.price ?? '',
        total_price: i.total_price ?? '',
        is_foc: Boolean(i.is_foc),
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
    containers: [] as Array<{ container_no: string; seal_code: string | null; size: string | null }>,
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
            part_number: it.part?.part_number ?? '',
            part_name: it.part?.part_name ?? '',
            material_group: it.part?.part_number ?? it.material_group ?? '',
            size: it.size ?? it.part?.size ?? 'STD',
            qty_goods: it.qty ?? '',
            po_qty_ref: it.qty ?? 0,
            unit_goods: it.unit ?? it.part?.uom?.code ?? 'KGM',
            qty_bundle: '',
            unit_bundle: props.packingUnits[0] ?? 'PALLET',
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
    // Container hanya relevan untuk kedatangan impor; saat pindah ke Lokal,
    // baris kosong tidak ikut terkirim karena disaring di submit().
});

const supplierMaterialOptions = computed(() => {
    const list = props.materialOptions.filter(
        (m) => !form.supplier_id || m.supplier_id === Number(form.supplier_id),
    );
    return list.map((m) => ({
        value: m.part_id,
        label: m.part_number ? `${m.part_number} · ${m.part_name}` : m.group,
        sublabel: `${m.group} · Size: ${m.size} · UOM: ${m.unit ?? 'KGM'}`,
        raw: m,
    }));
});

function onSelectMaterial(r: ItemRow, partId: number | '') {
    if (!partId) {
        r.part_id = null;
        r.part_number = '';
        r.part_name = '';
        r.material_group = '';
        r.size = '';
        return;
    }
    const match = props.materialOptions.find((m) => m.part_id === Number(partId));
    if (match) {
        r.part_id = match.part_id;
        r.part_number = match.part_number ?? '';
        r.part_name = match.part_name ?? '';
        r.material_group = match.group;
        r.size = match.size || 'STD';
        if (match.unit && !r.unit_goods) {
            r.unit_goods = match.unit;
        }
        if (r.unit_goods === 'KGM' && r.qty_goods && !r.weight_nett) {
            r.weight_nett = r.qty_goods;
        }
        recalcPrice(r);
    }
}

function onQtyGoodsInput(r: ItemRow) {
    if (r.unit_goods === 'KGM' || !r.weight_nett) {
        if (r.unit_goods === 'KGM') {
            r.weight_nett = r.qty_goods;
        }
    }
    recalcPrice(r);
}

function resetMaterialRows() {
    itemRows.value.forEach((row) => {
        row.material_group = '';
        row.size = '';
        row.part_id = null;
        row.part_number = '';
        row.part_name = '';
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
    return !r.part_id && !r.material_group && !r.size && r.qty_goods === '' && r.total_price === '';
}

function submit() {
    form.items = itemRows.value
        .filter((r) => !isRowEmpty(r))
        .map((r) => ({
            id: r.id,
            part_id: r.part_id,
            material_group: r.material_group || (r.part_number ? r.part_number : null),
            size: r.size || 'STD',
            qty_goods: r.qty_goods === '' ? 0 : Number(r.qty_goods),
            unit_goods: r.unit_goods || 'KGM',
            qty_bundle: r.qty_bundle === '' ? null : Number(r.qty_bundle),
            unit_bundle: r.unit_bundle || null,
            weight_nett: r.weight_nett === '' ? (r.unit_goods === 'KGM' ? Number(r.qty_goods) : null) : Number(r.weight_nett),
            unit_weight: 'KGM',
            weight_gross: r.weight_gross === '' ? null : Number(r.weight_gross),
            price: r.price === '' ? null : Number(r.price),
            total_price: r.total_price === '' ? null : Number(r.total_price),
            is_foc: r.is_foc,
            notes: r.notes,
        })) as any;

    // Container hanya untuk kedatangan impor; baris tanpa nomor kontainer dibuang.
    form.containers = isLocalArrival.value
        ? []
        : containerRows.value
              .filter((c) => c.container_no.trim() !== '')
              .map((c) => ({
                  container_no: c.container_no.trim(),
                  seal_code: c.seal_code.trim() || null,
                  size: c.size || null,
              }));

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
                        <div class="mt-1">
                            <SearchableSelect
                                v-model="form.supplier_id"
                                :options="suppliers.filter(s => s.source === 'local')"
                                value-key="id"
                                label-key="supplier_name"
                                sublabel-key="supplier_code"
                                :placeholder="t('incoming.choose')"
                                :aria-label="t('incoming.supplier')"
                                :error="form.errors.supplier_id"
                                @change="onSupplierChange"
                            />
                        </div>
                        <InputError :message="form.errors.supplier_id" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.purchaseOrder') }}</label>
                        <div class="mt-1">
                            <SearchableSelect
                                v-model="form.purchase_order_id"
                                :options="filteredPurchaseOrders.map(po => ({ id: po.id, po_no: po.po_no, remaining_label: `Sisa: ${Number(po.remaining_qty || 0).toLocaleString()}` }))"
                                value-key="id"
                                label-key="po_no"
                                sublabel-key="remaining_label"
                                :placeholder="t('incoming.choose')"
                                :disabled="!form.supplier_id"
                                :aria-label="t('incoming.purchaseOrder')"
                                :error="form.errors.purchase_order_id"
                                @change="onPurchaseOrderChange"
                            />
                        </div>
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
                        <div class="mt-1">
                            <SearchableSelect
                                v-model="form.supplier_id"
                                :options="suppliers.filter(s => s.source === 'import')"
                                value-key="id"
                                label-key="supplier_name"
                                sublabel-key="supplier_code"
                                :placeholder="t('incoming.choose')"
                                :aria-label="t('incoming.supplier')"
                                :error="form.errors.supplier_id"
                                @change="onSupplierChange"
                            />
                        </div>
                        <InputError :message="form.errors.supplier_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.purchaseOrder') }}</label>
                        <div class="mt-1">
                            <SearchableSelect
                                v-model="form.purchase_order_id"
                                :options="filteredPurchaseOrders.map(po => ({ id: po.id, po_no: po.po_no, remaining_label: `Sisa: ${Number(po.remaining_qty || 0).toLocaleString()}` }))"
                                value-key="id"
                                label-key="po_no"
                                sublabel-key="remaining_label"
                                :placeholder="t('incoming.choose')"
                                :disabled="!form.supplier_id"
                                :aria-label="t('incoming.purchaseOrder')"
                                :error="form.errors.purchase_order_id"
                                @change="onPurchaseOrderChange"
                            />
                        </div>
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
                        <div class="mt-1">
                            <SearchableSelect
                                v-model="form.trucking_company_id"
                                :options="truckings"
                                value-key="id"
                                label-key="company_name"
                                sublabel-key="company_code"
                                :placeholder="t('incoming.choose')"
                                :aria-label="t('incoming.trucking')"
                            />
                        </div>
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

                <!-- Container & Segel — khusus kedatangan impor -->
                <div class="mt-5 border-t border-borderline pt-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-semibold text-ink-primary">{{ t('incoming.containers') }}</h3>
                            <p class="text-xs text-ink-secondary">{{ t('incoming.containersHelp') }}</p>
                        </div>
                        <button type="button" @click="addContainer" class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-sm font-medium text-primary transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            {{ t('incoming.addContainer') }}
                        </button>
                    </div>
                    <InputError :message="form.errors.containers" class="mb-2" />

                    <div v-for="(c, ci) in containerRows" :key="ci" class="grid gap-3 py-2 sm:grid-cols-12">
                        <div class="sm:col-span-5">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.containerNo') }}</label>
                            <input
                                :aria-label="t('incoming.containerNo')"
                                v-model="c.container_no"
                                type="text"
                                placeholder="MSCU1234567"
                                class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-mono uppercase text-ink-primary focus:border-primary focus:ring-primary"
                            />
                            <InputError :message="(form.errors as any)[`containers.${ci}.container_no`]" class="mt-1" />
                        </div>
                        <div class="sm:col-span-4">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.sealCode') }}</label>
                            <input
                                :aria-label="t('incoming.sealCode')"
                                v-model="c.seal_code"
                                type="text"
                                placeholder="SEAL-001"
                                class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-mono uppercase text-ink-primary focus:border-primary focus:ring-primary"
                            />
                            <InputError :message="(form.errors as any)[`containers.${ci}.seal_code`]" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.size') }}</label>
                            <select :aria-label="t('incoming.size')" v-model="c.size" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                                <option value="">—</option>
                                <option v-for="s in containerSizes" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div class="flex items-end sm:col-span-1">
                            <button
                                type="button"
                                @click="removeContainer(ci)"
                                :disabled="containerRows.length <= 1"
                                class="rounded-lg border border-borderline px-3 py-2 text-sm text-danger transition hover:bg-danger/10 disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                :aria-label="t('incoming.delete')"
                            >✕</button>
                        </div>
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

                <div v-if="rowErrorList.length" class="mb-4 space-y-1 rounded-lg bg-danger/10 p-3 text-xs text-danger">
                    <div v-for="err in rowErrorList" :key="err.row">
                        Baris #{{ err.row }}: {{ err.msg }}
                    </div>
                </div>

                <div class="space-y-4">
                    <div
                        v-for="(r, i) in itemRows"
                        :key="i"
                        class="space-y-3 rounded-xl border border-borderline bg-background/40 p-4 transition hover:border-borderline/80"
                    >
                        <!-- Header Baris Material & Aksi -->
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-borderline/60 pb-2.5">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                    {{ i + 1 }}
                                </span>
                                <div v-if="r.part_id" class="flex flex-wrap items-baseline gap-2">
                                    <span class="font-mono text-sm font-bold text-ink-primary">{{ r.part_number || r.material_group }}</span>
                                    <span v-if="r.part_name" class="text-xs text-ink-secondary">· {{ r.part_name }}</span>
                                    <span v-if="r.po_qty_ref" class="rounded bg-primary-light px-2 py-0.5 text-[11px] font-semibold text-primary">
                                        Ref PO: {{ r.po_qty_ref }} {{ r.unit_goods }}
                                    </span>
                                </div>
                                <div v-else class="text-xs font-semibold text-ink-secondary">
                                    {{ t('incoming.chooseGroup') }}
                                </div>
                            </div>

                            <button
                                type="button"
                                @click="removeItem(i)"
                                :disabled="itemRows.length <= 1"
                                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-danger transition hover:bg-danger/10 disabled:opacity-30"
                                :title="t('incoming.delete')"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                                {{ t('incoming.delete') }}
                            </button>
                        </div>

                        <!-- Baris 1: Pilihan Part / Material, Ukuran, Qty Barang, Satuan -->
                        <div class="grid gap-3 sm:grid-cols-12">
                            <div class="sm:col-span-5">
                                <label class="text-xs font-semibold text-ink-secondary">Part / Material</label>
                                <div v-if="r.part_id && form.purchase_order_id" class="mt-1 flex items-center justify-between rounded-lg border border-borderline bg-surface px-3 py-2 text-sm text-ink-primary">
                                    <div class="truncate">
                                        <span class="font-mono font-bold">{{ r.part_number || r.material_group }}</span>
                                        <span v-if="r.part_name" class="ml-1.5 text-xs text-ink-secondary">{{ r.part_name }}</span>
                                    </div>
                                    <span class="ml-2 shrink-0 rounded bg-background px-1.5 py-0.5 text-[10px] font-semibold text-ink-secondary">PO</span>
                                </div>
                                <div v-else class="mt-1">
                                    <SearchableSelect
                                        :model-value="r.part_id"
                                        :options="supplierMaterialOptions"
                                        value-key="value"
                                        label-key="label"
                                        sublabel-key="sublabel"
                                        :placeholder="form.supplier_id ? 'Pilih part / material substitute...' : 'Pilih supplier terlebih dahulu'"
                                        :disabled="!form.supplier_id"
                                        @update:model-value="onSelectMaterial(r, $event)"
                                    />
                                </div>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.size') }}</label>
                                <input
                                    v-model="r.size"
                                    type="text"
                                    placeholder="STD / 0.5T / ..."
                                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                                />
                            </div>

                            <div class="sm:col-span-3">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.qtyGoods') }}</label>
                                    <span v-if="r.po_qty_ref" class="font-mono text-[10px] text-ink-secondary">Maks: {{ r.po_qty_ref }}</span>
                                </div>
                                <input
                                    v-model="r.qty_goods"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    @input="onQtyGoodsInput(r)"
                                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-bold text-primary focus:border-primary focus:ring-primary"
                                />
                            </div>

                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.unitGoods') }}</label>
                                <input
                                    v-model="r.unit_goods"
                                    list="uom-codes"
                                    type="text"
                                    placeholder="KGM / PCS"
                                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                                />
                            </div>
                        </div>

                        <!-- Baris 2: Kemasan (Bundle) & Berat (KGM) -->
                        <div class="grid gap-3 border-t border-dashed border-borderline/60 pt-2.5 sm:grid-cols-12">
                            <div class="sm:col-span-3">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.bundleQty') }}</label>
                                <input
                                    v-model="r.qty_bundle"
                                    type="number"
                                    step="1"
                                    min="0"
                                    placeholder="2"
                                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                                />
                                <p class="mt-1 text-[11px] text-ink-secondary">{{ t('incoming.bundleOptionalHint') }}</p>
                            </div>

                            <div class="sm:col-span-3">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.bundleUnit') }}</label>
                                <select
                                    v-model="r.unit_bundle"
                                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                                >
                                    <option value="">—</option>
                                    <option v-for="unit in packingUnits" :key="unit" :value="unit">{{ unit }}</option>
                                </select>
                            </div>

                            <div class="sm:col-span-3">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.netWeight') }} (KGM)</label>
                                <input
                                    v-model="r.weight_nett"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    placeholder="0.0000"
                                    @input="recalcPrice(r)"
                                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                                />
                            </div>

                            <div class="sm:col-span-3">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.grossWeight') }} (KGM)</label>
                                <input
                                    v-model="r.weight_gross"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    placeholder="0.0000"
                                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                                />
                                <p v-if="weightInvalid(r)" class="mt-0.5 text-[10px] text-danger">{{ t('incoming.invalidWeight') }}</p>
                            </div>
                        </div>

                        <!-- Baris 3: Informasi Opsional (Total Harga, Harga Satuan, Catatan) -->
                        <div class="flex flex-wrap items-center gap-4 border-t border-dashed border-borderline/40 pt-2 text-xs text-ink-secondary">
                            <div class="flex items-center gap-1.5">
                                <label>{{ t('incoming.totalPrice') }}:</label>
                                <input
                                    v-model="r.total_price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    @input="recalcPrice(r)"
                                    placeholder="0.00"
                                    class="h-7 w-28 rounded border-borderline bg-surface px-2 py-1 text-xs text-ink-primary"
                                />
                            </div>
                            <div class="flex items-center gap-1.5">
                                <label>{{ t('incoming.autoPrice') }}:</label>
                                <span class="font-mono font-medium text-ink-primary">{{ r.price !== '' ? Number(r.price).toLocaleString() : '—' }}</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <label>{{ t('incoming.notes') }}:</label>
                                <input
                                    v-model="r.notes"
                                    type="text"
                                    placeholder="Catatan..."
                                    class="h-7 w-48 rounded border-borderline bg-surface px-2 py-1 text-xs text-ink-primary"
                                />
                            </div>
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
