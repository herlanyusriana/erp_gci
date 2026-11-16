<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDate } from '@/utils/date';

const { t } = useI18n();

interface PickingRow {
    id: number;
    picking_no: string;
    picking_date: string;
    status: string;
    items_count: number;
    deliveries_count: number;
    creator?: { name: string } | null;
}

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

const props = defineProps<{
    lists: Paginated<PickingRow>;
    filters: { search?: string; from?: string; to?: string };
}>();

const search = ref(props.filters.search ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;
function applyFilters() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('picking-lists.index'),
            {
                search: search.value || undefined,
                from: from.value || undefined,
                to: to.value || undefined,
            },
            { preserveState: true, replace: true },
        );
    }, 250);
}
</script>

<template>
    <Head :title="t('picking.title')" />
    <AppLayout>
        <BackButton :href="route('outgoing-data')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('picking.title') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('picking.subtitle') }}</p>
            </div>
            <Link
                :href="route('picking-lists.create')"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('picking.new') }}
            </Link>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-borderline bg-surface p-3">
            <div class="w-full sm:w-64">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('picking.search') }}</label>
                <input
                    v-model="search"
                    type="search"
                    :aria-label="t('picking.search')"
                    class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                    @input="applyFilters"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('picking.date') }}</label>
                <div class="flex items-center gap-2">
                    <input
                        v-model="from"
                        type="date"
                        :aria-label="t('picking.date')"
                        class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        @change="applyFilters"
                    />
                    <span class="text-sm text-ink-secondary">–</span>
                    <input
                        v-model="to"
                        type="date"
                        :aria-label="t('picking.date')"
                        class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        @change="applyFilters"
                    />
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('picking.title') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('picking.date') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('picking.itemsCount') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('picking.deliveriesCount') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('picking.status') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('picking.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="row in lists.data" :key="row.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3 font-mono font-semibold text-ink-primary">{{ row.picking_no }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-ink-primary">{{ formatDate(row.picking_date) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ row.items_count }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ row.deliveries_count }}</td>
                            <td class="px-4 py-3">
                                <StatusBadge :tone="row.status === 'draft' ? 'neutral' : 'success'" uppercase>
                                    {{ t(`picking.status_${row.status}`) }}
                                </StatusBadge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <ActionButton :href="route('picking-lists.show', row.id)" :label="t('picking.open')" variant="view" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="lists.data.length === 0">
                            <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('picking.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Pagination :links="lists.links" />
    </AppLayout>
</template>
