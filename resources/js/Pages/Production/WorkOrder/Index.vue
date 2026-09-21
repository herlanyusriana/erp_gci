<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import type { WorkOrder, Paginated } from '@/types';

const { t, locale, te } = useI18n();

const props = defineProps<{
    workOrders: Paginated<WorkOrder>;
    filters: { search?: string; status?: string };
    statuses: string[];
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;
function applyFilter() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('work-orders.index'), {
            search: search.value || undefined,
            status: status.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
}

const statusLabel = (status: string) => te(`production.statuses.${status}`) ? t(`production.statuses.${status}`) : status;


const fmt = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 2 });
</script>

<template>
    <AppLayout>
        <Head :title="t('production.workOrder')" />
        <BackButton :href="route('launcher')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.workOrder') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('production.woIndexDescription') }}</p>
            </div>
            <div>
                <Link :href="route('work-orders.create')" class="inline-flex items-center gap-1 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">
                    {{ t('production.newWo') }}
                </Link>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-3">
            <input v-model="search" @input="applyFilter" type="search" :placeholder="t('production.searchWo')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary placeholder-ink-secondary focus:border-primary focus:ring-primary sm:w-72" />
            <select v-model="status" @change="applyFilter" class="rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                <option value="">{{ t('production.allStatuses') }}</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">{{ t('production.woNo') }}</th>
                        <th class="px-4 py-3">{{ t('production.fg') }}</th>
                        <th class="px-4 py-3">{{ t('production.model') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('production.qty') }}</th>
                        <th class="px-4 py-3 text-center">{{ t('production.item') }}</th>
                        <th class="px-4 py-3">{{ t('production.status') }}</th>
                        <th class="px-4 py-3">{{ t('production.date') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('production.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="wo in workOrders.data" :key="wo.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ wo.wo_no }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-ink-primary">{{ wo.part?.part_number }}</div>
                            <div class="text-xs text-ink-secondary">{{ wo.part?.part_name }}</div>
                        </td>
                        <td class="px-4 py-3 text-ink-secondary">{{ wo.part?.model ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-ink-primary">{{ fmt(wo.qty) }}</td>
                        <td class="px-4 py-3 text-center tabular-nums text-ink-secondary">{{ wo.items_count ?? 0 }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge uppercase :status="wo.status">{{ statusLabel(wo.status) }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3 text-ink-secondary">{{ wo.planned_date ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :href="route('work-orders.show', wo.id)" :label="t('production.view')" variant="view" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="workOrders.data.length === 0">
                        <td colspan="8" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('production.noWo') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="workOrders.links" />
    </AppLayout>
</template>