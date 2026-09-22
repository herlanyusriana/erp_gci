<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { WorkOrder, Paginated } from '@/types';

const { t, locale } = useI18n();

const props = defineProps<{
    workOrders: Paginated<WorkOrder>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('production-results.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
}

const fmt = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 2 });
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
                        <th scope="col" class="px-4 py-3">{{ t('production.fg') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.woQty') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.fgProduced') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.item') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="wo in workOrders.data" :key="wo.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3">
                            <Link :href="route('work-orders.show', wo.id)" class="font-semibold text-ink-primary hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ wo.wo_no }}</Link>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-ink-primary">{{ wo.part?.part_number ?? '—' }}</div>
                            <div class="text-xs text-ink-secondary">{{ wo.part?.part_name ?? '' }}</div>
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
                            <div class="flex justify-end">
                                <Link :href="route('production-results.create', wo.id)" class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    {{ t('production.report') }}
                                </Link>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="workOrders.data.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('production.noInProgressWo') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="workOrders.links" />
    </AppLayout>
</template>
