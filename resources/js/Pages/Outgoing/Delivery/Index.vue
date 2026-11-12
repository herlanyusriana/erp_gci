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

interface DeliveryRow {
    id: number;
    delivery_no: string;
    delivery_date: string;
    status: string;
    truck_no: string | null;
    items_count: number;
    customer?: { customer_code: string; customer_name: string } | null;
}

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

const props = defineProps<{
    deliveries: Paginated<DeliveryRow>;
    filters: { search?: string; status?: string; from?: string; to?: string };
    statuses: string[];
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;
function applyFilters() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('deliveries.index'),
            {
                search: search.value || undefined,
                status: status.value || undefined,
                from: from.value || undefined,
                to: to.value || undefined,
            },
            { preserveState: true, replace: true },
        );
    }, 250);
}

const tone = (value: string) =>
    value === 'shipped' ? 'success' : value === 'picked' ? 'warning' : 'neutral';
</script>

<template>
    <Head :title="t('outgoing.deliveryTitle')" />
    <AppLayout>
        <BackButton :href="route('outgoing-data')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('outgoing.deliveryTitle') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('outgoing.deliverySubtitle') }}</p>
            </div>
            <Link
                :href="route('deliveries.create')"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('outgoing.newDelivery') }}
            </Link>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-borderline bg-surface p-3">
            <div class="w-full sm:w-64">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.search') }}</label>
                <input v-model="search" @input="applyFilters" type="search" :aria-label="t('outgoing.search')" :placeholder="t('outgoing.searchPlaceholder')" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.status') }}</label>
                <select v-model="status" @change="applyFilters" :aria-label="t('outgoing.status')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                    <option value="">{{ t('outgoing.allStatuses') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ t(`outgoing.status_${s}`) }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.from') }}</label>
                <input v-model="from" @change="applyFilters" type="date" :aria-label="t('outgoing.from')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.to') }}</label>
                <input v-model="to" @change="applyFilters" type="date" :aria-label="t('outgoing.to')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.deliveryNo') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.deliveryDate') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.customer') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('outgoing.itemsCount') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.status') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('outgoing.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="d in deliveries.data" :key="d.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3 font-mono font-semibold text-ink-primary">{{ d.delivery_no }}</td>
                            <td class="px-4 py-3 text-ink-primary whitespace-nowrap">{{ formatDate(d.delivery_date) }}</td>
                            <td class="px-4 py-3 text-ink-primary">
                                {{ d.customer?.customer_name ?? '—' }}
                                <div class="text-xs text-ink-secondary">{{ d.customer?.customer_code ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ d.items_count }}</td>
                            <td class="px-4 py-3">
                                <StatusBadge :tone="tone(d.status)" uppercase>{{ t(`outgoing.status_${d.status}`) }}</StatusBadge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <ActionButton :href="route('deliveries.show', d.id)" :label="t('outgoing.detail')" variant="view" />
                                    <ActionButton :href="route('deliveries.print', d.id)" :label="t('outgoing.printSj')" variant="print" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="deliveries.data.length === 0">
                            <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('outgoing.deliveryEmpty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Pagination :links="deliveries.links" />
    </AppLayout>
</template>