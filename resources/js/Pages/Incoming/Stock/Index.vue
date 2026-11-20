<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import Pagination from '@/Components/Pagination.vue';
import type { IncomingReceivePacking, PartStock, Paginated } from '@/types';

const { t, locale } = useI18n();

const props = defineProps<{
    stocks: Paginated<PartStock>;
    tab: 'material' | 'wip' | 'fg';
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
const expanded = ref<Record<number, boolean>>({});
let timer: ReturnType<typeof setTimeout> | undefined;

const isMaterialTab = computed(() => props.tab === 'material');
const showLocationColumn = computed(() => props.tab === 'material' || props.tab === 'fg');
const columnCount = computed(() => (isMaterialTab.value ? 13 : showLocationColumn.value ? 10 : 9));

function navigate(tab: 'material' | 'wip' | 'fg') {
    expanded.value = {};
    router.get(route('stocks.index'), { tab, search: search.value || undefined }, { preserveState: true, replace: true });
}

function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => navigate(props.tab), 300);
}

const fmt = (n: number | null | undefined, decimals = 4) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: decimals });

function packingsOf(st: PartStock): IncomingReceivePacking[] {
    return st.receive?.packings ?? [];
}

function hasPackings(st: PartStock): boolean {
    return packingsOf(st).length > 0;
}

function packingSummary(st: PartStock): string | null {
    const list = packingsOf(st);
    const count = list.length > 0 ? list.length : Number(st.receive?.packing_count ?? 0);
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

function toggleExpand(id: number) {
    expanded.value[id] = !expanded.value[id];
}

const expandableIds = computed(() =>
    props.stocks.data.filter((st) => hasPackings(st)).map((st) => st.id),
);

const allExpanded = computed(() =>
    expandableIds.value.length > 0 && expandableIds.value.every((id) => expanded.value[id]),
);

function toggleAll() {
    const next = !allExpanded.value;
    const map: Record<number, boolean> = {};
    for (const id of expandableIds.value) {
        map[id] = next;
    }
    expanded.value = map;
}
</script>

<template>
    <Head :title="t('incoming.stock')" />
    <AppLayout>
        <BackButton :href="route('incoming-data')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.stock') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('incoming.stockDescription') }}</p>
            </div>
            <div class="rounded-xl border border-borderline bg-surface px-4 py-2 text-sm text-ink-secondary">
                {{ t('incoming.total') }} <span class="font-semibold tabular-nums text-ink-primary">{{ stocks.total }}</span> {{ t('incoming.stockRows') }}
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
                <input
                    v-model="search"
                    @input="doSearch"
                    type="search"
                    :placeholder="t('incoming.searchStock')"
                    :aria-label="t('incoming.searchStock')"
                    class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80"
                />
                <button
                    v-if="isMaterialTab && expandableIds.length > 0"
                    type="button"
                    class="rounded-lg border border-borderline bg-surface px-3 py-2 text-xs font-semibold text-ink-secondary transition hover:bg-primary-light hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    @click="toggleAll"
                >
                    {{ allExpanded ? t('incoming.collapseAllPackings') : t('incoming.expandAllPackings') }}
                </button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('incoming.part') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.model') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('incoming.type') }}</th>
                        <th v-if="isMaterialTab" scope="col" class="px-4 py-3">{{ t('incoming.invoice') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('incoming.tag') }}</th>
                        <th v-if="isMaterialTab" scope="col" class="px-4 py-3">{{ t('incoming.packing') }}</th>
                        <th v-if="showLocationColumn" scope="col" class="px-4 py-3">{{ t('incoming.location') }}</th>
                        <th v-if="isMaterialTab" scope="col" class="px-4 py-3">{{ t('incoming.supplier') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.qty') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.booked') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.available') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('incoming.unit') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <template v-for="st in stocks.data" :key="st.id">
                        <tr class="hover:bg-primary-light/40">
                            <td class="px-4 py-3">
                                <div class="font-medium text-ink-primary">{{ st.part?.part_number ?? '—' }}</div>
                                <div class="text-xs text-ink-secondary">{{ st.part?.part_name ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 text-ink-secondary">{{ st.part?.model || '—' }}</td>
                            <td class="px-4 py-3 text-ink-primary">{{ st.part?.part_type?.name ?? '—' }}</td>
                            <td v-if="isMaterialTab" class="px-4 py-3 text-ink-primary">
                                {{ st.receive?.arrival_item?.arrival?.invoice_no ?? st.receive?.invoice_no ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-ink-primary">{{ st.tag ?? '—' }}</div>
                                <div v-if="isMaterialTab && st.receive?.bundle_unit" class="text-[11px] uppercase text-ink-secondary">
                                    1 {{ st.receive.bundle_unit }}
                                </div>
                            </td>
                            <td v-if="isMaterialTab" class="px-4 py-3">
                                <button
                                    v-if="hasPackings(st)"
                                    type="button"
                                    :aria-expanded="Boolean(expanded[st.id])"
                                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border border-primary/30 bg-primary-light px-2.5 py-1 text-xs font-semibold text-primary transition hover:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    @click="toggleExpand(st.id)"
                                >
                                    <span>{{ packingSummary(st) }}</span>
                                    <svg
                                        class="h-3.5 w-3.5 shrink-0 transition-transform"
                                        :class="expanded[st.id] ? 'rotate-180' : ''"
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
                            <td v-if="showLocationColumn" class="px-4 py-3 text-ink-primary">
                                {{ st.receive?.location_code ?? st.location_code ?? t('incoming.locationNone') }}
                            </td>
                            <td v-if="isMaterialTab" class="px-4 py-3 text-ink-primary">
                                {{ st.receive?.arrival_item?.arrival?.supplier?.supplier_name ?? '—' }}
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

                        <tr v-if="isMaterialTab && expanded[st.id] && hasPackings(st)" class="bg-background/70">
                            <td :colspan="columnCount" class="px-6 py-3">
                                <div class="rounded-lg border border-borderline bg-surface">
                                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-borderline px-4 py-2 text-xs">
                                        <span class="font-semibold text-ink-primary">
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
                            </td>
                        </tr>
                    </template>

                    <tr v-if="!stocks.data.length">
                        <td :colspan="columnCount" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noStock') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="stocks.links" />
    </AppLayout>
</template>
