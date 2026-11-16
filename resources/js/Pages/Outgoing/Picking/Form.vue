<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';

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
    sequence: number;
    customer_id: number | '';
    part_id: number | '';
    qty: number | '';
    qty_unit: string | null;
}

const props = defineProps<{
    pickingList: PickingList | null;
    customers: CustomerOption[];
    parts: PartOption[];
}>();

const locked = computed(() => props.pickingList !== null && props.pickingList.status !== 'draft');

const backHref = computed(() =>
    props.pickingList ? route('picking-lists.show', props.pickingList.id) : route('picking-lists.index'),
);

const form = useForm({
    picking_date: props.pickingList?.picking_date?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
    notes: props.pickingList?.notes ?? '',
    items: (props.pickingList?.items ?? []).map<Row>((item) => ({
        sequence: item.sequence,
        customer_id: item.customer_id,
        part_id: item.part_id,
        qty: item.qty,
        qty_unit: item.qty_unit,
    })),
});

if (form.items.length === 0) {
    form.items.push({ sequence: 1, customer_id: '', part_id: '', qty: '', qty_unit: null });
}

const partById = computed(() => new Map(props.parts.map((part) => [part.id, part])));

const sequences = computed(() => [...new Set(form.items.map((row) => row.sequence))].sort((a, b) => a - b));

const rowsOf = (sequence: number) => form.items.filter((row) => row.sequence === sequence);

function addSeq() {
    const next = sequences.value.length > 0 ? Math.max(...sequences.value) + 1 : 1;
    form.items.push({ sequence: next, customer_id: '', part_id: '', qty: '', qty_unit: null });
}

function removeSeq(sequence: number) {
    if (!confirm(t('picking.removeSeqConfirm', { n: sequence }))) {
        return;
    }

    form.items = form.items.filter((row) => row.sequence !== sequence);
}

function addRow(sequence: number) {
    form.items.push({ sequence, customer_id: '', part_id: '', qty: '', qty_unit: null });
}

function removeRow(row: Row) {
    form.items = form.items.filter((item) => item !== row);
}

function onPartChange(row: Row, partId: number | null) {
    const part = partId !== null ? partById.value.get(partId) : undefined;
    row.qty_unit = part?.uom ?? null;
}

/** Stok tersedia setelah baris sebelumnya untuk part yang sama dipesan. */
function availableFor(row: Row): number {
    const partId = Number(row.part_id);
    if (!partId) {
        return 0;
    }

    const part = partById.value.get(partId);
    if (!part) {
        return 0;
    }

    let allocated = 0;
    for (const item of form.items) {
        if (item === row) {
            break;
        }
        if (Number(item.part_id) === partId) {
            allocated += Number(item.qty) || 0;
        }
    }

    return Math.max(0, Math.round((part.stock - allocated) * 10000) / 10000);
}

function shortageOf(row: Row): number {
    const qty = Number(row.qty) || 0;
    if (!Number(row.part_id) || qty <= 0) {
        return 0;
    }

    return Math.max(0, Math.round((qty - availableFor(row)) * 10000) / 10000);
}

const hasShortage = computed(() => form.items.some((row) => shortageOf(row) > 0));

const customerOptions = computed(() =>
    props.customers.map((customer) => ({
        value: customer.id,
        label: customer.customer_name,
        sublabel: customer.customer_code,
    })),
);

const partOptions = computed(() =>
    props.parts.map((part) => ({
        value: part.id,
        label: part.part_name,
        sublabel: `${part.part_number}${part.model ? ` · ${part.model}` : ''} · ${t('picking.stock')} ${part.stock}`,
    })),
);

const errorFor = (index: number, field: string): string | undefined =>
    (form.errors as Record<string, string>)[`items.${index}.${field}`];

function submit() {
    if (locked.value) {
        return;
    }

    const payload = {
        picking_date: form.picking_date,
        notes: form.notes,
        items: form.items.map((row) => ({
            sequence: row.sequence,
            customer_id: row.customer_id,
            part_id: row.part_id,
            qty: row.qty,
            qty_unit: row.qty_unit,
        })),
    };

    if (props.pickingList) {
        form.transform(() => payload).put(route('picking-lists.update', props.pickingList.id));
        return;
    }

    form.transform(() => payload).post(route('picking-lists.store'));
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
            class="mb-4 rounded-lg border border-warning bg-warning/10 px-4 py-3 text-sm text-warning-ink"
            role="status"
        >
            {{ t('picking.lockedEdit') }}
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
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-secondary">{{ t('picking.detailSection') }}</h2>
                        <p class="mt-1 text-xs text-ink-secondary">{{ t('picking.stockHint') }}</p>
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

                <InputError :message="form.errors.items" class="mb-3" />

                <div v-if="form.items.length === 0" class="rounded-lg border border-dashed border-borderline px-4 py-8 text-center text-sm text-ink-secondary">
                    {{ t('picking.noRows') }}
                </div>

                <div v-for="sequence in sequences" :key="sequence" class="mb-4 overflow-hidden rounded-lg border border-borderline">
                    <div class="flex items-center justify-between border-b border-borderline bg-background px-4 py-2">
                        <span class="text-sm font-semibold text-ink-primary">{{ t('picking.seqTitle', { n: sequence }) }}</span>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                :disabled="locked"
                                class="rounded-md border border-borderline px-2.5 py-1 text-xs font-semibold text-ink-primary transition hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50"
                                @click="addRow(sequence)"
                            >
                                + {{ t('picking.addRow') }}
                            </button>
                            <button
                                type="button"
                                :disabled="locked"
                                class="rounded-md border border-danger px-2.5 py-1 text-xs font-semibold text-danger transition hover:bg-danger/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger disabled:opacity-50"
                                @click="removeSeq(sequence)"
                            >
                                {{ t('picking.removeSeq') }}
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-borderline text-sm">
                            <thead class="bg-surface">
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                    <th scope="col" class="px-3 py-2 min-w-[14rem]">{{ t('picking.customer') }}</th>
                                    <th scope="col" class="px-3 py-2 min-w-[16rem]">{{ t('picking.fg') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ t('picking.model') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ t('picking.partNo') }}</th>
                                    <th scope="col" class="px-3 py-2 w-28">{{ t('picking.qty') }}</th>
                                    <th scope="col" class="px-3 py-2 w-20">{{ t('picking.uom') }}</th>
                                    <th scope="col" class="px-3 py-2 w-32 text-right">{{ t('picking.stockAvailable') }}</th>
                                    <th scope="col" class="px-3 py-2 w-16"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-borderline">
                                <tr v-for="row in rowsOf(sequence)" :key="form.items.indexOf(row)">
                                    <td class="px-3 py-2 align-top">
                                        <SearchableSelect
                                            v-model="row.customer_id"
                                            :options="customerOptions"
                                            :placeholder="t('picking.chooseCustomer')"
                                            :aria-label="t('picking.customer')"
                                            :disabled="locked"
                                        />
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <SearchableSelect
                                            v-model="row.part_id"
                                            :options="partOptions"
                                            :placeholder="t('picking.choosePart')"
                                            :aria-label="t('picking.fg')"
                                            :disabled="locked"
                                            @change="(value: number | null) => onPartChange(row, value)"
                                        />
                                    </td>
                                    <td class="px-3 py-2 align-top text-ink-secondary">
                                        {{ partById.get(Number(row.part_id))?.model ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 align-top font-mono text-xs text-ink-primary">
                                        {{ partById.get(Number(row.part_id))?.part_number ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <input
                                            v-model="row.qty"
                                            type="number"
                                            min="0"
                                            step="0.0001"
                                            :disabled="locked"
                                            :aria-label="t('picking.qty')"
                                            class="w-full rounded-lg border-borderline bg-background px-2 py-1.5 text-right text-sm tabular-nums text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-60"
                                        />
                                    </td>
                                    <td class="px-3 py-2 align-top text-xs uppercase text-ink-secondary">
                                        {{ row.qty_unit ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 align-top text-right">
                                        <span v-if="!row.part_id" class="text-xs text-ink-secondary">—</span>
                                        <span v-else-if="shortageOf(row) > 0" class="text-xs font-semibold text-danger">
                                            {{ t('picking.shortage') }} {{ shortageOf(row) }}
                                        </span>
                                        <span v-else class="text-xs font-semibold text-success-ink tabular-nums">{{ availableFor(row) }}</span>
                                    </td>
                                    <td class="px-3 py-2 align-top text-right">
                                        <button
                                            type="button"
                                            :disabled="locked || form.items.length === 1"
                                            class="rounded-md px-2 py-1 text-xs font-semibold text-danger transition hover:bg-danger/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger disabled:opacity-30"
                                            @click="removeRow(row)"
                                        >
                                            ✕
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="space-y-1 px-3 pb-3">
                        <p
                            v-for="(row, index) in rowsOf(sequence)"
                            :key="`err-${index}`"
                            class="text-xs text-danger"
                        >
                            <span v-if="errorFor(form.items.indexOf(row), 'customer_id')">{{ errorFor(form.items.indexOf(row), 'customer_id') }}</span>
                            <span v-if="errorFor(form.items.indexOf(row), 'part_id')">{{ errorFor(form.items.indexOf(row), 'part_id') }}</span>
                            <span v-if="errorFor(form.items.indexOf(row), 'qty')">{{ errorFor(form.items.indexOf(row), 'qty') }}</span>
                        </p>
                    </div>
                </div>

                <div v-if="hasShortage" class="mt-3 rounded-lg border border-warning bg-warning/10 px-4 py-3 text-sm text-warning-ink" role="status">
                    <p class="font-semibold">{{ t('picking.stockWarningTitle') }}</p>
                    <p class="mt-0.5">{{ t('picking.stockWarningHint') }}</p>
                </div>
            </section>

            <div class="flex justify-end gap-2">
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
