<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import type { ArrivalType, IncomingArrival, Subcontractor, Supplier, TruckingCompany } from '@/types';

const { t } = useI18n();

interface OspPartOption {
    part_id: number;
    part_number: string;
    part_name: string;
    model?: string | null;
    size: string;
    unit: string | null;
    part_type?: string | null;
}

interface ItemRow {
    id: number | null;
    part_id: number | null;
    part_number?: string;
    part_name?: string;
    model?: string | null;
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
    total_price: number | string;
    is_foc: boolean;
    notes: string;
    po_qty_ref?: number; // sisa kuantitas PO yang tersedia untuk item ini
    po_total_qty?: number;
    po_arrived_qty?: number;
    po_unit_ref?: string;
    po_unit_price?: number;
}

interface ContainerRow {
    container_no: string;
    seal_code: string;
    size: string;
}

const props = defineProps<{
    arrival: IncomingArrival | null;
    suppliers: Array<Pick<Supplier, 'id' | 'supplier_code' | 'supplier_name' | 'source'>>;
    subcontractors: Array<Pick<Subcontractor, 'id' | 'subcontractor_code' | 'subcontractor_name'>>;
    customers: Array<{ id: number; customer_code: string; customer_name: string; short_name?: string | null }>;
    truckings: Array<Pick<TruckingCompany, 'id' | 'company_code' | 'company_name'>>;
    materialOptions: Array<{
        supplier_id: number;
        group: string;
        size: string;
        part_id: number;
        part_number?: string;
        part_name?: string;
        model?: string | null;
        unit: string | null;
    }>;
    ospGciParts: OspPartOption[];
    ospCustomerParts: OspPartOption[];
    purchaseOrders: Array<any>;
    suppliersWithPos?: number[];
    uomCodes: string[];
    packingUnits: string[];
}>();

const initialArrivalType = (props.arrival?.arrival_type ?? (props.arrival?.is_local ? 'local' : 'import')) as ArrivalType;
const isEditingOsp = Boolean(props.arrival) && (initialArrivalType === 'osp_gci' || initialArrivalType === 'osp_customer');
const isEditingStandard = Boolean(props.arrival) && !isEditingOsp;

const activeTab = ref<'import' | 'local' | 'osp'>(
    initialArrivalType === 'osp_gci' || initialArrivalType === 'osp_customer' ? 'osp' : initialArrivalType,
);
const ospSubType = ref<'osp_gci' | 'osp_customer'>(
    initialArrivalType === 'osp_customer' ? 'osp_customer' : 'osp_gci',
);
const currentArrivalType = computed<ArrivalType>(() => (activeTab.value === 'osp' ? ospSubType.value : activeTab.value));
const isOspArrival = computed(() => activeTab.value === 'osp');
const isLocalArrival = computed(() => activeTab.value === 'local');
const isImportArrival = computed(() => activeTab.value === 'import');

function formatTotalPrice(value: number | string | null | undefined): string {
    if (value === '' || value === null || value === undefined) return '';
    const n = Number(value);
    if (!Number.isFinite(n)) return '';
    return n.toFixed(2);
}

const blankItem = (): ItemRow => ({
    id: null,
    part_id: null,
    part_number: '',
    part_name: '',
    model: '',
    material_group: '',
    size: '',
    qty_goods: '',
    unit_goods: isOspArrival.value ? 'PCS' : 'KGM',
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

const editPoRef = props.arrival?.purchase_order_id
    ? props.purchaseOrders.find((p: any) => Number(p.id) === Number(props.arrival?.purchase_order_id)) ?? null
    : null;

const itemRows = ref<ItemRow[]>(
    props.arrival?.items?.map((i: any) => {
        const poItem = editPoRef?.items?.find((it: any) => Number(it.part_id) === Number(i.part_id));

        return {
            id: i.id ?? null,
            part_id: i.part_id ?? null,
            part_number: i.part?.part_number ?? '',
            part_name: i.part?.part_name ?? '',
            model: i.part?.model ?? '',
            material_group: i.material_group ?? '',
            size: i.size ?? i.part?.size ?? '',
            qty_goods: i.qty_goods,
            unit_goods: i.unit_goods ?? i.part?.uom?.code ?? 'KGM',
            qty_bundle: i.qty_bundle ?? '',
            unit_bundle: i.unit_bundle ?? (props.packingUnits[0] ?? 'PALLET'),
            weight_nett: i.weight_nett ?? '',
            unit_weight: i.unit_weight ?? 'KGM',
            weight_gross: i.weight_gross ?? '',
            price: i.price ?? '',
            total_price: formatTotalPrice(i.total_price),
            is_foc: Boolean(i.is_foc),
            notes: i.notes ?? '',
            po_qty_ref: poItem ? Number(poItem.remaining_qty ?? poItem.qty ?? 0) : undefined,
            po_total_qty: poItem ? Number(poItem.qty ?? 0) : undefined,
            po_arrived_qty: poItem ? Number(poItem.arrived_qty ?? 0) : undefined,
            po_unit_ref: poItem ? String(poItem.unit ?? '') : undefined,
            po_unit_price: poItem ? Number(poItem.price ?? 0) : undefined,
        };
    }) ?? [blankItem()],
);

const form = useForm({
    is_local: isLocalArrival.value,
    arrival_type: initialArrivalType,
    invoice_no: props.arrival?.invoice_no ?? '',
    delivery_note_no: props.arrival?.delivery_note_no ?? '',
    contract_no: props.arrival?.contract_no ?? '',
    reference_no: props.arrival?.reference_no ?? '',
    invoice_date: props.arrival?.invoice_date ?? new Date().toISOString().slice(0, 10),
    supplier_id: props.arrival?.supplier_id ?? ('' as number | ''),
    subcontractor_id: props.arrival?.subcontractor_id ?? ('' as number | ''),
    customer_id: props.arrival?.customer_id ?? ('' as number | ''),
    trucking_company_id: props.arrival?.trucking_company_id ?? ('' as number | ''),
    purchase_order_id: props.arrival?.purchase_order_id ?? ('' as number | ''),
    vessel: props.arrival?.vessel ?? '',
    etd: props.arrival?.etd ?? '',
    eta: props.arrival?.eta ?? '',
    eta_gci: props.arrival?.eta_gci ?? '',
    bill_of_lading: props.arrival?.bill_of_lading ?? '',
    pen_no: props.arrival?.pen_no ?? '',
    pen_date: props.arrival?.pen_date ? String(props.arrival.pen_date).slice(0, 10) : '',
    aju_no: props.arrival?.aju_no ?? '',
    aju_date: props.arrival?.aju_date ? String(props.arrival.aju_date).slice(0, 10) : '',
    price_term: props.arrival?.price_term ?? '',
    hs_code: props.arrival?.hs_code ?? '',
    port_of_loading: props.arrival?.port_of_loading ?? '',
    country: props.arrival?.country ?? '',
    currency: props.arrival?.currency ?? (initialArrivalType === 'import' ? 'USD' : 'IDR'),
    notes: props.arrival?.notes ?? '',
    remarks: props.arrival?.remarks ?? '',
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

function clearPoMeta(row: ItemRow) {
    row.po_qty_ref = undefined;
    row.po_total_qty = undefined;
    row.po_arrived_qty = undefined;
    row.po_unit_ref = undefined;
    row.po_unit_price = undefined;
}

const selectedPo = computed(() =>
    form.purchase_order_id
        ? props.purchaseOrders.find((p) => Number(p.id) === Number(form.purchase_order_id)) ?? null
        : null,
);

const isLocalPoMode = computed(
    () => isLocalArrival.value && Boolean(selectedPo.value) && Array.isArray(selectedPo.value?.items) && selectedPo.value.items.length > 0,
);

const supplierAllPosExhausted = computed(
    () =>
        isLocalArrival.value &&
        Boolean(form.supplier_id) &&
        !form.purchase_order_id &&
        filteredPurchaseOrders.value.length === 0 &&
        (props.suppliersWithPos ?? []).includes(Number(form.supplier_id)),
);

const selectedPoExhausted = computed(
    () =>
        isLocalPoMode.value &&
        selectedPo.value.items.every((it: any) => Number(it.remaining_qty ?? 0) <= 1e-9),
);

const remainingUnpickedPoPartsCount = computed(() => {
    if (!isLocalPoMode.value || !selectedPo.value) return 0;
    const pickedIds = new Set(
        itemRows.value
            .map((row) => (row.part_id != null ? Number(row.part_id) : null))
            .filter((id): id is number => id !== null),
    );
    return selectedPo.value.items.filter(
        (it: any) => Number(it.remaining_qty ?? 0) > 1e-9 && !pickedIds.has(Number(it.part_id)),
    ).length;
});

const allPoPartsPickedOrExhausted = computed(
    () => isLocalPoMode.value && remainingUnpickedPoPartsCount.value === 0,
);

const canAddItemRow = computed(
    () => !(isLocalPoMode.value && allPoPartsPickedOrExhausted.value && itemRows.value.every((row) => Boolean(row.part_id))),
);

function poOptionsForRow(rowIndex: number) {
    if (!selectedPo.value?.items) return [];

    const currentPartId = itemRows.value[rowIndex]?.part_id;
    const otherPickedIds = new Set(
        itemRows.value
            .filter((_, idx) => idx !== rowIndex)
            .map((row) => (row.part_id != null ? Number(row.part_id) : null))
            .filter((id): id is number => id !== null),
    );

    return selectedPo.value.items
        .filter((it: any) => {
            const pId = Number(it.part_id);
            const isCurrent = currentPartId != null && pId === Number(currentPartId);
            const hasRemaining = Number(it.remaining_qty ?? 0) > 1e-9;
            return (hasRemaining || isCurrent) && !otherPickedIds.has(pId);
        })
        .map((it: any) => ({
            value: it.part_id,
            label: `${it.part?.part_number ?? ''} · ${it.part?.part_name ?? ''}`,
            sublabel: [
                `${t('master.model')}: ${it.part?.model || '—'}`,
                `${t('incoming.size')}: ${it.size || it.part?.size || 'STD'}`,
                t('incoming.poRemainingOption', {
                    remaining: Number(it.remaining_qty || 0).toLocaleString(),
                    unit: it.unit || 'PCS',
                    po: Number(it.qty || 0).toLocaleString(),
                    arrived: Number(it.arrived_qty || 0).toLocaleString(),
                }),
            ].join(' · '),
            raw: it,
        }));
}

function onSupplierChange() {
    resetMaterialRows();
    // Jika PO yang saat ini terpilih bukan milik supplier baru, reset purchase_order_id
    if (form.purchase_order_id) {
        const po = props.purchaseOrders.find((p) => Number(p.id) === Number(form.purchase_order_id));
        if (po && Number(po.supplier_id) !== Number(form.supplier_id)) {
            form.purchase_order_id = '';
        }
    }
}

// Pilih PO: Lokal mulai 1 baris kosong (part dipilih manual via dropdown + Qty kosong);
// Impor tetap memuat item bersisa secara otomatis.
function onPurchaseOrderChange() {
    const poId = Number(form.purchase_order_id);
    if (!poId) {
        itemRows.value.forEach(clearPoMeta);
        return;
    }

    const po = props.purchaseOrders.find((p) => Number(p.id) === poId);
    if (!po) return;

    // Auto-set supplier dan mata uang jika tersedia
    if (po.supplier_id) {
        form.supplier_id = po.supplier_id;
    }
    if (po.currency) {
        form.currency = po.currency;
    }

    if (isLocalArrival.value) {
        const defaultUnit = po.items?.[0]?.unit || 'PCS';
        const row = blankItem();
        row.unit_goods = defaultUnit;
        itemRows.value = [row];
        return;
    }

    if (po.items && Array.isArray(po.items) && po.items.length > 0) {
        const remainingItems = po.items.filter((it: any) => Number(it.remaining_qty ?? it.qty ?? 0) > 1e-9);
        const itemsToLoad = remainingItems.length > 0 ? remainingItems : po.items;

        itemRows.value = itemsToLoad.map((it: any) => {
            // Kelompok material diambil dari pemetaan substitute supplier untuk
            // part ini — part number BUKAN kelompok material.
            const mapped = props.materialOptions.find(
                (m) => m.supplier_id === Number(po.supplier_id) && m.part_id === Number(it.part_id),
            );
            const remQty = Number(it.remaining_qty ?? it.qty ?? 0);
            const unit = it.unit ?? it.part?.uom?.code ?? 'KGM';
            const unitPrice = Number(it.price ?? 0);

            return {
                id: null,
                part_id: it.part_id ?? null,
                part_number: it.part?.part_number ?? '',
                part_name: it.part?.part_name ?? '',
                model: it.part?.model ?? '',
                material_group: mapped?.group ?? it.material_group ?? '',
                size: it.size ?? mapped?.size ?? it.part?.size ?? 'STD',
                qty_goods: remQty > 0 ? remQty : '',
                po_qty_ref: remQty,
                po_total_qty: Number(it.qty ?? 0),
                po_arrived_qty: Number(it.arrived_qty ?? 0),
                po_unit_ref: unit,
                po_unit_price: unitPrice,
                unit_goods: unit,
                qty_bundle: '',
                unit_bundle: props.packingUnits[0] ?? 'PALLET',
                weight_nett: (unit === 'KGM' || unit === 'KG') && remQty > 0 ? remQty : '',
                unit_weight: 'KGM',
                weight_gross: '',
                price: unitPrice > 0 ? unitPrice : '',
                total_price: remQty > 0 && unitPrice > 0 ? formatTotalPrice(remQty * unitPrice) : '',
                is_foc: false,
                notes: it.notes ?? '',
            };
        });
    }
}

function selectTab(tab: 'import' | 'local' | 'osp') {
    if (isEditingOsp && tab !== 'osp') return;
    if (isEditingStandard && tab === 'osp') return;
    if (activeTab.value === tab) return;

    const wasOsp = activeTab.value === 'osp';
    activeTab.value = tab;
    form.arrival_type = currentArrivalType.value;
    form.is_local = tab === 'local';
    form.purchase_order_id = '';
    form.currency = tab === 'import' ? 'USD' : 'IDR';
    itemRows.value.forEach(clearPoMeta);

    if (tab === 'osp' && !wasOsp) {
        form.supplier_id = '';
        resetMaterialRows('PCS');
    } else if (tab !== 'osp' && wasOsp) {
        form.subcontractor_id = '';
        form.customer_id = '';
        resetMaterialRows('KGM');
    }
}

function selectOspSubType(sub: 'osp_gci' | 'osp_customer') {
    if (ospSubType.value === sub) return;
    ospSubType.value = sub;
    form.arrival_type = sub;
    form.subcontractor_id = '';
    form.customer_id = '';
    resetMaterialRows('PCS');
}

const ospActiveParts = computed(() =>
    ospSubType.value === 'osp_gci' ? props.ospGciParts : props.ospCustomerParts,
);

const ospSenderSelected = computed(() =>
    ospSubType.value === 'osp_gci' ? Boolean(form.subcontractor_id) : Boolean(form.customer_id),
);

const supplierMaterialOptions = computed(() => {
    if (isOspArrival.value) {
        return ospActiveParts.value.map((p) => ({
            value: p.part_id,
            label: `${p.part_number} · ${p.part_name}`,
            sublabel: [
                `${t('master.model')}: ${p.model || '—'}`,
                p.part_type ?? '',
                `Size: ${p.size || 'STD'}`,
                `UOM: ${p.unit ?? 'PCS'}`,
            ]
                .filter((segment) => segment !== '')
                .join(' · '),
            raw: p,
        }));
    }

    const list = props.materialOptions.filter(
        (m) => !form.supplier_id || m.supplier_id === Number(form.supplier_id),
    );
    return list.map((m) => ({
        value: m.part_id,
        label: m.part_number ? `${m.part_number} · ${m.part_name}` : m.group,
        sublabel: [m.group, `Size: ${m.size}`, `UOM: ${m.unit ?? 'KGM'}`, m.model ? `Model: ${m.model}` : '']
            .filter((segment) => segment !== '')
            .join(' · '),
        raw: m,
    }));
});

const materialPickerPlaceholder = computed(() => {
    if (isOspArrival.value) {
        if (!ospSenderSelected.value) {
            return ospSubType.value === 'osp_gci' ? t('incoming.chooseSubcontractorFirst') : t('incoming.chooseCustomerFirst');
        }
        return ospActiveParts.value.length === 0 ? t('incoming.ospNoParts') : t('incoming.chooseOspPart');
    }
    return form.supplier_id ? t('incoming.chooseMaterial') : t('incoming.chooseSupplierFirst');
});

function onSelectMaterial(r: ItemRow, partId: number | string | null | '') {
    if (!partId) {
        r.part_id = null;
        r.part_number = '';
        r.part_name = '';
        r.model = '';
        r.material_group = '';
        r.size = '';
        clearPoMeta(r);
        return;
    }

    if (isLocalPoMode.value && selectedPo.value) {
        const poItem = selectedPo.value.items.find((it: any) => Number(it.part_id) === Number(partId));
        if (poItem) {
            const mapped = props.materialOptions.find(
                (m) => m.supplier_id === Number(form.supplier_id) && m.part_id === Number(partId),
            );
            const poUnit = String(poItem.unit || mapped?.unit || 'PCS');
            const poUnitPrice = Number(poItem.price ?? 0);

            r.part_id = poItem.part_id;
            r.part_number = poItem.part?.part_number ?? '';
            r.part_name = poItem.part?.part_name ?? '';
            r.model = poItem.part?.model ?? '';
            r.material_group = mapped?.group ?? '';
            r.size = poItem.size ?? mapped?.size ?? poItem.part?.size ?? 'STD';
            r.unit_goods = poUnit;
            r.qty_goods = '';
            r.weight_nett = '';
            r.po_qty_ref = Number(poItem.remaining_qty ?? 0);
            r.po_total_qty = Number(poItem.qty ?? 0);
            r.po_arrived_qty = Number(poItem.arrived_qty ?? 0);
            r.po_unit_ref = poUnit;
            r.po_unit_price = poUnitPrice;
            r.price = poUnitPrice > 0 ? poUnitPrice : '';
            r.total_price = '';
        }
        return;
    }

    if (isOspArrival.value) {
        const ospMatch = ospActiveParts.value.find((p) => p.part_id === Number(partId));
        if (ospMatch) {
            r.part_id = ospMatch.part_id;
            r.part_number = ospMatch.part_number ?? '';
            r.part_name = ospMatch.part_name ?? '';
            r.model = ospMatch.model ?? '';
            r.material_group = '';
            r.size = ospMatch.size || 'STD';
            if (ospMatch.unit) {
                r.unit_goods = ospMatch.unit;
            }
            if (r.unit_goods === 'KGM' && r.qty_goods && !r.weight_nett) {
                r.weight_nett = r.qty_goods;
            }
            recalcPrice(r);
        }
        return;
    }

    const match = props.materialOptions.find(
        (m) => m.part_id === Number(partId) && (!form.supplier_id || m.supplier_id === Number(form.supplier_id)),
    ) ?? props.materialOptions.find((m) => m.part_id === Number(partId));

    if (match) {
        r.part_id = match.part_id;
        r.part_number = match.part_number ?? '';
        r.part_name = match.part_name ?? '';
        r.model = match.model ?? '';
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

function rowBasisQty(r: ItemRow): number {
    const unit = (r.po_unit_ref || r.unit_goods || '').toUpperCase();
    if (unit === 'KGM' || unit === 'KG') {
        return Number(r.weight_nett) || Number(r.qty_goods) || 0;
    }
    return Number(r.qty_goods) || 0;
}

function rowExceedsPoRemaining(r: ItemRow): boolean {
    if (r.po_qty_ref === undefined || !r.part_id) return false;
    return rowBasisQty(r) > r.po_qty_ref + 1e-9;
}

function syncPoPriceFromQty(r: ItemRow) {
    if (r.po_unit_price && r.po_unit_price > 0 && !r.is_foc) {
        const basis = rowBasisQty(r);
        r.price = r.po_unit_price;
        r.total_price = basis > 0 ? formatTotalPrice(basis * r.po_unit_price) : '';
        return;
    }
    recalcPrice(r);
}

function onQtyGoodsInput(r: ItemRow) {
    if (r.unit_goods === 'KGM') {
        r.weight_nett = r.qty_goods;
    }
    syncPoPriceFromQty(r);
}

function onWeightNettInput(r: ItemRow) {
    syncPoPriceFromQty(r);
}

function resetMaterialRows(defaultUnit?: string) {
    itemRows.value.forEach((row) => {
        row.material_group = '';
        row.size = '';
        row.part_id = null;
        row.part_number = '';
        row.part_name = '';
        row.model = '';
        clearPoMeta(row);
        if (defaultUnit) {
            row.unit_goods = defaultUnit;
        }
    });
}

function toCents(value: number | string): number {
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

function normalizeTotalPrice(row: ItemRow) {
    row.total_price = formatTotalPrice(row.total_price);
    recalcPrice(row);
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
    form.arrival_type = currentArrivalType.value;
    form.is_local = isLocalArrival.value;
    if (isOspArrival.value) {
        form.supplier_id = '';
        form.purchase_order_id = '';
        if (ospSubType.value === 'osp_gci') {
            form.customer_id = '';
        } else {
            form.subcontractor_id = '';
        }
    } else {
        form.subcontractor_id = '';
        form.customer_id = '';
    }

    form.items = itemRows.value
        .filter((r) => !isRowEmpty(r))
        .map((r) => ({
            id: r.id,
            part_id: r.part_id,
            material_group: isOspArrival.value ? null : (r.material_group || (r.part_number ? r.part_number : null)),
            size: r.size || 'STD',
            qty_goods: r.qty_goods === '' ? 0 : Number(r.qty_goods),
            unit_goods: r.unit_goods || (isOspArrival.value ? 'PCS' : 'KGM'),
            qty_bundle: r.qty_bundle === '' ? null : Number(r.qty_bundle),
            unit_bundle: r.unit_bundle || null,
            weight_nett: r.weight_nett === '' ? (r.unit_goods === 'KGM' ? Number(r.qty_goods) : null) : Number(r.weight_nett),
            unit_weight: 'KGM',
            weight_gross: r.weight_gross === '' ? null : Number(r.weight_gross),
            price: r.price === '' ? null : Number(r.price),
            total_price: r.total_price === '' ? null : Math.round(Number(r.total_price) * 100) / 100,
            is_foc: r.is_foc,
            notes: r.notes,
        })) as any;

    // Container hanya untuk kedatangan impor; baris tanpa nomor kontainer dibuang.
    form.containers = !isImportArrival.value
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

            <!-- Switcher Tab: Impor vs Lokal vs OSP -->
            <div class="inline-flex rounded-xl border border-borderline bg-surface p-1 shadow-sm">
                <button
                    type="button"
                    :disabled="isEditingOsp"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-40 disabled:cursor-not-allowed"
                    :class="isImportArrival ? 'bg-primary text-white' : 'text-ink-secondary hover:bg-primary-light'"
                    @click="selectTab('import')"
                >
                    {{ t('incoming.tabImportArrival') }}
                </button>
                <button
                    type="button"
                    :disabled="isEditingOsp"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-40 disabled:cursor-not-allowed"
                    :class="isLocalArrival ? 'bg-primary text-white' : 'text-ink-secondary hover:bg-primary-light'"
                    @click="selectTab('local')"
                >
                    {{ t('incoming.tabLocalArrival') }}
                </button>
                <button
                    type="button"
                    :disabled="isEditingStandard"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-40 disabled:cursor-not-allowed"
                    :class="isOspArrival ? 'bg-primary text-white' : 'text-ink-secondary hover:bg-primary-light'"
                    @click="selectTab('osp')"
                >
                    {{ t('incoming.tabOspArrival') }}
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
                    <h2 class="text-sm font-bold text-ink-primary">{{ t('incoming.localArrivalTitle') }}</h2>
                    <p class="text-xs text-ink-secondary">{{ t('incoming.localArrivalHint') }}</p>
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
                        <p
                            v-if="supplierAllPosExhausted"
                            role="status"
                            class="mt-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs font-medium text-warning-ink"
                        >
                            {{ t('incoming.supplierPosExhaustedWarning') }}
                        </p>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.deliveryNoteNo') }}</label>
                        <input
                            :aria-label="t('incoming.deliveryNoteNo')"
                            v-model="form.delivery_note_no"
                            type="text"
                            :placeholder="t('incoming.deliveryNotePlaceholder')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.delivery_note_no" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.invoiceNo') }}</label>
                        <input
                            :aria-label="t('incoming.invoiceNo')"
                            v-model="form.invoice_no"
                            type="text"
                            :placeholder="t('incoming.invoiceNoPlaceholder')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.invoice_no" class="mt-1" />
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

                    <div class="lg:col-span-3">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.notes') }}</label>
                        <input
                            :aria-label="t('incoming.notes')"
                            v-model="form.notes"
                            type="text"
                            :placeholder="t('incoming.arrivalNotesPlaceholder')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                    </div>
                </div>
            </div>

            <!-- FORM KEDATANGAN OSP (OSP GCI & OSP CUSTOMER) -->
            <div v-else-if="isOspArrival" class="rounded-xl border border-borderline bg-surface p-5 space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-borderline pb-3">
                    <div>
                        <h2 class="text-sm font-bold text-ink-primary">{{ t('incoming.ospArrivalTitle') }}</h2>
                        <p class="text-xs text-ink-secondary">{{ ospSubType === 'osp_gci' ? t('incoming.ospGciHint') : t('incoming.ospCustomerHint') }}</p>
                    </div>
                    <div class="inline-flex rounded-lg border border-borderline bg-background p-1">
                        <button
                            type="button"
                            class="rounded-md px-3 py-1.5 text-xs font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            :class="ospSubType === 'osp_gci' ? 'bg-warning text-white' : 'text-ink-secondary hover:bg-primary-light'"
                            @click="selectOspSubType('osp_gci')"
                        >
                            {{ t('incoming.ospGci') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md px-3 py-1.5 text-xs font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            :class="ospSubType === 'osp_customer' ? 'bg-success text-white' : 'text-ink-secondary hover:bg-primary-light'"
                            @click="selectOspSubType('osp_customer')"
                        >
                            {{ t('incoming.ospCustomer') }}
                        </button>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-if="ospSubType === 'osp_gci'">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.subcontractor') }} <span class="text-danger">*</span></label>
                        <div class="mt-1">
                            <SearchableSelect
                                v-model="form.subcontractor_id"
                                :options="subcontractors"
                                value-key="id"
                                label-key="subcontractor_name"
                                sublabel-key="subcontractor_code"
                                :placeholder="t('incoming.choose')"
                                :aria-label="t('incoming.subcontractor')"
                                :error="form.errors.subcontractor_id"
                            />
                        </div>
                        <InputError :message="form.errors.subcontractor_id" class="mt-1" />
                    </div>

                    <div v-else>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.customer') }} <span class="text-danger">*</span></label>
                        <div class="mt-1">
                            <SearchableSelect
                                v-model="form.customer_id"
                                :options="customers"
                                value-key="id"
                                label-key="customer_name"
                                sublabel-key="customer_code"
                                :placeholder="t('incoming.choose')"
                                :aria-label="t('incoming.customer')"
                                :error="form.errors.customer_id"
                            />
                        </div>
                        <InputError :message="form.errors.customer_id" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.contractNo') }}</label>
                        <input
                            :aria-label="t('incoming.contractNo')"
                            v-model="form.contract_no"
                            type="text"
                            :placeholder="t('incoming.contractNoPlaceholder')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.contract_no" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.deliveryNoteNo') }}</label>
                        <input
                            :aria-label="t('incoming.deliveryNoteNo')"
                            v-model="form.delivery_note_no"
                            type="text"
                            :placeholder="t('incoming.deliveryNotePlaceholder')"
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
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.invoiceNo') }}</label>
                        <input
                            :aria-label="t('incoming.invoiceNo')"
                            v-model="form.invoice_no"
                            type="text"
                            :placeholder="t('incoming.invoiceNoPlaceholder')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.invoice_no" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.referenceNo') }}</label>
                        <input
                            :aria-label="t('incoming.referenceNo')"
                            v-model="form.reference_no"
                            type="text"
                            :placeholder="t('incoming.referenceNoPlaceholder')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.reference_no" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.penNo') }}</label>
                        <input
                            :aria-label="t('incoming.penNo')"
                            v-model="form.pen_no"
                            type="text"
                            :placeholder="t('incoming.penNoPlaceholder')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.pen_no" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.penDate') }}</label>
                        <input
                            :aria-label="t('incoming.penDate')"
                            v-model="form.pen_date"
                            type="date"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.pen_date" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.ajuNo') }}</label>
                        <input
                            :aria-label="t('incoming.ajuNo')"
                            v-model="form.aju_no"
                            type="text"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.aju_no" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.ajuDate') }}</label>
                        <input
                            :aria-label="t('incoming.ajuDate')"
                            v-model="form.aju_date"
                            type="date"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="(form.errors as any).aju_date" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.currency') }}</label>
                        <select :aria-label="t('incoming.currency')" v-model="form.currency" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="IDR">IDR</option>
                            <option value="USD">USD</option>
                            <option value="KRW">KRW</option>
                        </select>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.notes') }}</label>
                        <input
                            :aria-label="t('incoming.notes')"
                            v-model="form.notes"
                            type="text"
                            :placeholder="t('incoming.arrivalNotesPlaceholder')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                    </div>
                </div>
            </div>

            <!-- FORM KEDATANGAN IMPOR (LENGKAP KONT/VESSEL) -->
            <div v-else class="rounded-xl border border-borderline bg-surface p-5 space-y-4">
                <div class="border-b border-borderline pb-3">
                    <h2 class="text-sm font-bold text-ink-primary">{{ t('incoming.importArrivalTitle') }}</h2>
                    <p class="text-xs text-ink-secondary">{{ t('incoming.importArrivalHint') }}</p>
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
                        <InputError :message="form.errors.purchase_order_id" class="mt-1" />
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
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.portLoading') }}</label>
                        <input
                            :aria-label="t('incoming.portLoading')"
                            v-model="form.port_of_loading"
                            type="text"
                            :placeholder="t('incoming.portPlaceholder')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.port_of_loading" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.currency') }}</label>
                        <select :aria-label="t('incoming.currency')" v-model="form.currency" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="USD">USD</option>
                            <option value="IDR">IDR</option>
                            <option value="KRW">KRW</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.priceTerm') }}</label>
                        <input
                            :aria-label="t('incoming.priceTerm')"
                            v-model="form.price_term"
                            type="text"
                            list="arrival-price-terms"
                            maxlength="50"
                            placeholder="FOB"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm uppercase text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <datalist id="arrival-price-terms">
                            <option value="FOB"></option>
                            <option value="CIF"></option>
                            <option value="CNF"></option>
                            <option value="CFR"></option>
                            <option value="EXW"></option>
                            <option value="FCA"></option>
                            <option value="DAP"></option>
                            <option value="DDP"></option>
                        </datalist>
                        <InputError :message="form.errors.price_term" class="mt-1" />
                    </div>

                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.remarks') }}</label>
                        <textarea
                            :aria-label="t('incoming.remarks')"
                            v-model="form.remarks"
                            rows="2"
                            :placeholder="t('incoming.remarksPlaceholder')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        ></textarea>
                        <InputError :message="form.errors.remarks" class="mt-1" />
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
                        <p class="text-xs text-ink-secondary">{{ t('incoming.itemsHint') }}</p>
                    </div>
                    <button
                        type="button"
                        :disabled="!canAddItemRow"
                        @click="addItem"
                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-primary px-3 py-1.5 text-sm font-medium text-primary transition hover:bg-primary-light active:scale-[0.98] motion-reduce:active:scale-100 disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        <svg aria-hidden="true" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        {{ t('incoming.addRow') }}
                    </button>
                </div>
                <InputError :message="form.errors.items" class="mb-3" />

                <div
                    v-if="selectedPoExhausted"
                    role="alert"
                    class="mb-4 rounded-lg border border-warning/40 bg-warning/10 px-3.5 py-2.5 text-xs font-semibold text-warning-ink"
                >
                    {{ t('incoming.poExhaustedWarning') }}
                </div>
                <div
                    v-else-if="isLocalPoMode && allPoPartsPickedOrExhausted && itemRows.some((row) => Boolean(row.part_id))"
                    role="status"
                    class="mb-4 rounded-lg border border-warning/40 bg-warning/10 px-3.5 py-2.5 text-xs font-medium text-warning-ink"
                >
                    {{ t('incoming.allPoPartsSelectedHint') }}
                </div>

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
                                    <span v-if="r.model" class="rounded bg-background px-1.5 py-0.5 text-[11px] font-medium text-ink-secondary">
                                        {{ t('master.model') }}: {{ r.model }}
                                    </span>
                                    <span v-if="r.po_qty_ref !== undefined" class="rounded bg-primary-light px-2 py-0.5 text-[11px] font-semibold text-primary">
                                        {{
                                            t('incoming.poRemainingBadge', {
                                                remaining: Number(r.po_qty_ref).toLocaleString(),
                                                unit: r.po_unit_ref || r.unit_goods,
                                                po: Number(r.po_total_qty ?? r.po_qty_ref).toLocaleString(),
                                                arrived: Number(r.po_arrived_qty ?? 0).toLocaleString(),
                                            })
                                        }}
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
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.partMaterial') }}</label>
                                <div v-if="isImportArrival && r.part_id && form.purchase_order_id" class="mt-1 flex items-center justify-between rounded-lg border border-borderline bg-surface px-3 py-2 text-sm text-ink-primary">
                                    <div class="truncate">
                                        <span class="font-mono font-bold">{{ r.part_number || r.material_group }}</span>
                                        <span v-if="r.part_name" class="ml-1.5 text-xs text-ink-secondary">{{ r.part_name }}</span>
                                    </div>
                                    <span class="ml-2 shrink-0 rounded bg-background px-1.5 py-0.5 text-[10px] font-semibold text-ink-secondary">PO</span>
                                </div>
                                <div v-else class="mt-1">
                                    <SearchableSelect
                                        :model-value="r.part_id"
                                        :options="isLocalPoMode ? poOptionsForRow(i) : supplierMaterialOptions"
                                        value-key="value"
                                        label-key="label"
                                        sublabel-key="sublabel"
                                        :placeholder="isLocalPoMode ? t('incoming.choosePoPart') : materialPickerPlaceholder"
                                        :disabled="isOspArrival ? (!ospSenderSelected || ospActiveParts.length === 0) : !form.supplier_id"
                                        @update:model-value="onSelectMaterial(r, $event)"
                                    />
                                </div>
                                <InputError :message="(form.errors as any)[`items.${i}.part_id`]" class="mt-1" />
                            </div>

                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.size') }}</label>
                                <input
                                    v-model="r.size"
                                    :aria-label="t('incoming.size')"
                                    type="text"
                                    placeholder="STD / 0.5T / ..."
                                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                                />
                            </div>

                            <div class="sm:col-span-3">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.qtyGoods') }}</label>
                                    <span v-if="r.po_qty_ref !== undefined" class="font-mono text-[10px] text-ink-secondary">
                                        Maks: {{ Number(r.po_qty_ref).toLocaleString() }} {{ r.po_unit_ref || r.unit_goods }}
                                    </span>
                                </div>
                                <input
                                    v-model="r.qty_goods"
                                    :aria-label="t('incoming.qtyGoods')"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    @input="onQtyGoodsInput(r)"
                                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-bold text-primary focus:border-primary focus:ring-primary"
                                />
                                <p v-if="rowExceedsPoRemaining(r)" class="mt-1 text-[11px] font-medium text-danger">
                                    {{ t('incoming.exceedsPoRemaining', { remaining: Number(r.po_qty_ref).toLocaleString(), unit: r.po_unit_ref || r.unit_goods }) }}
                                </p>
                                <InputError :message="(form.errors as any)[`items.${i}.qty_goods`]" class="mt-1" />
                            </div>

                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.unitGoods') }}</label>
                                <input
                                    v-model="r.unit_goods"
                                    :aria-label="t('incoming.unitGoods')"
                                    :list="isLocalPoMode && r.part_id ? undefined : 'uom-codes'"
                                    :readonly="isLocalPoMode && Boolean(r.part_id)"
                                    type="text"
                                    placeholder="KGM / PCS"
                                    class="mt-1 w-full rounded-lg border-borderline px-3 py-2 text-sm focus:border-primary focus:ring-primary"
                                    :class="isLocalPoMode && r.part_id ? 'cursor-not-allowed bg-background font-medium text-ink-secondary' : 'bg-surface text-ink-primary'"
                                />
                                <InputError :message="(form.errors as any)[`items.${i}.unit_goods`]" class="mt-1" />
                            </div>
                        </div>

                        <!-- Baris 2: Kemasan (Bundle) & Berat (KGM) -->
                        <div class="grid gap-3 border-t border-dashed border-borderline/60 pt-2.5 sm:grid-cols-12">
                            <div class="sm:col-span-3">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.bundleQty') }}</label>
                                <input
                                    v-model="r.qty_bundle"
                                    :aria-label="t('incoming.bundleQty')"
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
                                    :aria-label="t('incoming.bundleUnit')"
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
                                    :aria-label="t('incoming.netWeight')"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    placeholder="0.0000"
                                    @input="onWeightNettInput(r)"
                                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                                />
                            </div>

                            <div class="sm:col-span-3">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.grossWeight') }} (KGM)</label>
                                <input
                                    v-model="r.weight_gross"
                                    :aria-label="t('incoming.grossWeight')"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    placeholder="0.0000"
                                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                                />
                                <p v-if="weightInvalid(r)" class="mt-0.5 text-[10px] text-danger">{{ t('incoming.invalidWeight') }}</p>
                            </div>
                        </div>

                        <!-- Baris 3: Informasi Opsional (Total Harga, Harga Satuan, FOC, Catatan) -->
                        <div class="flex flex-wrap items-center gap-4 border-t border-dashed border-borderline/40 pt-2 text-xs text-ink-secondary">
                            <div class="flex items-center gap-1.5">
                                <label>{{ t('incoming.totalPrice') }}:</label>
                                <input
                                    v-model="r.total_price"
                                    :aria-label="t('incoming.totalPrice')"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    @input="recalcPrice(r)"
                                    @blur="normalizeTotalPrice(r)"
                                    placeholder="0.00"
                                    class="h-7 w-28 rounded border-borderline bg-surface px-2 py-1 text-xs tabular-nums text-ink-primary"
                                />
                            </div>
                            <div class="flex items-center gap-1.5">
                                <label>{{ t('incoming.autoPrice') }}:</label>
                                <span class="font-mono font-medium text-ink-primary">{{ r.price !== '' ? Number(r.price).toLocaleString() : '—' }}</span>
                            </div>
                            <label class="inline-flex cursor-pointer items-center gap-1.5 font-medium text-ink-primary">
                                <input
                                    v-model="r.is_foc"
                                    type="checkbox"
                                    @change="if (r.is_foc) { r.total_price = ''; r.price = ''; }"
                                    class="rounded border-borderline text-primary focus:ring-primary"
                                />
                                <span>{{ t('incoming.foc') }}</span>
                            </label>
                            <div class="flex items-center gap-1.5">
                                <label>{{ t('incoming.notes') }}:</label>
                                <input
                                    v-model="r.notes"
                                    :aria-label="t('incoming.notes')"
                                    type="text"
                                    :placeholder="t('incoming.notesPlaceholder')"
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
