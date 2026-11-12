<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { formatDate } from '@/utils/date';

const { t } = useI18n();

interface TagOption {
    part_stock_id: number;
    part_id: number;
    part_number: string | null;
    part_name: string | null;
    tag: string;
    qty: number;
    qty_unit: string | null;
    location_code: string | null;
    received_at: string | null;
    in_prep: boolean;
}

interface DeliveryItem {
    id: number;
    part_stock_id: number;
    tag: string | null;
    qty: number;
    qty_unit: string | null;
    origin_location_code: string | null;
    part?: { id: number; part_number: string; part_name: string } | null;
}

interface Delivery {
    id: number;
    delivery_no: string;
    customer_id: number;
    delivery_date: string;
    status: string;
    truck_no: string | null;
    driver_name: string | null;
    notes: string | null;
    customer?: { id: number; customer_code: string; customer_name: string; address: string | null } | null;
    items?: DeliveryItem[];
}

interface Row {
    uid: string;
    part_stock_id: number | '';
    qty: number | '';
}

const props = defineProps<{
    delivery: Delivery | null;
    customers: Array<{ id: number; customer_code: string; customer_name: string; address: string | null }>;
    parts: Array<{ id: number; part_number: string; part_name: string }>;
    suggestedTags: TagOption[];
    prepLocation: string | null;
}>();

const backHref = () => (props.delivery ? route('deliveries.show', props.delivery.id) : route('deliveries.index'));

const form = useForm({
    customer_id: (props.delivery?.customer_id ?? '') as number | '',
    delivery_date: props.delivery?.delivery_date?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
    truck_no: props.delivery?.truck_no ?? '',
    driver_name: props.delivery?.driver_name ?? '',
    notes: props.delivery?.notes ?? '',
    items: [] as any[],
});

let seq = 0;
const nextUid = () => `row-${++seq}`;

const rows = ref<Row[]>(
    props.delivery?.items?.length
        ? props.delivery.items.map((item) => ({
              uid: nextUid(),
              part_stock_id: item.part_stock_id,
              qty: item.qty,
          }))
        : [{ uid: nextUid(), part_stock_id: '', qty: '' }],
);

/** Tag yang sudah dipakai baris lain tidak ditawarkan lagi. */
function optionsFor(row: Row) {
    const used = rows.value
        .filter((r) => r.uid !== row.uid && r.part_stock_id !== '')
        .map((r) => Number(r.part_stock_id));

    return props.suggestedTags
        .filter((tag) => !used.includes(tag.part_stock_id))
        .map((tag) => ({
            value: tag.part_stock_id,
            label: `${tag.part_number ?? '—'} · ${tag.tag}`,
            sublabel: `${Number(tag.qty).toLocaleString()} ${tag.qty_unit ?? ''} · ${tag.location_code ?? '—'}${tag.in_prep ? ` · ${t('outgoing.inPrep')}` : ''}`,
            raw: tag,
        }));
}

const selectedTag = (row: Row): TagOption | undefined =>
    props.suggestedTags.find((tag) => tag.part_stock_id === Number(row.part_stock_id));

/** Saat tag dipilih, qty default = sisa stok tag (boleh dikurangi). */
function onPickTag(row: Row, value: number | '') {
    row.part_stock_id = value === '' ? '' : Number(value);
    const tag = selectedTag(row);
    if (tag) {
        row.qty = tag.qty;
    }
}

function addRow() {
    rows.value.push({ uid: nextUid(), part_stock_id: '', qty: '' });
}

function removeRow(index: number) {
    if (rows.value.length > 1) {
        rows.value.splice(index, 1);
    }
}

/** Saran FIFO: tag tertua untuk part yang sama. */
function applyFifo(row: Row) {
    const current = selectedTag(row);
    if (!current) return;

    const oldest = props.suggestedTags
        .filter((tag) => tag.part_id === current.part_id && !tag.in_prep)
        .sort((a, b) => String(a.received_at ?? '').localeCompare(String(b.received_at ?? '')))[0];

    if (oldest) {
        onPickTag(row, oldest.part_stock_id);
    }
}

const totalRows = computed(() => rows.value.filter((r) => r.part_stock_id !== '').length);

function submit() {
    form.items = rows.value
        .filter((r) => r.part_stock_id !== '')
        .map((r) => ({
            part_stock_id: Number(r.part_stock_id),
            qty: r.qty === '' ? 0 : Number(r.qty),
        })) as any;

    if (props.delivery) {
        form.put(route('deliveries.update', props.delivery.id));
    } else {
        form.post(route('deliveries.store'));
    }
}

function removeDelivery() {
    if (!props.delivery) return;
    if (confirm(t('outgoing.deleteConfirm', { no: props.delivery.delivery_no }))) {
        router.delete(route('deliveries.destroy', props.delivery.id));
    }
}
</script>

<template>
    <Head :title="delivery ? t('outgoing.editTitle', { no: delivery.delivery_no }) : t('outgoing.newTitle')" />
    <AppLayout>
        <BackButton :href="backHref()" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">
                    {{ delivery ? t('outgoing.editTitle', { no: delivery.delivery_no }) : t('outgoing.newTitle') }}
                </h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('outgoing.formSubtitle') }}</p>
            </div>
            <p v-if="prepLocation" class="rounded-lg border border-borderline bg-surface px-3 py-2 text-xs text-ink-secondary">
                {{ t('outgoing.prepLocation') }}: <span class="font-mono font-semibold text-ink-primary">{{ prepLocation }}</span>
            </p>
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <!-- Header dokumen -->
            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.customer') }} <span class="text-danger">*</span></label>
                        <div class="mt-1">
                            <SearchableSelect
                                v-model="form.customer_id"
                                :options="customers"
                                value-key="id"
                                label-key="customer_name"
                                sublabel-key="customer_code"
                                :placeholder="t('outgoing.chooseCustomer')"
                                :aria-label="t('outgoing.customer')"
                                :error="form.errors.customer_id"
                            />
                        </div>
                        <InputError :message="form.errors.customer_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.deliveryDate') }} <span class="text-danger">*</span></label>
                        <input v-model="form.delivery_date" type="date" :aria-label="t('outgoing.deliveryDate')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.delivery_date" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.truckNo') }}</label>
                        <input v-model="form.truck_no" type="text" placeholder="B 9123 GCI" :aria-label="t('outgoing.truckNo')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.driverName') }}</label>
                        <input v-model="form.driver_name" type="text" :aria-label="t('outgoing.driverName')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div class="lg:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.notes') }}</label>
                        <input v-model="form.notes" type="text" :aria-label="t('outgoing.notes')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                </div>
            </div>

            <!-- Baris barang -->
            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold text-ink-primary">{{ t('outgoing.items') }}</h2>
                        <p class="text-xs text-ink-secondary">{{ t('outgoing.itemsHint') }}</p>
                    </div>
                    <button type="button" @click="addRow" class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-sm font-medium text-primary transition hover:bg-primary-light">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        {{ t('outgoing.addRow') }}
                    </button>
                </div>

                <InputError :message="form.errors.items" class="mb-3" />
                <p v-if="!suggestedTags.length" class="mb-3 rounded-lg bg-warning/10 px-3 py-2 text-xs font-medium text-warning">
                    {{ t('outgoing.noStock') }}
                </p>

                <div class="space-y-3">
                    <div v-for="(row, index) in rows" :key="row.uid" class="rounded-xl border border-borderline bg-background/40 p-4">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="inline-flex items-center gap-2 text-xs font-semibold text-ink-secondary">
                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-primary/10 text-[11px] font-bold text-primary">{{ index + 1 }}</span>
                                {{ t('outgoing.pickTag') }}
                            </span>
                            <button
                                type="button"
                                @click="removeRow(index)"
                                :disabled="rows.length <= 1"
                                class="rounded px-2 py-1 text-xs font-medium text-danger transition hover:bg-danger/10 disabled:opacity-30"
                            >
                                {{ t('outgoing.removeRow') }}
                            </button>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-12">
                            <div class="sm:col-span-8">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.tag') }}</label>
                                <div class="mt-1">
                                    <SearchableSelect
                                        :model-value="row.part_stock_id"
                                        :options="optionsFor(row)"
                                        value-key="value"
                                        label-key="label"
                                        sublabel-key="sublabel"
                                        :placeholder="t('outgoing.chooseTag')"
                                        :aria-label="t('outgoing.tag')"
                                        @update:model-value="onPickTag(row, $event)"
                                    />
                                </div>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.qty') }}</label>
                                <input
                                    v-model="row.qty"
                                    type="number"
                                    step="any"
                                    min="0.0001"
                                    :aria-label="t('outgoing.qty')"
                                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-semibold text-ink-primary focus:border-primary focus:ring-primary"
                                />
                            </div>
                            <div class="flex items-end sm:col-span-2">
                                <button
                                    type="button"
                                    @click="applyFifo(row)"
                                    :disabled="!row.part_stock_id"
                                    class="w-full rounded-lg border border-borderline px-3 py-2 text-xs font-semibold text-primary transition hover:bg-primary-light disabled:opacity-40"
                                    :title="t('outgoing.fifoTip')"
                                >
                                    FIFO
                                </button>
                            </div>
                        </div>

                        <div v-if="selectedTag(row)" class="mt-2 flex flex-wrap gap-3 text-[11px] text-ink-secondary">
                            <span>{{ t('outgoing.available') }}: <span class="font-semibold text-ink-primary">{{ Number(selectedTag(row)!.qty).toLocaleString() }} {{ selectedTag(row)!.qty_unit ?? '' }}</span></span>
                            <span>{{ t('outgoing.location') }}: <span class="font-mono text-ink-primary">{{ selectedTag(row)!.location_code ?? '—' }}</span></span>
                            <span v-if="selectedTag(row)!.received_at">{{ t('outgoing.receivedAt') }}: {{ formatDate(selectedTag(row)!.received_at) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between gap-3">
                <button
                    v-if="delivery"
                    type="button"
                    @click="removeDelivery"
                    class="rounded-md border border-danger px-4 py-2 text-sm font-medium text-danger transition hover:bg-danger/10"
                >
                    {{ t('outgoing.delete') }}
                </button>
                <div class="ml-auto flex items-center gap-3">
                    <BackButton :href="backHref()">{{ t('outgoing.cancel') }}</BackButton>
                    <button
                        type="submit"
                        :disabled="form.processing || totalRows === 0"
                        class="rounded-md bg-primary px-5 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
                    >
                        {{ form.processing ? t('outgoing.saving') : t('outgoing.save') }}
                    </button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>