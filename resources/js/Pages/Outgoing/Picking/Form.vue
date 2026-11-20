<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { formatDateTime } from '@/utils/date';

const { t } = useI18n();

interface CustomerOption {
    id: number;
    customer_code: string;
    customer_name: string;
}

interface PartOption {
    id: number;
    part_number: string;
    part_name: string;
    model: string | null;
    uom: string | null;
    stock: number;
}

interface PickingItem {
    id: number;
    sequence: number;
    customer_id: number;
    part_id: number;
    qty: number;
    qty_unit: string | null;
}

interface PickingList {
    id: number;
    picking_no: string;
    picking_date: string;
    status: string;
    notes: string | null;
    items?: PickingItem[];
}

interface Row {
    uid: number;
    sequence: number;
    customer_id: number | '';
    part_id: number | '';
    qty: string;
    qty_unit: string | null;
}

const props = defineProps<{
    pickingList: PickingList | null;
    customers: CustomerOption[];
    parts: PartOption[];
    stockAsOf: string;
    deliveriesCount: number;
}>();

const locked = computed(() => props.deliveriesCount > 0 || (props.pickingList !== null && props.pickingList.status !== 'draft'));

const backHref = computed(() =>
    props.pickingList ? route('picking-lists.show', props.pickingList.id) : route('picking-lists.index'),
);

let rowSeq = 0;
const nextUid = () => ++rowSeq;

const form = useForm({
    picking_date: props.pickingList?.picking_date?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
    notes: props.pickingList?.notes ?? '',
    items: (props.pickingList?.items ?? []).map<Row>((item) => ({
        uid: nextUid(),
        sequence: item.sequence,
        customer_id: item.customer_id,
        part_id: item.part_id,
        qty: String(item.qty),
        qty_unit: item.qty_unit,
    })),
});

if (form.items.length === 0) {
    form.items.push({ uid: nextUid(), sequence: 1, customer_id: '', part_id: '', qty: '', qty_unit: null });
}

const partById = computed(() => new Map(props.parts.map((part) => [part.id, part])));

const sequences = computed(() => [...new Set(form.items.map((row) => row.sequence))].sort((a, b) => a - b));

const rowsOf = (sequence: number) => form.items.filter((row) => row.sequence === sequence);

/** Customer satu seq = customer baris pertamanya yang terisi. */
function seqCustomer(sequence: number): number | '' {
    const row = rowsOf(sequence).find((item) => item.customer_id !== '');
    return row ? Number(row.customer_id) : '';
}

function setSeqCustomer(sequence: number, value: number | '' | null): void {
    const id = value === null || value === '' ? '' : Number(value);

    for (const row of rowsOf(sequence)) {
        row.customer_id = id;
    }
}

const customerOptions = computed(() =>
    props.customers.map((customer) => ({
        value: customer.id,
        label: customer.customer_name,
        sublabel: customer.customer_code,
    })),
);

/** FG: part number + nama, model dan stok hanya tampil di daftar pilihan. */
const partOptions = computed(() =>
    props.parts.map((part) => ({
        value: part.id,
        label: part.part_number,
        sublabel: [part.part_name, part.model].filter(Boolean).join(' · '),
        badge: part.stock > 0 ? `${t('picking.stock')} ${part.stock}` : undefined,
        badgeTone: part.stock > 0 ? ('success' as const) : ('danger' as const),
    })),
);

const customerNameById = computed(() => new Map(props.customers.map((customer) => [customer.id, customer.customer_name])));

function parseQty(value: string | number | null | undefined): number {
    if (value === null || value === undefined) {
        return 0;
    }

    const parsed = Number(String(value).replace(',', '.'));
    return Number.isFinite(parsed) ? parsed : 0;
}

/**
 * Perkiraan stok tersedia, dihitung dengan aturan yang sama seperti server:
 * baris tanpa customer atau tanpa part dilewati, lalu qty dialokasikan berurutan.
 */
const availableByRow = computed(() => {
    const allocated = new Map<number, number>();
    const result = new Map<number, number>();

    for (const row of form.items) {
        const partId = Number(row.part_id);
        if (!partId || row.customer_id === '') {
            continue;
        }

        const part = partById.value.get(partId);
        const used = allocated.get(partId) ?? 0;
        result.set(row.uid, Math.max(0, Math.round(((part?.stock ?? 0) - used) * 10000) / 10000));
        allocated.set(partId, used + parseQty(row.qty));
    }

    return result;
});

const exceedsByRow = computed(() => {
    const map = new Map<number, number>();

    for (const row of form.items) {
        const available = availableByRow.value.get(row.uid);
        if (available === undefined) {
            continue;
        }

        const over = Math.round((parseQty(row.qty) - available) * 10000) / 10000;
        if (over > 0) {
            map.set(row.uid, over);
        }
    }

    return map;
});

const exceedCount = computed(() => exceedsByRow.value.size);

const stockAsOfLabel = computed(() => {
    const date = new Date(props.stockAsOf);
    return Number.isNaN(date.getTime()) ? props.stockAsOf : formatDateTime(props.stockAsOf);
});

// --- Undo (satu slot) -------------------------------------------------------

type UndoEntry = { kind: 'row'; row: Row; index: number } | { kind: 'seq'; sequence: number; rows: Row[] };

const undoEntry = ref<UndoEntry | null>(null);
let undoTimer: ReturnType<typeof setTimeout> | undefined;

function pushUndo(entry: UndoEntry): void {
    undoEntry.value = entry;
    clearTimeout(undoTimer);
    undoTimer = setTimeout(() => (undoEntry.value = null), 10000);
}

function undo(): void {
    const entry = undoEntry.value;
    undoEntry.value = null;

    if (!entry) {
        return;
    }

    if (entry.kind === 'row') {
        form.items.splice(entry.index, 0, entry.row);
        return;
    }

    const existing = new Set(form.items.map((row) => row.uid));
    const restored = entry.rows.filter((row) => !existing.has(row.uid));
    form.items.push(...restored);
}

const undoLabel = computed(() => {
    const entry = undoEntry.value;
    if (!entry) {
        return '';
    }

    return entry.kind === 'row'
        ? t('picking.undoRow')
        : t('picking.undoSeq', { n: entry.sequence, count: entry.rows.length });
});

onBeforeUnmount(() => clearTimeout(undoTimer));

// --- Baris & seq ------------------------------------------------------------

const fgRefs = new Map<number, { focus: () => void; open: () => void }>();
const qtyRefs = new Map<number, HTMLInputElement>();

function setFgRef(uid: number, el: unknown): void {
    if (el && typeof (el as { focus?: unknown }).focus === 'function') {
        fgRefs.set(uid, el as { focus: () => void; open: () => void });
    } else {
        fgRefs.delete(uid);
    }
}

function setQtyRef(uid: number, el: unknown): void {
    if (el instanceof HTMLInputElement) {
        qtyRefs.set(uid, el);
    } else {
        qtyRefs.delete(uid);
    }
}

function focusRow(row: Row, field: 'fg' | 'qty', openList = false): void {
    nextTick(() => {
        if (field === 'fg') {
            // Baris baru langsung membuka daftar supaya operator bisa mengetik
            // tanpa klik tambahan; fokus karena error cukup fokus saja.
            const ref = fgRefs.get(row.uid);
            openList ? ref?.open() : ref?.focus();
            return;
        }

        const input = qtyRefs.get(row.uid);
        input?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        input?.focus();
    });
}

function addSeq(): void {
    const next = sequences.value.length > 0 ? Math.max(...sequences.value) + 1 : 1;
    const row: Row = { uid: nextUid(), sequence: next, customer_id: '', part_id: '', qty: '', qty_unit: null };
    form.items.push(row);
    focusRow(row, 'fg');
}

function duplicateSeq(sequence: number): void {
    const source = rowsOf(sequence);
    const next = Math.max(...sequences.value) + 1;
    const copies = source.map<Row>((row) => ({ ...row, uid: nextUid(), sequence: next }));

    form.items.push(...copies);
}

function removeSeq(sequence: number): void {
    const rows = rowsOf(sequence);
    if (rows.length === 0) {
        return;
    }

    pushUndo({ kind: 'seq', sequence, rows });
    form.items = form.items.filter((row) => row.sequence !== sequence);
}

function addRow(sequence: number): void {
    const row: Row = {
        uid: nextUid(),
        sequence,
        customer_id: seqCustomer(sequence),
        part_id: '',
        qty: '',
        qty_unit: null,
    };

    form.items.push(row);
    focusRow(row, 'fg', true);
}

function removeRow(row: Row): void {
    if (form.items.length <= 1) {
        return;
    }

    if (rowsOf(row.sequence).length <= 1) {
        return;
    }

    const index = form.items.indexOf(row);
    pushUndo({ kind: 'row', row, index });
    form.items = form.items.filter((item) => item !== row);
}

function onPartChange(row: Row): void {
    const part = partById.value.get(Number(row.part_id));
    row.qty_unit = part?.uom ?? null;

    if (row.part_id !== '') {
        focusRow(row, 'qty');
    }
}

function onQtyEnter(row: Row): void {
    addRow(row.sequence);
}

// --- Error & submit ---------------------------------------------------------

const errorFor = (row: Row, field: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[`items.${row.uid}.${field}`];

/** Ringkasan error per baris: dipakai untuk banner di atas tabel. */
const errorSummary = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;
    const rows: Array<{ key: string; label: string; message: string; row: Row }> = [];

    for (const row of form.items) {
        for (const field of ['customer_id', 'part_id', 'qty'] as const) {
            const message = errors[`items.${row.uid}.${field}`];
            if (!message) {
                continue;
            }

            rows.push({
                key: `${row.uid}-${field}`,
                label: `${t('picking.seqTitle', { n: row.sequence })} · ${t('picking.rowLabel', { n: rowsOf(row.sequence).indexOf(row) + 1 })}`,
                message,
                row,
            });
        }
    }

    return rows;
});

const globalItemsError = computed(() => (form.errors as Record<string, string | undefined>).items);

function submit(): void {
    if (locked.value) {
        return;
    }

    // Baris kosong (tanpa FG dan tanpa qty) tidak ikut dikirim: form selalu
    // menyediakan satu baris kosong untuk mulai mengetik.
    const payload = form.items
        .filter((row) => !(row.part_id === '' && parseQty(row.qty) === 0))
        .map((row) => ({
            uid: row.uid,
            sequence: row.sequence,
            customer_id: row.customer_id,
            part_id: row.part_id,
            qty: parseQty(row.qty),
            qty_unit: row.qty_unit,
        }));

    if (payload.length === 0) {
        form.setError('items', t('picking.noRowsToSave'));
        return;
    }

    const options = {
        preserveScroll: true,
        onError: () => {
            const first = errorSummary.value[0];
            if (first) {
                focusRow(first.row, first.row.part_id === '' ? 'fg' : 'qty');
            }
        },
    };

    form.transform(() => ({
        picking_date: form.picking_date,
        notes: form.notes,
        items: payload,
    }));

    if (props.pickingList) {
        form.put(route('picking-lists.update', props.pickingList.id), options);
        return;
    }

    form.post(route('picking-lists.store'), options);
}
</script>

<template>
    <Head :title="pickingList ? t('picking.editTitle', { no: pickingList.picking_no }) : t('picking.new')" />
    <AppLayout>
        <BackButton :href="backHref" class="mb-4" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">
                {{ pickingList ? t('picking.editTitle', { no: pickingList.picking_no }) : t('picking.new') }}
            </h1>
            <p class="mt-1 text-sm text-ink-secondary">{{ t('picking.subtitle') }}</p>
        </div>

        <div
            v-if="locked"
            class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning bg-warning/10 px-4 py-3 text-sm text-warning-ink"
            role="status"
        >
            <span>{{ t('picking.lockedWithDeliveries', { count: deliveriesCount }) }}</span>
            <Link
                v-if="pickingList"
                :href="route('picking-lists.show', pickingList.id)"
                class="rounded-md border border-warning px-3 py-1.5 text-xs font-semibold text-warning-ink transition hover:bg-warning/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-warning"
            >
                {{ t('picking.openPicking') }}
            </Link>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <section class="rounded-xl border border-borderline bg-surface p-5">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-ink-secondary">{{ t('picking.infoSection') }}</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel for="picking_date" :value="t('picking.date')" />
                        <input
                            id="picking_date"
                            v-model="form.picking_date"
                            type="date"
                            :disabled="locked"
                            class="mt-1 w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-60"
                        />
                        <InputError :message="form.errors.picking_date" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel for="notes" :value="t('picking.notes')" />
                        <input
                            id="notes"
                            v-model="form.notes"
                            type="text"
                            :disabled="locked"
                            class="mt-1 w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-60"
                        />
                        <InputError :message="form.errors.notes" class="mt-1" />
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-secondary">{{ t('picking.detailSection') }}</h2>
                        <p class="mt-1 text-xs text-ink-secondary">{{ t('picking.stockHint', { time: stockAsOfLabel }) }}</p>
                    </div>
                    <button
                        type="button"
                        :disabled="locked"
                        class="inline-flex items-center gap-1.5 rounded-md border border-borderline bg-surface px-3 py-1.5 text-sm font-semibold text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50"
                        @click="addSeq"
                    >
                        + {{ t('picking.addSeq') }}
                    </button>
                </div>

                <div
                    v-if="errorSummary.length > 0 || globalItemsError"
                    class="mb-3 rounded-lg border border-danger bg-danger/10 px-4 py-3 text-sm text-danger"
                    role="alert"
                >
                    <p v-if="globalItemsError" class="font-semibold">{{ globalItemsError }}</p>
                    <p v-if="errorSummary.length > 0" class="font-semibold">
                        {{ t('picking.summaryTitle', { count: errorSummary.length }) }}
                    </p>
                    <ul v-if="errorSummary.length > 0" class="mt-1 space-y-0.5">
                        <li v-for="item in errorSummary" :key="item.key">
                            <button
                                type="button"
                                class="text-left underline decoration-danger/40 underline-offset-2 hover:decoration-danger focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger"
                                @click="focusRow(item.row, item.row.part_id === '' ? 'fg' : 'qty')"
                            >
                                {{ item.label }} — {{ item.message }}
                            </button>
                        </li>
                    </ul>
                </div>

                <div v-if="form.items.length === 0" class="rounded-lg border border-dashed border-borderline px-4 py-8 text-center text-sm text-ink-secondary">
                    {{ t('picking.noRows') }}
                </div>

                <div v-for="sequence in sequences" :key="sequence" class="mb-4 overflow-hidden rounded-lg border border-borderline">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-borderline bg-background px-4 py-2.5">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm font-semibold text-ink-primary">{{ t('picking.seqTitle', { n: sequence }) }}</span>
                            <span class="text-xs text-ink-secondary">{{ rowsOf(sequence).length }} {{ t('picking.itemsCount').toLowerCase() }}</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <div class="w-64">
                                <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-ink-secondary">
                                    {{ t('picking.seqCustomer', { n: sequence }) }}
                                </span>
                                <SearchableSelect
                                    :model-value="seqCustomer(sequence)"
                                    :options="customerOptions"
                                    size="md"
                                    :placeholder="t('picking.chooseCustomer')"
                                    :aria-label="t('picking.seqCustomer', { n: sequence })"
                                    :disabled="locked"
                                    :error="Boolean(rowsOf(sequence)[0] && errorFor(rowsOf(sequence)[0], 'customer_id'))"
                                    @update:model-value="(value: number | '' | null) => setSeqCustomer(sequence, value)"
                                >
                                    <template #leading>
                                        <svg class="h-4 w-4 shrink-0 text-ink-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                        </svg>
                                    </template>
                                </SearchableSelect>
                            </div>

                            <button
                                type="button"
                                :disabled="locked"
                                class="rounded-md border border-borderline px-2.5 py-1.5 text-xs font-semibold text-ink-primary transition hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50"
                                @click="duplicateSeq(sequence)"
                            >
                                {{ t('picking.duplicateSeq') }}
                            </button>
                            <button
                                type="button"
                                :disabled="locked"
                                class="rounded-md px-2.5 py-1.5 text-xs font-semibold text-danger transition hover:bg-danger/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger disabled:opacity-50"
                                @click="removeSeq(sequence)"
                            >
                                {{ t('picking.removeSeq') }}
                            </button>
                        </div>
                    </div>

                    <InputError
                        :message="rowsOf(sequence)[0] ? errorFor(rowsOf(sequence)[0], 'customer_id') : undefined"
                        class="px-4 pt-2"
                    />

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-borderline text-sm">
                            <thead class="bg-surface">
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                    <th scope="col" class="w-10 px-3 py-2 text-right">{{ t('picking.rowNo') }}</th>
                                    <th scope="col" class="min-w-[22rem] px-3 py-2">{{ t('picking.fg') }}</th>
                                    <th scope="col" class="w-28 px-3 py-2">{{ t('picking.qty') }}</th>
                                    <th scope="col" class="w-20 px-3 py-2">{{ t('picking.uom') }}</th>
                                    <th scope="col" class="w-12 px-3 py-2"><span class="sr-only">{{ t('picking.actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-borderline">
                                <tr
                                    v-for="(row, rowIndex) in rowsOf(sequence)"
                                    :key="row.uid"
                                    :class="errorFor(row, 'part_id') || errorFor(row, 'qty') ? 'bg-danger/5' : ''"
                                >
                                    <td class="px-3 py-2 text-right align-middle text-xs tabular-nums text-ink-secondary">
                                        <span
                                            v-if="errorFor(row, 'part_id') || errorFor(row, 'qty') || errorFor(row, 'customer_id')"
                                            class="font-bold text-danger"
                                            :title="t('picking.summaryTitle', { count: 1 })"
                                            >!</span
                                        >
                                        <span v-else>{{ rowIndex + 1 }}</span>
                                    </td>

                                    <td class="px-3 py-2 align-middle">
                                        <SearchableSelect
                                            :ref="(el: any) => setFgRef(row.uid, el)"
                                            v-model="row.part_id"
                                            :options="partOptions"
                                            :max-items="8"
                                            size="md"
                                            :placeholder="t('picking.choosePart')"
                                            :aria-label="t('picking.fg')"
                                            :disabled="locked"
                                            :error="errorFor(row, 'part_id')"
                                            @change="() => onPartChange(row)"
                                        >
                                            <template #leading>
                                                <svg class="h-4 w-4 shrink-0 text-ink-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                                                </svg>
                                            </template>
                                        </SearchableSelect>
                                        <InputError :message="errorFor(row, 'part_id')" class="mt-1" />
                                    </td>

                                    <td class="px-3 py-2 align-middle">
                                        <input
                                            :ref="(el: any) => setQtyRef(row.uid, el)"
                                            v-model="row.qty"
                                            type="text"
                                            inputmode="decimal"
                                            :disabled="locked"
                                            :aria-label="t('picking.qty')"
                                            :aria-invalid="errorFor(row, 'qty') ? 'true' : undefined"
                                            :class="[
                                                'h-11 w-full rounded-lg border bg-background px-2 text-right text-sm tabular-nums text-ink-primary focus:ring-2 disabled:opacity-60',
                                                errorFor(row, 'qty')
                                                    ? 'border-danger focus:border-danger focus:ring-danger'
                                                    : 'border-borderline focus:border-primary focus:ring-primary',
                                            ]"
                                            @keydown.enter.prevent="onQtyEnter(row)"
                                            @keydown.alt.delete.prevent="removeRow(row)"
                                        />
                                        <p
                                            v-if="exceedsByRow.get(row.uid)"
                                            class="mt-1 text-[11px] font-medium text-danger"
                                        >
                                            {{ t('picking.exceedsStock') }} (−{{ exceedsByRow.get(row.uid) }})
                                        </p>
                                        <InputError :message="errorFor(row, 'qty')" class="mt-1" />
                                    </td>

                                    <td class="px-3 py-2 align-middle text-xs uppercase text-ink-secondary">
                                        {{ row.qty_unit ?? '—' }}
                                    </td>

                                    <td class="px-3 py-2 align-middle text-right">
                                        <button
                                            type="button"
                                            tabindex="-1"
                                            :disabled="locked || form.items.length <= 1 || rowsOf(sequence).length <= 1"
                                            :aria-label="t('picking.removeRow')"
                                            :title="rowsOf(sequence).length <= 1 ? t('picking.lastRowBlocked') : t('picking.removeRow')"
                                            class="rounded-md px-2 py-1 text-xs font-semibold text-danger transition hover:bg-danger/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger disabled:opacity-30"
                                            @click="removeRow(row)"
                                        >
                                            ✕
                                        </button>
                                    </td>
                                </tr>

                                <tr v-if="!locked">
                                    <td colspan="5" class="px-3 py-2">
                                        <button
                                            type="button"
                                            class="w-full rounded-md border border-dashed border-borderline px-3 py-2 text-xs font-semibold text-ink-secondary transition hover:border-primary hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                            @click="addRow(sequence)"
                                        >
                                            + {{ t('picking.addRow') }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <p v-if="form.items.length > 0" class="mt-2 text-[11px] text-ink-secondary">{{ t('picking.keyHint') }}</p>

                <div v-if="exceedCount > 0" class="mt-3 rounded-lg border border-warning bg-warning/10 px-4 py-3 text-sm text-warning-ink" role="status">
                    {{ t('picking.exceedsSummary', { count: exceedCount }) }}
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div v-if="undoEntry" class="inline-flex items-center gap-2 rounded-md border border-borderline bg-surface px-3 py-1.5 text-sm text-ink-primary">
                    <span role="status" aria-live="polite">{{ undoLabel }}</span>
                    <button
                        type="button"
                        class="rounded px-1.5 py-0.5 font-semibold text-primary underline underline-offset-2 transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        @click="undo"
                    >
                        {{ t('picking.undo') }}
                    </button>
                </div>
                <span v-else></span>

                <button
                    type="submit"
                    :disabled="form.processing || locked"
                    class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface disabled:opacity-60"
                >
                    {{ t('picking.save') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
