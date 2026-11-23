<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import Pagination from '@/Components/Pagination.vue';
import { arrivalSenderName, arrivalTypeBadgeClass, arrivalTypeLabel, resolveArrivalType } from '@/utils/arrivalType';
import type { IncomingReceivePacking, PartStock, Paginated } from '@/types';

const { t, locale } = useI18n();

const props = defineProps<{
    stocks: Paginated<PartStock>;
    tab: 'material' | 'wip' | 'fg';
    filters: { search?: string; arrival_type?: string | null };
}>();

const search = ref(props.filters.search ?? '');
const arrivalType = ref(props.filters.arrival_type ?? '');
const expandedInvoices = ref<Record<string, boolean>>({});
const expandedTags = ref<Record<number, boolean>>({});
let timer: ReturnType<typeof setTimeout> | undefined;

const isMaterialTab = computed(() => props.tab === 'material');
const showLocationColumn = computed(() => props.tab === 'material' || props.tab === 'fg');
const tagTableColspan = 11;
const wipFgColumnCount = computed(() => (showLocationColumn.value ? 10 : 9));

function navigate(tab: 'material' | 'wip' | 'fg') {
    expandedInvoices.value = {};
    expandedTags.value = {};
    router.get(
        route('stocks.index'),
        {
            tab,
            search: search.value || undefined,
            arrival_type: arrivalType.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => navigate(props.tab), 300);
}

const fmt = (n: number | null | undefined, decimals = 4) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: decimals });

function invoiceOf(st: PartStock): string | null {
    const raw =
        st.receive?.arrival_item?.arrival?.invoice_no ??
        st.receive?.arrival_item?.arrival?.delivery_note_no ??
        st.receive?.invoice_no ??
        null;
    const trimmed = raw?.trim() ?? '';
    return trimmed !== '' ? trimmed : null;
}

function packingsOf(st: PartStock): IncomingReceivePacking[] {
    return st.receive?.packings ?? [];
}

function hasPackings(st: PartStock): boolean {
    return packingsOf(st).length > 0;
}

function packingCountOf(st: PartStock): number {
    const list = packingsOf(st);
    return list.length > 0 ? list.length : Number(st.receive?.packing_count ?? 0);
}

function packingSummary(st: PartStock): string | null {
    const count = packingCountOf(st);
    if (count <= 0) return null;
    const type = st.receive?.packing_type?.trim();
    return type ? `${fmt(count, 0)} ${type}` : `${fmt(count, 0)} ${t('incoming.packing')}`;
}

function totalPackingQty(st: PartStock): number {
    return packingsOf(st).reduce((sum, p) => sum + Number(p.qty ?? 0), 0);
}

function totalPackingNetWeight(st: PartStock): number | null {
    const list = packingsOf(st).filter((p) => p.net_weight != null);
    if (!list.length) return null;
    return list.reduce((sum, p) => sum + Number(p.net_weight ?? 0), 0);
}

interface UnitSummary {
    unit: string;
    qty: number;
    booked: number;
    available: number;
}

type ArrivalMeta = NonNullable<NonNullable<NonNullable<PartStock['receive']>['arrival_item']>['arrival']>;

interface InvoiceGroup {
    key: string;
    invoiceNo: string | null;
    arrival: ArrivalMeta | null;
    stocks: PartStock[];
    tagCount: number;
    totalPackingCount: number;
    totalsByUnit: UnitSummary[];
}

const invoiceGroups = computed<InvoiceGroup[]>(() => {
    const map = new Map<string, InvoiceGroup>();

    for (const st of props.stocks.data) {
        const inv = invoiceOf(st);
        const key = inv ? `inv:${inv.toUpperCase()}` : '__NO_INVOICE__';
        let group = map.get(key);

        if (!group) {
            group = {
                key,
                invoiceNo: inv,
                arrival: st.receive?.arrival_item?.arrival ?? null,
                stocks: [],
                tagCount: 0,
                totalPackingCount: 0,
                totalsByUnit: [],
            };
            map.set(key, group);
        } else if (!group.arrival && st.receive?.arrival_item?.arrival) {
            group.arrival = st.receive.arrival_item.arrival;
        }

        group.stocks.push(st);
        group.tagCount += 1;
        group.totalPackingCount += packingCountOf(st);
    }

    for (const group of map.values()) {
        const unitMap = new Map<string, UnitSummary>();
        for (const st of group.stocks) {
            const unit = st.qty_unit?.trim() || '—';
            let entry = unitMap.get(unit);
            if (!entry) {
                entry = { unit, qty: 0, booked: 0, available: 0 };
                unitMap.set(unit, entry);
            }
            entry.qty += Number(st.qty ?? 0);
            entry.booked += Number(st.booked_qty ?? 0);
            entry.available += Number(st.avail_qty ?? st.qty ?? 0);
        }
        group.totalsByUnit = Array.from(unitMap.values());
    }

    return Array.from(map.values());
});

// Saat user melakukan pencarian atau hanya ada 1 invoice, buka otomatis supaya hasil langsung terlihat.
watch(
    () => [invoiceGroups.value, props.filters.search] as const,
    ([groups, activeSearch]) => {
        if ((activeSearch && activeSearch.trim() !== '') || groups.length === 1) {
            const nextInvoices: Record<string, boolean> = {};
            for (const g of groups) {
                nextInvoices[g.key] = true;
            }
            expandedInvoices.value = nextInvoices;
        }
    },
    { immediate: true },
);

function isInvoiceOpen(key: string): boolean {
    return Boolean(expandedInvoices.value[key]);
}

function toggleInvoice(key: string) {
    expandedInvoices.value[key] = !expandedInvoices.value[key];
}

function toggleTag(id: number) {
    expandedTags.value[id] = !expandedTags.value[id];
}

const allInvoicesExpanded = computed(
    () => invoiceGroups.value.length > 0 && invoiceGroups.value.every((g) => expandedInvoices.value[g.key]),
);

function toggleAllInvoices() {
    const next = !allInvoicesExpanded.value;
    const map: Record<string, boolean> = {};
    for (const g of invoiceGroups.value) {
        map[g.key] = next;
    }
    expandedInvoices.value = map;
    if (!next) {
        expandedTags.value = {};
    }
}

const expandableTagIds = computed(() =>
    props.stocks.data.filter((st) => hasPackings(st)).map((st) => st.id),
);

const allPackingsExpanded = computed(
    () =>
        allInvoicesExpanded.value &&
        expandableTagIds.value.length > 0 &&
        expandableTagIds.value.every((id) => expandedTags.value[id]),
);

function toggleAllPackings() {
    const next = !allPackingsExpanded.value;
    if (next) {
        const invMap: Record<string, boolean> = {};
        for (const g of invoiceGroups.value) {
            invMap[g.key] = true;
        }
        expandedInvoices.value = invMap;
    }
    const tagMap: Record<number, boolean> = {};
    for (const id of expandableTagIds.value) {
        tagMap[id] = next;
    }
    expandedTags.value = tagMap;
}
</script>

<template>
    <Head :title="t('incoming.stock')" />
    <AppLayout>
        <BackButton :href="route('incoming-data')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.stock') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ isMaterialTab ? t('incoming.clickInvoiceToViewTags') : t('incoming.stockDescription') }}
                </p>
            </div>
            <div class="flex items-center gap-2 rounded-xl border border-borderline bg-surface px-4 py-2 text-sm text-ink-secondary">
                <template v-if="isMaterialTab">
                    <span class="font-semibold tabular-nums text-ink-primary">{{ invoiceGroups.length }}</span>
                    <span>{{ t('incoming.invoiceGroups') }}</span>
                    <span>·</span>
                </template>
                <span>{{ t('incoming.total') }}</span>
                <span class="font-semibold tabular-nums text-ink-primary">{{ stocks.total }}</span>
                <span>{{ t('incoming.stockRows') }}</span>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex rounded-xl border border-borderline bg-surface p-1">
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :class="tab === 'material' ? 'bg-primary text-white' : 'text-ink-secondary hover:bg-primary-light'"
                    @click="navigate('material')"
                >{{ t('incoming.tabMaterialStock') }}</button>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :class="tab === 'wip' ? 'bg-primary text-white' : 'text-ink-secondary hover:bg-primary-light'"
                    @click="navigate('wip')"
                >{{ t('incoming.tabWipStock') }}</button>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :class="tab === 'fg' ? 'bg-primary text-white' : 'text-ink-secondary hover:bg-primary-light'"
                    @click="navigate('fg')"
                >{{ t('incoming.tabFgStock') }}</button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <select
                    v-model="arrivalType"
                    :aria-label="t('incoming.arrivalType')"
                    @change="navigate(props.tab)"
                    class="rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                >
                    <option value="">{{ t('incoming.allArrivalTypes') }}</option>
                    <option value="import">{{ t('incoming.arrivalType_import') }}</option>
                    <option value="local">{{ t('incoming.arrivalType_local') }}</option>
                    <option value="osp_gci">{{ t('incoming.arrivalType_osp_gci') }}</option>
                    <option value="osp_customer">{{ t('incoming.arrivalType_osp_customer') }}</option>
                </select>
                <input
                    v-model="search"
                    @input="doSearch"
                    type="search"
                    :placeholder="t('incoming.searchStock')"
                    :aria-label="t('incoming.searchStock')"
                    class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80"
                />
                <button
                    v-if="isMaterialTab && invoiceGroups.length > 0"
                    type="button"
                    class="rounded-lg border border-borderline bg-surface px-3 py-2 text-xs font-semibold text-ink-secondary transition hover:bg-primary-light hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    @click="toggleAllInvoices"
                >
                    {{ allInvoicesExpanded ? t('incoming.collapseAllInvoices') : t('incoming.expandAllInvoices') }}
                </button>
                <button
                    v-if="isMaterialTab && expandableTagIds.length > 0"
                    type="button"
                    class="rounded-lg border border-borderline bg-surface px-3 py-2 text-xs font-semibold text-ink-secondary transition hover:bg-primary-light hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    @click="toggleAllPackings"
                >
                    {{ allPackingsExpanded ? t('incoming.collapseAllPackings') : t('incoming.expandAllPackings') }}
                </button>
            </div>
        </div>

        <!-- TAB MATERIAL: Hirarki 3 Level (Invoice -> TAG -> Packing) -->
        <div v-if="isMaterialTab" class="space-y-3">
            <div
                v-for="group in invoiceGroups"
                :key="group.key"
                class="overflow-hidden rounded-xl border border-borderline bg-surface transition"
            >
                <!-- LEVEL 1: INDUK INVOICE -->
                <button
                    type="button"
                    :aria-expanded="isInvoiceOpen(group.key)"
                    class="flex w-full flex-wrap items-center justify-between gap-4 px-5 py-4 text-left transition hover:bg-primary-light/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
                    :class="isInvoiceOpen(group.key) ? 'border-b border-borderline bg-primary-light/25' : ''"
                    @click="toggleInvoice(group.key)"
                >
                    <div class="flex min-w-0 items-center gap-3.5">
                        <span
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-borderline bg-surface text-primary transition"
                            :class="isInvoiceOpen(group.key) ? 'border-primary bg-primary text-white' : ''"
                        >
                            <svg
                                class="h-4 w-4 transition-transform duration-150"
                                :class="isInvoiceOpen(group.key) ? 'rotate-90' : ''"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2.2"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                        </span>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-semibold uppercase tracking-wider text-ink-secondary">
                                    {{ t('incoming.invoice') }}
                                </span>
                                <span class="text-base font-bold text-ink-primary">
                                    {{ group.invoiceNo ?? t('incoming.noInvoiceGroup') }}
                                </span>
                                <span
                                    v-if="group.arrival"
                                    :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold', arrivalTypeBadgeClass(resolveArrivalType(group.arrival))]"
                                >
                                    {{ arrivalTypeLabel(group.arrival, t) }}
                                </span>
                            </div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-ink-secondary">
                                <span v-if="group.arrival" class="font-medium text-ink-primary">
                                    {{ arrivalSenderName(group.arrival) }}
                                </span>
                                <span v-if="group.arrival?.arrival_no">· {{ group.arrival.arrival_no }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center gap-1.5">
                            <span class="inline-flex items-center rounded-full bg-primary-light px-3 py-1 text-xs font-bold text-primary">
                                {{ fmt(group.tagCount, 0) }} {{ t('incoming.tag') }}
                            </span>
                            <span
                                v-if="group.totalPackingCount > 0"
                                class="inline-flex items-center rounded-full border border-borderline bg-background px-3 py-1 text-xs font-semibold text-ink-secondary"
                            >
                                {{ fmt(group.totalPackingCount, 0) }} {{ t('incoming.packing') }}
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 border-l border-borderline pl-3 text-xs">
                            <div
                                v-for="u in group.totalsByUnit"
                                :key="u.unit"
                                class="flex items-center gap-3 rounded-lg bg-background px-3 py-1.5"
                            >
                                <div>
                                    <span class="text-ink-secondary">{{ t('incoming.qty') }}: </span>
                                    <span class="font-bold tabular-nums text-ink-primary">{{ fmt(u.qty) }} {{ u.unit }}</span>
                                </div>
                                <div v-if="u.booked > 0">
                                    <span class="text-ink-secondary">{{ t('incoming.booked') }}: </span>
                                    <span class="font-semibold tabular-nums text-warning">{{ fmt(u.booked) }}</span>
                                </div>
                                <div>
                                    <span class="text-ink-secondary">{{ t('incoming.available') }}: </span>
                                    <span class="font-bold tabular-nums" :class="u.available > 0 ? 'text-success' : 'text-ink-secondary'">
                                        {{ fmt(u.available) }} {{ u.unit }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </button>

                <!-- LEVEL 2: DAFTAR TAG DI DALAM INVOICE -->
                <div v-if="isInvoiceOpen(group.key)" class="bg-background/40 px-4 py-3 sm:px-6">
                    <div class="ml-2 border-l-2 border-primary/40 pl-3 sm:ml-3 sm:pl-4">
                        <div class="overflow-x-auto rounded-lg border border-borderline bg-surface">
                            <table class="min-w-full divide-y divide-borderline text-sm">
                                <thead class="bg-background">
                                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                        <th scope="col" class="px-4 py-2.5">{{ t('incoming.tag') }}</th>
                                        <th scope="col" class="px-4 py-2.5">{{ t('incoming.part') }}</th>
                                        <th scope="col" class="px-4 py-2.5">{{ t('master.model') }}</th>
                                        <th scope="col" class="px-4 py-2.5">{{ t('incoming.type') }}</th>
                                        <th scope="col" class="px-4 py-2.5">{{ t('incoming.packing') }}</th>
                                        <th scope="col" class="px-4 py-2.5">{{ t('incoming.location') }}</th>
                                        <th scope="col" class="px-4 py-2.5 text-right">{{ t('incoming.qty') }}</th>
                                        <th scope="col" class="px-4 py-2.5 text-right">{{ t('incoming.booked') }}</th>
                                        <th scope="col" class="px-4 py-2.5 text-right">{{ t('incoming.available') }}</th>
                                        <th scope="col" class="px-4 py-2.5">{{ t('incoming.unit') }}</th>
                                        <th scope="col" class="px-4 py-2.5 text-right">{{ t('incoming.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-borderline">
                                    <template v-for="st in group.stocks" :key="st.id">
                                        <tr
                                            class="transition hover:bg-primary-light/40"
                                            :class="hasPackings(st) ? 'cursor-pointer' : ''"
                                            @click="hasPackings(st) ? toggleTag(st.id) : undefined"
                                        >
                                            <td class="px-4 py-3">
                                                <div class="flex items-start gap-2">
                                                    <span class="mt-0.5 select-none font-bold text-primary" aria-hidden="true">↳</span>
                                                    <div>
                                                        <div class="font-semibold text-ink-primary">{{ st.tag ?? '—' }}</div>
                                                        <div v-if="st.receive?.bundle_unit" class="text-[11px] uppercase text-ink-secondary">
                                                            1 {{ st.receive.bundle_unit }}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="font-medium text-ink-primary">{{ st.part?.part_number ?? '—' }}</div>
                                                <div class="text-xs text-ink-secondary">{{ st.part?.part_name ?? '' }}</div>
                                            </td>
                                            <td class="px-4 py-3 text-ink-secondary">{{ st.part?.model || '—' }}</td>
                                            <td class="px-4 py-3 text-ink-primary">{{ st.part?.part_type?.name ?? '—' }}</td>
                                            <td class="px-4 py-3" @click.stop>
                                                <button
                                                    v-if="hasPackings(st)"
                                                    type="button"
                                                    :aria-expanded="Boolean(expandedTags[st.id])"
                                                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border border-primary/30 bg-primary-light px-2.5 py-1 text-xs font-semibold text-primary transition hover:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                                    @click="toggleTag(st.id)"
                                                >
                                                    <span>{{ packingSummary(st) }}</span>
                                                    <svg
                                                        class="h-3.5 w-3.5 shrink-0 transition-transform"
                                                        :class="expandedTags[st.id] ? 'rotate-180' : ''"
                                                        fill="none"
                                                        viewBox="0 0 24 24"
                                                        stroke="currentColor"
                                                        stroke-width="2"
                                                    >
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                    </svg>
                                                </button>
                                                <span
                                                    v-else-if="packingSummary(st)"
                                                    class="inline-flex whitespace-nowrap rounded-full bg-background px-2.5 py-1 text-xs font-medium text-ink-secondary"
                                                >
                                                    {{ packingSummary(st) }}
                                                </span>
                                                <span v-else class="text-ink-secondary">—</span>
                                            </td>
                                            <td class="px-4 py-3 text-ink-primary">
                                                {{ st.receive?.location_code ?? st.location_code ?? t('incoming.locationNone') }}
                                            </td>
                                            <td class="px-4 py-3 text-right tabular-nums font-semibold text-ink-primary">{{ fmt(st.qty) }}</td>
                                            <td class="px-4 py-3 text-right tabular-nums text-warning">{{ fmt(st.booked_qty ?? 0) }}</td>
                                            <td class="px-4 py-3 text-right tabular-nums font-semibold" :class="(st.avail_qty ?? 0) > 0 ? 'text-success' : 'text-ink-secondary'">
                                                {{ fmt(st.avail_qty ?? st.qty) }}
                                            </td>
                                            <td class="px-4 py-3 text-ink-primary">{{ st.qty_unit ?? '—' }}</td>
                                            <td class="px-4 py-3 text-right" @click.stop>
                                                <a
                                                    v-if="st.receive?.id"
                                                    :href="route('receive.label', st.receive.id)"
                                                    target="_blank"
                                                    rel="noopener"
                                                    :aria-label="`${t('incoming.printLabel')} ${st.tag ?? ''}`.trim()"
                                                    class="inline-flex rounded-md border border-borderline px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                                >
                                                    {{ t('incoming.label') }}
                                                </a>
                                                <span v-else class="text-xs text-ink-secondary">—</span>
                                            </td>
                                        </tr>

                                        <!-- LEVEL 3: RINCIAN PACKING DI DALAM TAG -->
                                        <tr v-if="expandedTags[st.id] && hasPackings(st)" class="bg-background/80">
                                            <td :colspan="tagTableColspan" class="px-6 py-3">
                                                <div class="ml-4 border-l-2 border-primary/30 pl-4">
                                                    <div class="overflow-hidden rounded-lg border border-borderline bg-surface">
                                                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-borderline bg-primary-light/20 px-4 py-2 text-xs">
                                                            <span class="flex items-center gap-1.5 font-semibold text-ink-primary">
                                                                <span class="font-bold text-primary" aria-hidden="true">↳</span>
                                                                {{ t('incoming.packingDetailTitle', { tag: st.tag ?? '—' }) }}
                                                            </span>
                                                            <span class="text-ink-secondary">
                                                                {{ packingSummary(st) }} · {{ t('incoming.qty') }}:
                                                                <span class="font-semibold tabular-nums text-ink-primary">{{ fmt(totalPackingQty(st)) }} {{ st.qty_unit ?? '' }}</span>
                                                                <template v-if="totalPackingNetWeight(st) !== null">
                                                                    · {{ t('incoming.netWeight') }}:
                                                                    <span class="font-semibold tabular-nums text-ink-primary">{{ fmt(totalPackingNetWeight(st)) }} KGM</span>
                                                                </template>
                                                            </span>
                                                        </div>
                                                        <table class="min-w-full divide-y divide-borderline text-xs">
                                                            <thead class="bg-background">
                                                                <tr class="text-left font-semibold uppercase tracking-wide text-ink-secondary">
                                                                    <th scope="col" class="px-4 py-2">#</th>
                                                                    <th scope="col" class="px-4 py-2">{{ t('incoming.packingNo') }}</th>
                                                                    <th scope="col" class="px-4 py-2">{{ t('incoming.packingType') }}</th>
                                                                    <th scope="col" class="px-4 py-2 text-right">{{ t('incoming.qty') }}</th>
                                                                    <th scope="col" class="px-4 py-2 text-right">{{ t('incoming.netWeightColumn') }}</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-borderline">
                                                                <tr v-for="p in packingsOf(st)" :key="p.id">
                                                                    <td class="px-4 py-2 tabular-nums text-ink-secondary">{{ p.sequence }}</td>
                                                                    <td class="px-4 py-2 font-medium text-ink-primary">{{ p.packing_no || '—' }}</td>
                                                                    <td class="px-4 py-2 text-ink-secondary">{{ st.receive?.packing_type || '—' }}</td>
                                                                    <td class="px-4 py-2 text-right tabular-nums font-semibold text-ink-primary">
                                                                        {{ fmt(p.qty) }} {{ p.uom || st.qty_unit || '' }}
                                                                    </td>
                                                                    <td class="px-4 py-2 text-right tabular-nums text-ink-secondary">
                                                                        {{ p.net_weight != null ? fmt(p.net_weight) : '—' }}
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="!invoiceGroups.length"
                class="rounded-xl border border-borderline bg-surface px-4 py-12 text-center text-sm text-ink-secondary"
            >
                {{ t('incoming.noStock') }}
            </div>
        </div>

        <!-- TAB WIP & FG -->
        <div v-else class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('incoming.part') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.model') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('incoming.type') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('incoming.tag') }}</th>
                        <th v-if="showLocationColumn" scope="col" class="px-4 py-3">{{ t('incoming.location') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.qty') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.booked') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.available') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('incoming.unit') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="st in stocks.data" :key="st.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3">
                            <div class="font-medium text-ink-primary">{{ st.part?.part_number ?? '—' }}</div>
                            <div class="text-xs text-ink-secondary">{{ st.part?.part_name ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-ink-secondary">{{ st.part?.model || '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">
                            <div>{{ st.part?.part_type?.name ?? '—' }}</div>
                            <span
                                v-if="st.receive?.arrival_item?.arrival"
                                :class="['mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold', arrivalTypeBadgeClass(resolveArrivalType(st.receive.arrival_item.arrival))]"
                            >
                                {{ arrivalTypeLabel(st.receive.arrival_item.arrival, t) }} · {{ arrivalSenderName(st.receive.arrival_item.arrival) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ st.tag ?? '—' }}</td>
                        <td v-if="showLocationColumn" class="px-4 py-3 text-ink-primary">
                            {{ st.receive?.location_code ?? st.location_code ?? t('incoming.locationNone') }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-ink-primary">{{ fmt(st.qty) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-warning">{{ fmt(st.booked_qty ?? 0) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold" :class="(st.avail_qty ?? 0) > 0 ? 'text-success' : 'text-ink-secondary'">
                            {{ fmt(st.avail_qty ?? st.qty) }}
                        </td>
                        <td class="px-4 py-3 text-ink-primary">{{ st.qty_unit ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a
                                v-if="st.receive?.id"
                                :href="route('receive.label', st.receive.id)"
                                target="_blank"
                                rel="noopener"
                                :aria-label="`${t('incoming.printLabel')} ${st.tag ?? ''}`.trim()"
                                class="inline-flex rounded-md border border-borderline px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                            >
                                {{ t('incoming.label') }}
                            </a>
                            <span v-else class="text-xs text-ink-secondary">—</span>
                        </td>
                    </tr>
                    <tr v-if="!stocks.data.length">
                        <td :colspan="wipFgColumnCount" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noStock') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="stocks.links" />
    </AppLayout>
</template>
