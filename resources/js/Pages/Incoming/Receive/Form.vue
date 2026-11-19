<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, reactive, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import type { IncomingArrivalItem } from '@/types';

const { t } = useI18n();

const MAX_BUNDLES = 50;
const MAX_PACKINGS = 999;
const MAX_TOTAL_ROWS = 1000;
const requestedPackingCount = ref<number | null>(null);

interface PackingSubRow {
    qty: number | '';
    uom: string;
    net_weight: number | '';
}

interface BundleGroup {
    tag: string;
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

// Section 2 State
const bundleConfig = reactive({
    bundle_count: initialBundleCount() as number | '',
    bundle_unit: initialBundleUnit(),
    packing_count: 3 as number | '',
    packing_type: packingTypeOptions.value.includes('COIL') ? 'COIL' : (packingTypeOptions.value[0] ?? 'BOX'),
});

const packingCapNotice = computed(() => {
    const applied = Number(bundleConfig.packing_count) || 0;
    const requested = requestedPackingCount.value;

    return requested !== null && requested > applied ? applied : null;
});

// Section 1 Form State
const form = useForm({
    receive_date: new Date().toISOString().slice(0, 10),
    truck_no: '',
    driver_name: '',
    location_code: props.defaultLocation ?? 'BULK-AREA',
    pen_no: props.arrivalItem.arrival?.pen_no ?? '',
    aju_no: props.arrivalItem.arrival?.aju_no ?? '',
    tags: [] as any[],
});

// Bundles reactive list
const bundles = ref<BundleGroup[]>([]);

function createPackingRow(): PackingSubRow {
    return {
        qty: '',
        uom: defaultUom(),
        net_weight: '',
    };
}

function resizeBundles() {
    let nb = Math.floor(Number(bundleConfig.bundle_count) || 1);
    const requestedPacking = Math.floor(Number(bundleConfig.packing_count) || 1);
    nb = Math.min(MAX_BUNDLES, Math.max(1, nb));

    const packingCap = Math.max(1, Math.min(MAX_PACKINGS, Math.floor(MAX_TOTAL_ROWS / nb)));
    const np = Math.min(packingCap, Math.max(1, requestedPacking));

    bundleConfig.bundle_count = nb;
    bundleConfig.packing_count = np;

    const current = bundles.value;
    while (current.length < nb) {
        const idx = current.length + 1;
        // Tag = identitas bundle yang diketik operator. Prefill cukup nomor
        // bundle agar tidak tertukar dengan nomor invoice / nomor part.
        current.push({
            tag: String(idx),
            packings: [],
        });
    }
    current.length = nb;

    current.forEach((b) => {
        while (b.packings.length < np) {
            b.packings.push(createPackingRow());
        }
        b.packings.length = np;
    });
}

watch(() => [bundleConfig.bundle_count, bundleConfig.packing_count], resizeBundles);

function onPackingCountInput(event: Event) {
    const value = Number((event.target as HTMLInputElement).value);
    requestedPackingCount.value = Number.isFinite(value) && value > 0 ? Math.floor(value) : null;
}

// Otomasi Qty -> Net weight jika UOM = KGM/KG
function onQtyChange(r: PackingSubRow) {
    if (isWeightUnit(r.uom) && r.qty !== '' && (r.net_weight === '' || r.net_weight === 0)) {
        r.net_weight = r.qty;
    }
}

const round4 = (n: number) => Math.round(n * 10000) / 10000;

/**
 * Sisa yang dibagi ke seluruh baris packing.
 *
 * Qty dan net weight adalah dua besaran berbeda di dokumen kedatangan: qty
 * memakai satuan barang (mis. COIL), net weight selalu KGM. Dulu hanya qty
 * yang dibagi, lalu net weight disamakan dengan qty sehingga 825 COIL menjadi
 * 825 KGM. Sekarang keduanya dibagi dari sisa masing-masing. Net weight
 * dibatasi `maxReceivable` (sisa berat setelah limit PO) dan qty diskalakan
 * dengan rasio yang sama supaya perbandingan qty:berat tetap seperti dokumen
 * kedatangan.
 */
const distribution = computed(() => {
    const rows = (Number(bundleConfig.bundle_count) || 1) * (Number(bundleConfig.packing_count) || 1);
    const acceptedNet = props.weightBasis ? Math.max(0, Number(props.maxReceivable) || 0) : 0;
    const remainingNet = Math.max(0, Number(props.remainingNetWeight) || 0);
    const scale = props.weightBasis && remainingNet > 0 ? Math.min(1, acceptedNet / remainingNet) : 1;

    return {
        rows,
        qtyTotal: round4(Math.max(0, Number(props.remainingGoodsQty) || 0) * scale),
        netTotal: acceptedNet,
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

// Otomasi pembagian sisa qty dan net weight ke seluruh baris
function autoDistributeRemaining() {
    const { rows, qtyTotal, netTotal } = distribution.value;
    if (rows <= 0 || (qtyTotal <= 0 && netTotal <= 0)) {
        return;
    }

    const qtyValues = splitEvenly(qtyTotal, rows);
    const netValues = splitEvenly(netTotal, rows);
    let index = 0;

    bundles.value.forEach((b) => {
        b.packings.forEach((p) => {
            if (qtyValues.length > 0) {
                p.qty = qtyValues[index];
            }
            if (netValues.length > 0) {
                p.net_weight = netValues[index];
            } else if (isWeightUnit(p.uom) && qtyValues.length > 0) {
                p.net_weight = p.qty;
            }
            index++;
        });
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
            totPack++;
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
    clientError.value = '';

    if (!form.truck_no.trim()) {
        clientError.value = t('incoming.truckNoRequired');
        return;
    }

    // Material berbasis berat: operator sering hanya mengisi Net weight.
    // Pada item berbasis berat qty memakai satuan yang sama (KGM), jadi qty
    // diisi dari net weight agar penerimaan tidak tertahan tanpa penjelasan.
    if (props.weightBasis) {
        bundles.value.forEach((b) => {
            b.packings.forEach((p) => {
                const qty = Number(p.qty) || 0;
                const net = Number(p.net_weight) || 0;
                if (qty <= 0 && net > 0) {
                    p.qty = net;
                }
            });
        });
    }

    // Hanya bundle yang benar-benar berisi qty yang dikirim. Form selalu
    // menyediakan beberapa baris kosong (default 4 bundle x 3 packing), dan
    // server menolak tag ber-qty 0 — tanpa filter ini penyimpanan gagal
    // dengan pesan yang tidak tampil di layar.
    const filled = bundles.value.filter((b) => b.packings.some((p) => (Number(p.qty) || 0) > 0));

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

    // Bentuk payload tags per bundle (1 incoming_receive per tag bundle, berisi list sub-packings)
    form.tags = filled.map((b) => {
        let bQty = 0;
        let bNet = 0;

        const packingsData = b.packings.map((p, pi) => {
            const q = Number(p.qty) || 0;
            const nw = p.net_weight === '' ? null : Number(p.net_weight);

            bQty += q;
            if (nw !== null) bNet += nw;

            return {
                packing_no: `${b.tag}/${pi + 1}`,
                sequence: pi + 1,
                qty: q,
                uom: p.uom,
                net_weight: nw,
            };
        });

        const round4 = (n: number) => Math.round(n * 10000) / 10000;

        return {
            tag: b.tag.trim(),
            qty: round4(bQty),
            qty_unit: defaultUom(),
            bundle_count: 1,
            bundle_qty: 1,
            bundle_unit: bundleConfig.bundle_unit,
            packing_count: b.packings.length,
            packing_type: bundleConfig.packing_type,
            packing_qty: b.packings.length > 0 ? round4(bQty / b.packings.length) : round4(bQty),
            net_weight: props.weightBasis ? round4(bNet) : (bNet > 0 ? round4(bNet) : null),
            packings: packingsData,
        };
    }) as any;

    form.post(route('receive.store', props.arrivalItem.id));
}
</script>

<template>
    <Head :title="t('incoming.receiveGoods')" />
    <AppLayout>
        <BackButton :href="backHref()" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4 border-b-2 border-ink-primary pb-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.receiveGoods') }}</h1>
                <p class="text-xs text-ink-secondary">Formulir penerimaan barang masuk ke gudang.</p>
            </div>
            <div class="rounded-lg bg-primary-light px-3 py-1.5 text-xs font-semibold text-primary">
                Lokasi default: Bulk Area (BULK-AREA)
            </div>
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <!-- 1. Informasi Penerimaan -->
            <section class="rounded-xl border border-borderline bg-surface p-5">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold text-ink-primary">
                    <span class="inline-grid h-6 w-6 place-items-center rounded-full bg-primary text-xs text-white">1</span>
                    Informasi Penerimaan
                </h2>

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
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.receive_date" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">
                            {{ t('incoming.truckNo') }} <span class="text-danger">*</span>
                        </label>
                        <input
                            v-model="form.truck_no"
                            type="text"
                            placeholder="Contoh: B 9123 GCI"
                            :aria-label="t('incoming.truckNo')"
                            :aria-required="true"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-semibold text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.truck_no" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Nama Driver</label>
                        <input
                            v-model="form.driver_name"
                            type="text"
                            placeholder="Nama driver pengirim"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.poNo') }}</label>
                        <input
                            type="text"
                            :value="arrivalItem.arrival?.purchase_order?.po_no ?? arrivalItem.arrival?.po_no ?? '—'"
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
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.penNo') }}</label>
                        <input
                            v-model="form.pen_no"
                            type="text"
                            :placeholder="t('incoming.penNoPlaceholder')"
                            :aria-label="t('incoming.penNo')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="(form.errors as any).pen_no" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.ajuNo') }}</label>
                        <input
                            v-model="form.aju_no"
                            type="text"
                            placeholder="No. AJU Bea Cukai"
                            :aria-label="t('incoming.ajuNo')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="(form.errors as any).aju_no" class="mt-1" />
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

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">
                            {{ t('incoming.bundleQty') }}
                        </label>
                        <input
                            v-model="bundleConfig.bundle_count"
                            type="number"
                            min="1"
                            max="50"
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
                        <label class="text-xs font-semibold text-ink-secondary">Jumlah Packing</label>
                        <input
                            v-model="bundleConfig.packing_count"
                            type="number"
                            min="1"
                            :max="MAX_PACKINGS"
                            @input="onPackingCountInput"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-bold text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <p v-if="packingCapNotice" class="mt-1 text-[11px] font-semibold text-warning">
                            {{ t('incoming.packingRowLimit', { packing: packingCapNotice, max: MAX_TOTAL_ROWS }) }}
                        </p>
                        <p v-else class="mt-1 text-[11px] text-ink-secondary">Packing per bundle</p>
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
                            Penomoran packing otomatis {TAG}/{1..N}. Tag Bundle adalah identitas utama saat scan fisik.
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="autoDistributeRemaining"
                        class="rounded-lg border border-primary px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-light"
                    >
                        ⚡ Auto-distribusikan Sisa ({{ autoDistributeSummary }})
                    </button>
                </div>

                <InputError :message="form.errors.tags" class="mb-3" />

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-borderline border border-borderline text-sm">
                        <thead class="bg-background">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                <th class="w-12 border border-borderline px-3 py-2.5 text-center">No</th>
                                <th class="w-52 border border-borderline px-3 py-2.5">{{ t('master.partName') }}</th>
                                <th class="w-32 border border-borderline px-3 py-2.5">{{ t('master.model') }}</th>
                                <th class="w-40 border border-borderline px-3 py-2.5">Tag # (Bundle)</th>
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
                                            class="w-full rounded border-borderline bg-surface px-2.5 py-1.5 font-mono text-xs font-bold text-ink-primary focus:border-primary focus:ring-primary"
                                        />
                                    </td>

                                    <!-- Kolom per Packing -->
                                    <td class="border border-borderline px-3 py-2 font-mono text-xs font-semibold text-primary">
                                        {{ b.tag || '-' }}/{{ ri + 1 }}
                                    </td>
                                    <td class="border border-borderline p-1 text-right">
                                        <input
                                            v-model="r.qty"
                                            type="number"
                                            step="any"
                                            min="0"
                                            @input="onQtyChange(r)"
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
                                            class="w-full rounded border-borderline bg-surface px-2 py-1 text-right text-xs text-ink-primary focus:border-primary focus:ring-primary"
                                        />
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="border-t-2 border-ink-primary bg-background font-bold text-ink-primary">
                            <tr>
                                <td colspan="5" class="border border-borderline px-3 py-2 text-right text-xs uppercase">
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
                    :disabled="form.processing"
                    class="rounded-md bg-primary px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                >
                    {{ form.processing ? t('incoming.saving') : t('incoming.saveReceive') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
