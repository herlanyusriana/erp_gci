<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, reactive, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import MultiTagInput from '@/Components/MultiTagInput.vue';
import { arrivalSenderName, arrivalTypeBadgeClass, arrivalTypeLabel, isOspType, resolveArrivalType } from '@/utils/arrivalType';
import type { IncomingArrivalItem } from '@/types';

const { t } = useI18n();

const MAX_BUNDLES = 50;
const MAX_PACKINGS = 999;
const MAX_TOTAL_ROWS = 1000;
const packingCapNotice = ref<number | null>(null);

interface PackingSubRow {
    qty: number | '';
    uom: string;
    net_weight: number | '';
}

interface BundleGroup {
    tag: string;
    total_qty: number | '';
    packings: PackingSubRow[];
}

const props = defineProps<{
    arrivalItem: IncomingArrivalItem;
    remainingQty: number;
    remainingGoodsQty: number;
    remainingNetWeight: number;
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
    defaultLocation?: string | null;
    section1Prefill?: {
        prefilled: boolean;
        receive_date: string | null;
        truck_no: string;
        driver_name: string;
        location_code: string | null;
        pen_no: string;
        pen_date: string;
        aju_no: string;
        aju_date: string;
    };
}>();

const backHref = () => route('receive.show', props.arrivalItem.arrival_id);

const defaultUom = () =>
    (props.arrivalItem.unit_goods ?? (props.weightBasis ? props.weightUnit : 'PCS')).toUpperCase();

const isWeightUnit = (code: string) => ['KGM', 'KG'].includes((code || '').toUpperCase());

// Pilihan Tipe Bundle & Packing dari master katalog
const bundleTypeOptions = computed(() =>
    props.packingUnits?.length ? props.packingUnits : ['PALLET', 'BUNDLE', 'SKID', 'BOX'],
);

const packingTypeOptions = computed(() =>
    props.packingTypes?.length ? props.packingTypes : ['COIL', 'SHEET', 'BOX', 'DRUM', 'PALLET'],
);

const initialBundleUnit = () => {
    const fromArrival = (props.arrivalItem.unit_bundle ?? '').toUpperCase();
    return bundleTypeOptions.value.includes(fromArrival) ? fromArrival : (bundleTypeOptions.value[0] ?? 'BUNDLE');
};

const initialBundleCount = () => {
    const count = Math.floor(Number(props.arrivalItem.qty_bundle) || 0);
    return count > 0 ? Math.min(count, MAX_BUNDLES) : 4;
};

// Section 2 State: jumlah bundle, tipe bundle, dan jenis kemasan (berlaku otomatis ke seluruh bundle/packing).
const bundleConfig = reactive({
    bundle_count: initialBundleCount() as number | '',
    bundle_unit: initialBundleUnit(),
    packing_type: packingTypeOptions.value.includes('COIL') ? 'COIL' : (packingTypeOptions.value[0] ?? 'BOX'),
});

function createIdempotencyKey(): string {
    return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `recv-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

// Section 1 Form State
const truckInputRef = ref<InstanceType<typeof MultiTagInput> | null>(null);
const driverInputRef = ref<InstanceType<typeof MultiTagInput> | null>(null);

const form = useForm({
    receive_date: props.section1Prefill?.receive_date || new Date().toISOString().slice(0, 10),
    truck_no: props.section1Prefill?.truck_no ?? '',
    driver_name: props.section1Prefill?.driver_name ?? '',
    location_code: props.section1Prefill?.location_code ?? props.defaultLocation ?? 'BULK-AREA',
    pen_no: props.section1Prefill?.pen_no || (props.arrivalItem.arrival?.pen_no ?? ''),
    pen_date: props.section1Prefill?.pen_date || (props.arrivalItem.arrival?.pen_date ? String(props.arrivalItem.arrival.pen_date).slice(0, 10) : ''),
    aju_no: props.section1Prefill?.aju_no || (props.arrivalItem.arrival?.aju_no ?? ''),
    aju_date: props.section1Prefill?.aju_date || (props.arrivalItem.arrival?.aju_date ? String(props.arrivalItem.arrival.aju_date).slice(0, 10) : ''),
    idempotency_key: createIdempotencyKey(),
    tags: [] as any[],
});

const submitting = ref(false);

// Bundles reactive list
const bundles = ref<BundleGroup[]>([]);

function createPackingRow(): PackingSubRow {
    return {
        qty: '',
        uom: defaultUom(),
        net_weight: '',
    };
}

/**
 * Batas jumlah packing untuk satu bundle.
 *
 * Dua batas berlaku sekaligus: 999 packing per bundle (batas tampilan) dan
 * 1000 baris total agar tabel tetap ringan. Karena tiap bundle punya jumlah
 * sendiri, batas per bundle dihitung dari sisa jatah baris yang belum dipakai
 * bundle lain.
 */
function bundleRowCap(bundleIndex: number): number {
    const otherRows = bundles.value.reduce(
        (sum, b, i) => (i === bundleIndex ? sum : sum + b.packings.length),
        0,
    );

    return Math.max(1, Math.min(MAX_PACKINGS, MAX_TOTAL_ROWS - otherRows));
}

function distributeBundleQty(bundle: BundleGroup) {
    const total = Number(bundle.total_qty);
    if (!Number.isFinite(total) || total <= 0) {
        bundle.packings.forEach((p) => {
            p.qty = '';
            p.net_weight = '';
        });
        return;
    }

    const qtyValues = splitEvenly(round4(total), bundle.packings.length);
    bundle.packings.forEach((p, idx) => {
        const q = qtyValues[idx] ?? 0;
        p.qty = q;
        p.net_weight = calcPackingNetWeight(q, p.uom);
    });
}

function onBundleTotalQty(bundle: BundleGroup) {
    distributeBundleQty(bundle);
}

function setBundlePackingCount(bundle: BundleGroup, bundleIndex: number, value: number) {
    const target = Math.min(bundleRowCap(bundleIndex), Math.max(1, Math.floor(value) || 1));

    while (bundle.packings.length < target) {
        bundle.packings.push(createPackingRow());
    }
    bundle.packings.length = target;

    if ((Number(bundle.total_qty) || 0) > 0) {
        distributeBundleQty(bundle);
    }
}

function onBundlePackingCount(bundle: BundleGroup, bundleIndex: number, event: Event) {
    const raw = (event.target as HTMLInputElement).value;
    if (raw === '') {
        return;
    }

    const value = Number(raw);
    const cap = bundleRowCap(bundleIndex);
    packingCapNotice.value = Number.isFinite(value) && value > cap ? cap : null;
    setBundlePackingCount(bundle, bundleIndex, Number.isFinite(value) && value > 0 ? value : 1);

    // Tulis balik nilai yang benar-benar dipakai supaya input tidak menampilkan
    // angka yang berbeda dari jumlah baris di tabel.
    (event.target as HTMLInputElement).value = String(bundle.packings.length);
}

function onBundlePackingCountBlur(bundle: BundleGroup, event: Event) {
    (event.target as HTMLInputElement).value = String(bundle.packings.length);
}

function createBundle(index: number): BundleGroup {
    return {
        tag: String(index),
        total_qty: '',
        packings: [createPackingRow()],
    };
}

function resizeBundles() {
    const bundleCount = Math.min(MAX_BUNDLES, Math.max(1, Math.floor(Number(bundleConfig.bundle_count) || 1)));
    bundleConfig.bundle_count = bundleCount;

    const current = bundles.value;
    while (current.length < bundleCount) {
        const bundle = createBundle(current.length + 1);
        setBundlePackingCount(bundle, current.length, bundle.packings.length);
        current.push(bundle);
    }
    current.length = bundleCount;
}

watch(() => bundleConfig.bundle_count, resizeBundles);

const round4 = (n: number) => Math.round(n * 10000) / 10000;

/**
 * Berat bersih per 1 satuan barang untuk Part No ini:
 * (Total Net Weight per Part No / Total Qty Barang)
 */
const netWeightPerUnit = computed(() => {
    const totalNet = Number(props.arrivalItem.weight_nett) || Number(props.remainingNetWeight) || 0;
    const totalQty = Number(props.arrivalItem.qty_goods) || Number(props.remainingGoodsQty) || 0;
    if (totalNet > 0 && totalQty > 0) {
        return totalNet / totalQty;
    }
    return 0;
});

function calcPackingNetWeight(qty: number, uom: string): number | '' {
    if (qty <= 0) {
        return '';
    }
    if (netWeightPerUnit.value > 0) {
        return round4(netWeightPerUnit.value * qty);
    }
    if (isWeightUnit(uom)) {
        return round4(qty);
    }
    return '';
}

// Otomasi Qty/packing -> Net weight (kg) = ((Total Net Weight / Total Qty Barang) × Qty/packing)
function onQtyChange(bundle: BundleGroup, r: PackingSubRow) {
    const qty = Number(r.qty) || 0;
    r.net_weight = calcPackingNetWeight(qty, r.uom);
    const sum = round4(bundle.packings.reduce((acc, p) => acc + (Number(p.qty) || 0), 0));
    bundle.total_qty = sum > 0 ? sum : '';
}

/** Baris packing yang benar-benar berisi; baris kosong tidak dikirim ke server. */
const isPackingFilled = (packing: PackingSubRow) =>
    (Number(packing.qty) || 0) > 0 || (Number(packing.net_weight) || 0) > 0;

/**
 * Nomor packing yang akan tersimpan untuk baris ke-`rowIndex`.
 *
 * Baris kosong dilewati saat menyimpan, jadi nomor di layar mengikuti urutan
 * baris yang terisi — bukan indeks baris — supaya nomor yang ditulis operator
 * di packing fisik sama dengan nomor yang tersimpan.
 */
function packingSequence(bundle: BundleGroup, rowIndex: number): number {
    let sequence = 0;

    for (let i = 0; i <= rowIndex; i++) {
        if (isPackingFilled(bundle.packings[i])) {
            sequence++;
        }
    }

    return sequence;
}

/**
 * Sisa yang dibagi ke seluruh baris packing: jumlah bundle × jumlah packing.
 *
 * Qty dan net weight adalah dua besaran berbeda di dokumen kedatangan: qty
 * memakai satuan barang (mis. COIL), net weight selalu KGM. Dulu hanya qty
 * yang dibagi, lalu net weight disamakan dengan qty sehingga 825 COIL menjadi
 * 825 KGM. Sekarang qty dibagi dari sisa qty barang dan — khusus COIL — net
 * weight dibagi dari sisa berat kedatangan. Net weight dibatasi
 * `maxReceivable` (sisa berat setelah limit PO) dan qty diskalakan dengan
 * rasio yang sama supaya perbandingan qty:berat tetap seperti dokumen
 * kedatangan.
 */
const distribution = computed(() => {
    const rows = bundles.value.reduce((sum, b) => sum + b.packings.length, 0);
    const acceptedNet = Math.max(0, Number(props.maxReceivable) || 0);
    const remainingNet = Math.max(0, Number(props.remainingNetWeight) || 0);
    // Limit PO dihitung dalam berat, jadi qty barang ikut diskalakan dengan
    // rasio berat yang boleh diterima — berapa pun tipe packingnya.
    const scale = props.weightBasis && remainingNet > 0 ? Math.min(1, acceptedNet / remainingNet) : 1;

    return {
        rows,
        qtyTotal: round4(Math.max(0, Number(props.remainingGoodsQty) || 0) * scale),
        netTotal: props.weightBasis ? acceptedNet : 0,
    };
});

const autoDistributeSummary = computed(() => {
    const { qtyTotal, netTotal } = distribution.value;
    const parts: string[] = [];

    if (qtyTotal > 0) {
        parts.push(`${qtyTotal.toLocaleString()} ${defaultUom()}`);
    }
    if (netTotal > 0 && !isWeightUnit(defaultUom())) {
        parts.push(`${netTotal.toLocaleString()} ${props.weightUnit}`);
    }

    return parts.join(' / ') || '0';
});

function splitEvenly(total: number, rows: number): number[] {
    if (total <= 0 || rows <= 0) {
        return [];
    }

    const each = Math.floor((total / rows) * 10000) / 10000;
    const values = Array.from({ length: rows }, () => each);
    values[rows - 1] = round4(values[rows - 1] + (total - each * rows));

    return values;
}

// Otomasi pembagian sisa qty dan kalkulasi net weight ke seluruh baris packing
function autoDistributeRemaining() {
    const { rows, qtyTotal, netTotal } = distribution.value;
    if (rows <= 0 || (qtyTotal <= 0 && netTotal <= 0)) {
        return;
    }

    const qtyValues = splitEvenly(qtyTotal, rows);
    const netValues = splitEvenly(netTotal, rows);
    let index = 0;
    let sumNet = 0;
    let lastPacking: PackingSubRow | null = null;

    bundles.value.forEach((b) => {
        b.packings.forEach((p) => {
            if (qtyValues.length > 0) {
                p.qty = qtyValues[index];
                const computedNet = calcPackingNetWeight(Number(p.qty) || 0, p.uom);
                p.net_weight = computedNet !== '' ? computedNet : (netValues[index] ?? '');
            } else if (netValues.length > 0) {
                p.net_weight = netValues[index];
            }
            sumNet += Number(p.net_weight) || 0;
            lastPacking = p;
            index++;
        });
    });

    if (lastPacking && netTotal > 0 && sumNet > 0) {
        const diff = round4(netTotal - sumNet);
        if (Math.abs(diff) > 0 && Math.abs(diff) < 0.05) {
            (lastPacking as PackingSubRow).net_weight = round4((Number((lastPacking as PackingSubRow).net_weight) || 0) + diff);
        }
    }

    bundles.value.forEach((b) => {
        const sum = round4(b.packings.reduce((acc, p) => acc + (Number(p.qty) || 0), 0));
        b.total_qty = sum > 0 ? sum : '';
    });
}

resizeBundles();

// Summary Totals
const totals = computed(() => {
    let totQty = 0;
    let totNet = 0;
    let totPack = 0;

    bundles.value.forEach((b) => {
        b.packings.forEach((p) => {
            if (isPackingFilled(p)) {
                totPack++;
            }
            totQty += Number(p.qty) || 0;
            totNet += Number(p.net_weight) || 0;
        });
    });

    const round4 = (n: number) => Math.round(n * 10000) / 10000;
    return {
        bundles: bundles.value.length,
        packings: totPack,
        qty: round4(totQty),
        net: round4(totNet),
    };
});

const clientError = ref('');

/**
 * Error validasi dari server untuk baris tag (`tags.<index>.<field>`).
 *
 * Form ini tidak merender error per field di dalam tabel, jadi tanpa ringkasan
 * ini penolakan server tidak terlihat sama sekali: pengguna menekan Simpan dan
 * tidak terjadi apa-apa.
 */
const rowErrors = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;
    const rows: Array<{ key: string; label: string; message: string }> = [];

    for (const [key, message] of Object.entries(errors)) {
        const match = key.match(/^tags\.(\d+)\.(.+)$/);
        if (!match || !message) {
            continue;
        }

        const index = Number(match[1]);
        const bundle = bundles.value[index];
        const tag = bundle?.tag?.trim();

        rows.push({
            key,
            label: tag ? `Bundle ${index + 1} (${tag})` : `Bundle ${index + 1}`,
            message,
        });
    }

    return rows;
});

/**
 * Error server yang tidak menempel pada baris tag (mis. tanggal, truk, lokasi).
 * Tanpa daftar ini pesan hanya muncul di field atas form — di luar layar saat
 * pengguna menekan Simpan di bagian bawah.
 */
const otherErrors = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return Object.entries(errors)
        .filter(([key, message]) => Boolean(message) && ! key.startsWith('tags.'))
        .map(([, message]) => String(message));
});

function submit() {
    if (submitting.value || form.processing) {
        return;
    }

    clientError.value = '';
    truckInputRef.value?.commitDraft();
    driverInputRef.value?.commitDraft();

    if (!form.truck_no.trim()) {
        clientError.value = t('incoming.truckNoRequired');
        return;
    }

    // Lengkapi net_weight dari rumus ((Total Net Weight / Total Qty Barang) × Qty/packing)
    // bila operator hanya mengisi Qty/packing, atau sebaliknya isi qty bila hanya net_weight diisi.
    bundles.value.forEach((b) => {
        b.packings.forEach((p) => {
            const qty = Number(p.qty) || 0;
            const net = Number(p.net_weight) || 0;
            if (qty > 0 && net <= 0) {
                const computedNet = calcPackingNetWeight(qty, p.uom);
                if (computedNet !== '') {
                    p.net_weight = computedNet;
                }
            } else if (props.weightBasis && qty <= 0 && net > 0) {
                p.qty = netWeightPerUnit.value > 0 ? round4(net / netWeightPerUnit.value) : net;
            }
        });
    });

    // Hanya bundle yang benar-benar berisi qty/berat yang dikirim. Form selalu
    // menyediakan beberapa baris kosong, dan server menolak packing kosong —
    // tanpa filter ini penyimpanan gagal dengan pesan yang tidak tampil di layar.
    const filled = bundles.value.filter((b) => b.packings.some(isPackingFilled));

    const emptyTagIdx = filled.findIndex((b) => !b.tag.trim());
    if (emptyTagIdx !== -1) {
        clientError.value = t('incoming.receiveTagRequired', { index: emptyTagIdx + 1 });
        return;
    }

    if (filled.length === 0 || totals.value.qty <= 0) {
        clientError.value = t('incoming.receiveQtyRequired');
        return;
    }

    if (props.weightBasis && totals.value.net <= 0) {
        clientError.value = t('incoming.receiveNetWeightRequired');
        return;
    }

    // Bentuk payload tags per bundle (1 incoming_receive per tag bundle, berisi list sub-packings).
    // Baris packing kosong dibuang dan nomornya diurutkan ulang, supaya
    // packing_count yang tersimpan sama dengan jumlah packing yang benar-benar
    // ada — bukan jumlah baris yang disediakan form.
    form.tags = filled.map((b) => {
        let bQty = 0;
        let bNet = 0;
        const tag = b.tag.trim();
        const filledPackings = b.packings.filter(isPackingFilled);

        const packingsData = filledPackings.map((p, index) => {
            const q = Number(p.qty) || 0;
            const nw = p.net_weight === '' ? null : Number(p.net_weight);

            bQty += q;
            if (nw !== null) bNet += nw;

            return {
                packing_no: `${tag}-${index + 1}`,
                sequence: index + 1,
                qty: q,
                uom: p.uom,
                net_weight: nw,
            };
        });

        const round4 = (n: number) => Math.round(n * 10000) / 10000;

        return {
            tag,
            qty: round4(bQty),
            qty_unit: defaultUom(),
            bundle_count: 1,
            bundle_qty: 1,
            bundle_unit: bundleConfig.bundle_unit,
            packing_count: packingsData.length,
            packing_type: bundleConfig.packing_type,
            packing_qty: packingsData.length > 0 ? round4(bQty / packingsData.length) : round4(bQty),
            net_weight: props.weightBasis ? round4(bNet) : (bNet > 0 ? round4(bNet) : null),
            packings: packingsData,
        };
    }) as any;

    submitting.value = true;
    form.post(route('receive.store', props.arrivalItem.id), {
        onError: () => {
            submitting.value = false;
        },
        onCancel: () => {
            submitting.value = false;
        },
    });
}
</script>

<template>
    <Head :title="t('incoming.receiveGoods')" />
    <AppLayout>
        <BackButton :href="backHref()" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4 border-b-2 border-ink-primary pb-3">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.receiveGoods') }}</h1>
                    <span
                        v-if="isOspType(resolveArrivalType(arrivalItem.arrival))"
                        :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold', arrivalTypeBadgeClass(resolveArrivalType(arrivalItem.arrival))]"
                    >
                        {{ arrivalTypeLabel(arrivalItem.arrival, t) }} · {{ arrivalSenderName(arrivalItem.arrival) }}
                    </span>
                </div>
                <p class="text-xs text-ink-secondary">Formulir penerimaan barang masuk ke gudang.</p>
            </div>
            <div class="rounded-lg bg-primary-light px-3 py-1.5 text-xs font-semibold text-primary">
                Lokasi default: Bulk Area (BULK-AREA)
            </div>
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <!-- 1. Informasi Penerimaan -->
            <section class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-ink-primary">
                        <span class="inline-grid h-6 w-6 place-items-center rounded-full bg-primary text-xs text-white">1</span>
                        Informasi Penerimaan
                    </h2>
                    <span
                        v-if="section1Prefill?.prefilled"
                        class="inline-flex items-center gap-1.5 rounded-full bg-primary-light px-3 py-1 text-xs font-semibold text-primary"
                    >
                        <svg aria-hidden="true" class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                            <path
                                fill-rule="evenodd"
                                d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"
                                clip-rule="evenodd"
                            />
                        </svg>
                        {{ t('incoming.prefilledFromInvoice') }}
                    </span>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Part</label>
                        <input
                            type="text"
                            :value="arrivalItem.part?.part_name ?? '—'"
                            readonly
                            class="mt-1 w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm font-medium text-ink-primary"
                        />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Part #</label>
                        <input
                            type="text"
                            :value="arrivalItem.part?.part_number ?? '—'"
                            readonly
                            class="mt-1 w-full rounded-lg border-borderline bg-background px-3 py-2 font-mono text-sm font-bold text-ink-primary"
                        />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Group / Ukuran</label>
                        <input
                            type="text"
                            :value="`${arrivalItem.material_group_label ?? arrivalItem.material_group ?? '—'} · ${arrivalItem.size ?? '—'}`"
                            readonly
                            class="mt-1 w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-secondary"
                        />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.receiveDate') }}</label>
                        <input
                            v-model="form.receive_date"
                            type="date"
                            :aria-label="t('incoming.receiveDate')"
                            @keydown.enter.prevent
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.receive_date" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">
                            {{ t('incoming.truckNo') }} <span class="text-danger">*</span>
                        </label>
                        <MultiTagInput
                            ref="truckInputRef"
                            v-model="form.truck_no"
                            :placeholder="t('incoming.truckNoPlaceholder')"
                            :aria-label="t('incoming.truckNo')"
                            :aria-required="true"
                            :uppercase="true"
                            :dedupe="true"
                            :remove-label="(v) => t('incoming.removeEntry', { value: v })"
                        />
                        <p class="mt-1 text-[11px] text-ink-secondary">{{ t('incoming.multiEntryHint') }}</p>
                        <InputError :message="form.errors.truck_no" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.driverName') }}</label>
                        <MultiTagInput
                            ref="driverInputRef"
                            v-model="form.driver_name"
                            :placeholder="t('incoming.driverNamePlaceholder')"
                            :aria-label="t('incoming.driverName')"
                            :remove-label="(v) => t('incoming.removeEntry', { value: v })"
                        />
                        <p class="mt-1 text-[11px] text-ink-secondary">{{ t('incoming.multiEntryHint') }}</p>
                        <InputError :message="form.errors.driver_name" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.poNo') }}</label>
                        <input
                            type="text"
                            :value="arrivalItem.arrival?.purchase_order?.po_no ?? arrivalItem.arrival?.po_no ?? arrivalItem.arrival?.contract_no ?? arrivalItem.arrival?.reference_no ?? '—'"
                            readonly
                            class="mt-1 w-full rounded-lg border-borderline bg-background px-3 py-2 font-mono text-sm font-medium text-ink-primary"
                        />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.invoiceNo') }}</label>
                        <input
                            type="text"
                            :value="arrivalItem.arrival?.invoice_no || '—'"
                            readonly
                            class="mt-1 w-full rounded-lg border-borderline bg-background px-3 py-2 font-mono text-sm font-medium text-ink-primary"
                        />
                        <p
                            v-if="!arrivalItem.arrival?.invoice_no && arrivalItem.arrival?.delivery_note_no"
                            class="mt-1 text-[11px] text-ink-secondary"
                        >
                            {{ t('incoming.deliveryNoteNo') }}: {{ arrivalItem.arrival.delivery_note_no }}
                        </p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.penNo') }}</label>
                        <input
                            v-model="form.pen_no"
                            type="text"
                            :placeholder="t('incoming.penNoPlaceholder')"
                            :aria-label="t('incoming.penNo')"
                            @keydown.enter.prevent
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="(form.errors as any).pen_no" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.penDate') }}</label>
                        <input
                            v-model="form.pen_date"
                            type="date"
                            :aria-label="t('incoming.penDate')"
                            @keydown.enter.prevent
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="(form.errors as any).pen_date" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.ajuNo') }}</label>
                        <input
                            v-model="form.aju_no"
                            type="text"
                            placeholder="No. AJU Bea Cukai"
                            :aria-label="t('incoming.ajuNo')"
                            @keydown.enter.prevent
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="(form.errors as any).aju_no" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.ajuDate') }}</label>
                        <input
                            v-model="form.aju_date"
                            type="date"
                            :aria-label="t('incoming.ajuDate')"
                            @keydown.enter.prevent
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="(form.errors as any).aju_date" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Lokasi</label>
                        <input
                            type="text"
                            :value="form.location_code || 'BULK-AREA'"
                            readonly
                            class="mt-1 w-full rounded-lg border-primary/30 bg-primary-light/40 px-3 py-2 font-mono text-sm font-bold text-primary"
                        />
                        <p class="mt-1 text-[11px] text-ink-secondary">Otomatis default Bulk area</p>
                    </div>
                </div>
            </section>

            <!-- 2. Bundle & Packing -->
            <section class="rounded-xl border border-borderline bg-surface p-5">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold text-ink-primary">
                    <span class="inline-grid h-6 w-6 place-items-center rounded-full bg-primary text-xs text-white">2</span>
                    Bundle & Packing
                </h2>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">
                            {{ t('incoming.bundleQty') }}
                        </label>
                        <input
                            v-model="bundleConfig.bundle_count"
                            type="number"
                            min="1"
                            max="50"
                            @keydown.enter.prevent
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-bold text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <p class="mt-1 text-[11px] text-ink-secondary">Jumlah baris bundle / tag</p>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">
                            {{ t('incoming.bundleUnit') }}
                        </label>
                        <select
                            v-model="bundleConfig.bundle_unit"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-medium text-ink-primary focus:border-primary focus:ring-primary"
                        >
                            <option v-for="u in bundleTypeOptions" :key="u" :value="u">{{ u }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.packingType') }}</label>
                        <select
                            v-model="bundleConfig.packing_type"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-medium text-ink-primary focus:border-primary focus:ring-primary"
                        >
                            <option v-for="p in packingTypeOptions" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </div>
                </div>
            </section>

            <!-- 3. Detail Bundle (Tabel Ber-Rowspan) -->
            <section class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="flex items-center gap-2 text-sm font-bold text-ink-primary">
                            <span class="inline-grid h-6 w-6 place-items-center rounded-full bg-primary text-xs text-white">3</span>
                            Detail Bundle & Sub-Packing
                        </h2>
                        <p class="text-xs text-ink-secondary">
                            Penomoran packing otomatis {TAG}-{1..N}. Tag Bundle adalah identitas utama saat scan fisik.
                        </p>
                        <p class="text-xs text-ink-secondary">{{ t('incoming.packingPerBundleTableHint') }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <button
                            type="button"
                            @click="autoDistributeRemaining"
                            class="rounded-lg border border-primary px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-light"
                        >
                            ⚡ Auto-distribusikan Sisa ({{ autoDistributeSummary }})
                        </button>
                    </div>
                </div>

                <InputError :message="form.errors.tags" class="mb-3" />
                <p v-if="packingCapNotice" class="mb-3 text-xs font-semibold text-warning">
                    {{ t('incoming.packingRowLimit', { packing: packingCapNotice, max: MAX_TOTAL_ROWS }) }}
                </p>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-borderline border border-borderline text-sm">
                        <thead class="bg-background">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                <th class="w-12 border border-borderline px-3 py-2.5 text-center">No</th>
                                <th class="w-52 border border-borderline px-3 py-2.5">{{ t('master.partName') }}</th>
                                <th class="w-32 border border-borderline px-3 py-2.5">{{ t('master.model') }}</th>
                                <th class="w-44 border border-borderline px-3 py-2.5">Tag # (Bundle)</th>
                                <th class="w-36 border border-borderline px-3 py-2.5 text-right">{{ t('incoming.qtyTotalPerTag') }}</th>
                                <th class="w-28 border border-borderline px-3 py-2.5 text-center">{{ t('incoming.packingQty') }}</th>
                                <th class="w-36 border border-borderline px-3 py-2.5">Packing #</th>
                                <th class="w-32 border border-borderline px-3 py-2.5 text-right">Qty/packing</th>
                                <th class="w-24 border border-borderline px-3 py-2.5">UOM</th>
                                <th class="w-36 border border-borderline px-3 py-2.5 text-right">Net weight (kg)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="(b, bi) in bundles" :key="bi">
                                <tr
                                    v-for="(r, ri) in b.packings"
                                    :key="`${bi}-${ri}`"
                                    :class="[ri === 0 ? 'border-t-2 border-ink-primary' : '', 'hover:bg-primary-light/20']"
                                >
                                    <!-- Kolom No & Tag # menggunakan rowspan setinggi jumlah packing -->
                                    <td
                                        v-if="ri === 0"
                                        :rowspan="b.packings.length"
                                        class="border border-borderline bg-surface px-3 py-2 text-center font-bold text-ink-primary align-middle"
                                    >
                                        {{ bi + 1 }}
                                    </td>
                                    <!-- Part & model dari master, bukan input: identitas part tidak boleh diubah saat menerima -->
                                    <td
                                        v-if="ri === 0"
                                        :rowspan="b.packings.length"
                                        class="border border-borderline bg-surface px-3 py-2 align-middle text-xs font-semibold text-ink-primary"
                                    >
                                        {{ arrivalItem.part?.part_name || '—' }}
                                    </td>
                                    <td
                                        v-if="ri === 0"
                                        :rowspan="b.packings.length"
                                        class="border border-borderline bg-surface px-3 py-2 align-middle text-xs text-ink-secondary"
                                    >
                                        {{ arrivalItem.part?.model || '—' }}
                                    </td>
                                    <td
                                        v-if="ri === 0"
                                        :rowspan="b.packings.length"
                                        class="border border-borderline bg-surface p-2 align-middle"
                                    >
                                        <input
                                            v-model="b.tag"
                                            type="text"
                                            placeholder="Tag bundle"
                                            :aria-label="`${t('incoming.tag')} ${bi + 1}`"
                                            @keydown.enter.prevent
                                            class="w-full rounded border-borderline bg-surface px-2.5 py-1.5 font-mono text-xs font-bold text-ink-primary focus:border-primary focus:ring-primary"
                                        />
                                    </td>
                                    <td
                                        v-if="ri === 0"
                                        :rowspan="b.packings.length"
                                        class="border border-borderline bg-surface p-2 text-right align-middle"
                                    >
                                        <input
                                            v-model="b.total_qty"
                                            type="number"
                                            step="any"
                                            min="0"
                                            placeholder="0"
                                            :aria-label="t('incoming.qtyTotalForBundle', { bundle: bi + 1 })"
                                            @input="onBundleTotalQty(b)"
                                            @keydown.enter.prevent
                                            class="w-full rounded border-borderline bg-surface px-2 py-1.5 text-right text-xs font-bold tabular-nums text-primary focus:border-primary focus:ring-primary"
                                        />
                                        <div class="mt-1 text-[10px] font-semibold text-ink-secondary">
                                            {{ defaultUom() }}
                                        </div>
                                    </td>
                                    <td
                                        v-if="ri === 0"
                                        :rowspan="b.packings.length"
                                        class="border border-borderline bg-surface p-2 text-center align-middle"
                                    >
                                        <input
                                            :value="b.packings.length"
                                            type="number"
                                            min="1"
                                            :max="MAX_PACKINGS"
                                            inputmode="numeric"
                                            :aria-label="t('incoming.packingCountForBundle', { bundle: bi + 1 })"
                                            @keydown.enter.prevent
                                            class="w-16 rounded border-borderline bg-surface px-2 py-1.5 text-center text-xs font-bold text-ink-primary focus:border-primary focus:ring-primary"
                                            @input="onBundlePackingCount(b, bi, $event)"
                                            @blur="onBundlePackingCountBlur(b, $event)"
                                        />
                                        <div class="mt-1 text-[10px] font-semibold uppercase tracking-wide text-ink-secondary">
                                            {{ bundleConfig.packing_type }}
                                        </div>
                                    </td>

                                    <!-- Kolom per Packing -->
                                    <td class="border border-borderline px-3 py-2 font-mono text-xs font-semibold text-primary">
                                        <template v-if="isPackingFilled(r)">{{ b.tag || '-' }}-{{ packingSequence(b, ri) }}</template>
                                        <span v-else class="text-ink-secondary">—</span>
                                    </td>
                                    <td class="border border-borderline p-1 text-right">
                                        <input
                                            v-model="r.qty"
                                            type="number"
                                            step="any"
                                            min="0"
                                            @input="onQtyChange(b, r)"
                                            @keydown.enter.prevent
                                            placeholder="0"
                                            class="w-full rounded border-borderline bg-surface px-2 py-1 text-right text-xs font-bold text-ink-primary focus:border-primary focus:ring-primary"
                                        />
                                    </td>
                                    <td class="border border-borderline p-1">
                                        <select
                                            v-model="r.uom"
                                            class="w-full rounded border-borderline bg-surface px-1.5 py-1 text-xs text-ink-primary focus:border-primary focus:ring-primary"
                                        >
                                            <option v-for="u in uomCodes" :key="u" :value="u">{{ u }}</option>
                                        </select>
                                    </td>
                                    <td class="border border-borderline p-1 text-right">
                                        <input
                                            v-model="r.net_weight"
                                            type="number"
                                            step="any"
                                            min="0"
                                            placeholder="0.00"
                                            @keydown.enter.prevent
                                            class="w-full rounded border-borderline bg-surface px-2 py-1 text-right text-xs text-ink-primary focus:border-primary focus:ring-primary"
                                        />
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="border-t-2 border-ink-primary bg-background font-bold text-ink-primary">
                            <tr>
                                <td colspan="7" class="border border-borderline px-3 py-2 text-right text-xs uppercase">
                                    TOTAL ({{ totals.bundles }} Bundle, {{ totals.packings }} Packing) :
                                </td>
                                <td class="border border-borderline px-3 py-2 text-right tabular-nums text-primary text-xs font-bold">
                                    {{ totals.qty.toLocaleString() }}
                                </td>
                                <td class="border border-borderline px-3 py-2 text-xs">
                                    {{ defaultUom() }}
                                </td>
                                <td class="border border-borderline px-3 py-2 text-right tabular-nums text-xs">
                                    {{ totals.net.toLocaleString() }} kg
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <div
                v-if="clientError || rowErrors.length > 0 || otherErrors.length > 0"
                class="mb-3 rounded-lg border border-danger bg-danger/10 px-4 py-3 text-sm text-danger"
                role="alert"
            >
                <p class="font-semibold">{{ t('incoming.receiveSaveBlocked') }}</p>
                <ul class="mt-1 space-y-0.5">
                    <li v-if="clientError">{{ clientError }}</li>
                    <template v-else>
                        <li v-if="rowErrors.length > 0">{{ t('incoming.receiveRowErrors', { count: rowErrors.length }) }}</li>
                        <li v-for="item in rowErrors" :key="item.key">{{ item.label }} — {{ item.message }}</li>
                    </template>
                    <li v-for="message in otherErrors" :key="message">{{ message }}</li>
                </ul>
            </div>

            <!-- Aksi Tombol -->
            <div class="flex items-center justify-end gap-3 pt-2">
                <BackButton :href="backHref()">{{ t('incoming.cancel') }}</BackButton>
                <button
                    type="submit"
                    :disabled="submitting || form.processing"
                    class="rounded-md bg-primary px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                >
                    {{ submitting || form.processing ? t('incoming.saving') : t('incoming.saveReceive') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
