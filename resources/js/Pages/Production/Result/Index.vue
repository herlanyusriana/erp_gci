<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { WorkOrder, Paginated } from '@/types';

type StepDetail = {
    parent_part_id: number;
    part_number: string | null;
    part_name: string | null;
    qty_good: number;
    qty_reject: number;
    machines: string[];
    last_date: string | null;
};

type WoDetail = {
    qty_good: number;
    qty_reject: number;
    performance_pct: number | null;
    last_produced_date: string | null;
    steps: StepDetail[];
};

type Row = WorkOrder & { detail?: WoDetail | null };

const { t, locale } = useI18n();

const props = defineProps<{
    workOrders: Paginated<Row>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('production-results.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
}

const fmt = (n: number | null | undefined) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 2 });
const pct = (n: number | null | undefined) => (n == null ? '—' : `${Number(n).toLocaleString(locale.value, { maximumFractionDigits: 1 })}%`);

/**
 * Tabel dikelompokkan per Part FG dengan subtotal WO Qty dan Produced Qty,
 * sesuai catatan review: satu part FG bisa punya beberapa WO berjalan.
 */
const groups = computed(() => {
    const map = new Map<string, { part_number: string; part_name: string; rows: Row[]; woQty: number; produced: number }>();

    for (const wo of props.workOrders.data) {
        const key = wo.part?.part_number ?? '—';
        if (!map.has(key)) {
            map.set(key, {
                part_number: key,
                part_name: wo.part?.part_name ?? '',
                rows: [],
                woQty: 0,
                produced: 0,
            });
        }
        const g = map.get(key)!;
        g.rows.push(wo);
        g.woQty += Number(wo.qty ?? 0);
        g.produced += Number(wo.fg_produced ?? 0);
    }

    return [...map.values()].sort((a, b) => a.part_number.localeCompare(b.part_number));
});

const detailOpen = ref(false);
const detailRow = ref<Row | null>(null);

function openDetail(row: Row) {
    detailRow.value = row;
    detailOpen.value = true;
}

function closeDetail() {
    detailOpen.value = false;
    detailRow.value = null;
}
</script>

<template>
    <AppLayout>
        <Head :title="t('production.results')" />
        <BackButton :href="route('production-data')" class="mb-4" />

        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.results') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('production.resultsIndexDescription') }}</p>
            </div>
        </div>

        <input v-model="search" @input="doSearch" type="search" :placeholder="t('production.searchWo')" :aria-label="t('production.searchWo')" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <div class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('production.woNo') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.woQty') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.fgProduced') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.item') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <template v-for="(group, gi) in groups" :key="group.part_number">
                        <!-- Header grup Part FG -->
                        <tr class="bg-background" :class="gi > 0 ? 'border-t-2 border-t-ink-secondary/30' : ''">
                            <td colspan="6" class="px-4 py-2.5">
                                <div class="flex flex-wrap items-baseline gap-2">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.fgGroup') }}</span>
                                    <span class="font-semibold text-ink-primary">{{ group.part_number }}</span>
                                    <span class="text-xs text-ink-secondary">{{ group.part_name }}</span>
                                    <span class="ml-auto text-xs text-ink-secondary">{{ t('production.woCount', { n: group.rows.length }) }}</span>
                                </div>
                            </td>
                        </tr>

                        <tr v-for="wo in group.rows" :key="wo.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3">
                                <Link :href="route('work-orders.show', wo.id)" class="font-semibold text-ink-primary hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ wo.wo_no }}</Link>
                                <div v-if="wo.detail?.last_produced_date" class="text-xs text-ink-secondary">{{ t('production.producedAt') }} {{ wo.detail.last_produced_date }}</div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-secondary">{{ fmt(wo.qty) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold" :class="Number(wo.fg_produced ?? 0) >= Number(wo.qty) ? 'text-success' : 'text-warning'">
                                {{ fmt(wo.fg_produced ?? 0) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-secondary">{{ wo.results_count ?? 0 }}</td>
                            <td class="px-4 py-3">
                                <StatusBadge uppercase :status="wo.status">{{ wo.status }}</StatusBadge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button
                                        type="button"
                                        :title="t('production.details')"
                                        :aria-label="t('production.details')"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-borderline text-ink-secondary transition hover:bg-primary-light hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                        @click="openDetail(wo)"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                    </button>
                                    <Link :href="route('production-results.create', wo.id)" class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        {{ t('production.report') }}
                                    </Link>
                                </div>
                            </td>
                        </tr>

                        <!-- Subtotal per Part FG -->
                        <tr class="bg-background font-semibold">
                            <td class="px-4 py-2.5 text-xs uppercase tracking-wide text-ink-secondary">{{ t('production.subtotal') }} {{ group.part_number }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-ink-primary">{{ fmt(group.woQty) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums" :class="group.produced >= group.woQty ? 'text-success' : 'text-warning'">{{ fmt(group.produced) }}</td>
                            <td colspan="3"></td>
                        </tr>
                    </template>

                    <tr v-if="workOrders.data.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('production.noInProgressWo') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="workOrders.links" />

        <!-- Modal detail per WO -->
        <Modal :show="detailOpen" max-width="2xl" @close="closeDetail">
            <div class="p-6">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-ink-primary">{{ detailRow?.wo_no }}</h2>
                        <p class="text-sm text-ink-secondary">{{ detailRow?.part?.part_number }} · {{ detailRow?.part?.part_name }}</p>
                    </div>
                    <button type="button" class="rounded-md p-1.5 text-ink-secondary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" :aria-label="t('common.close')" @click="closeDetail">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <dl class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-lg border border-borderline bg-background p-3">
                        <dt class="text-xs text-ink-secondary">{{ t('production.good') }}</dt>
                        <dd class="tabular-nums font-semibold text-success">{{ fmt(detailRow?.detail?.qty_good) }}</dd>
                    </div>
                    <div class="rounded-lg border border-borderline bg-background p-3">
                        <dt class="text-xs text-ink-secondary">{{ t('production.reject') }}</dt>
                        <dd class="tabular-nums font-semibold text-danger">{{ fmt(detailRow?.detail?.qty_reject) }}</dd>
                    </div>
                    <div class="rounded-lg border border-borderline bg-background p-3">
                        <dt class="text-xs text-ink-secondary">{{ t('production.performance') }}</dt>
                        <dd class="tabular-nums font-semibold text-ink-primary">{{ pct(detailRow?.detail?.performance_pct) }}</dd>
                    </div>
                    <div class="rounded-lg border border-borderline bg-background p-3">
                        <dt class="text-xs text-ink-secondary">{{ t('production.producedAt') }}</dt>
                        <dd class="tabular-nums font-semibold text-ink-primary">{{ detailRow?.detail?.last_produced_date ?? '—' }}</dd>
                    </div>
                </dl>

                <h3 class="mb-2 text-sm font-semibold text-ink-primary">{{ t('production.resultPerWip') }}</h3>
                <div class="overflow-x-auto rounded-lg border border-borderline">
                    <table class="min-w-full divide-y divide-borderline text-sm">
                        <thead class="bg-background">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                <th scope="col" class="px-3 py-2">{{ t('production.wipStep') }}</th>
                                <th scope="col" class="px-3 py-2 text-right">{{ t('production.good') }}</th>
                                <th scope="col" class="px-3 py-2 text-right">{{ t('production.reject') }}</th>
                                <th scope="col" class="px-3 py-2">{{ t('production.machine') }}</th>
                                <th scope="col" class="px-3 py-2">{{ t('production.producedAt') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-borderline">
                            <tr v-for="step in detailRow?.detail?.steps ?? []" :key="step.parent_part_id">
                                <td class="px-3 py-2">
                                    <div class="font-medium text-ink-primary">{{ step.part_number ?? '—' }}</div>
                                    <div class="text-xs text-ink-secondary">{{ step.part_name ?? '' }}</div>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-success">{{ fmt(step.qty_good) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-danger">{{ fmt(step.qty_reject) }}</td>
                                <td class="px-3 py-2 text-ink-secondary">{{ step.machines.length ? step.machines.join(', ') : '—' }}</td>
                                <td class="px-3 py-2 tabular-nums text-ink-secondary">{{ step.last_date ?? '—' }}</td>
                            </tr>
                            <tr v-if="!detailRow?.detail?.steps?.length">
                                <td colspan="5" class="px-3 py-8 text-center text-sm text-ink-secondary">{{ t('production.noResultsYet') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
