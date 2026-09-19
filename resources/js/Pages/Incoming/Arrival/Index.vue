<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import { Link } from '@inertiajs/vue3';
import type { IncomingArrival, Paginated } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    arrivals: Paginated<IncomingArrival>;
    filters: { search?: string; status?: string };
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('incoming-arrivals.index'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }), 300);
}

function remove(a: IncomingArrival) {
    if (confirm(t('incoming.deleteArrival', { number: a.arrival_no }))) router.delete(route('incoming-arrivals.destroy', a.id));
}

</script>

<template>
    <Head :title="t('incoming.incomingArrival')" />
    <AppLayout>
        <BackButton :href="route('incoming-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.incomingArrival') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('incoming.arrivalDescription') }}</p>
            </div>
            <Link
                :href="route('incoming-arrivals.create')"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('incoming.add') }}
            </Link>
        </div>

        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <input v-model="search" @input="doSearch" type="search" :placeholder="t('incoming.searchArrival')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />
            <select v-model="status" @change="doSearch" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-40">
                <option value="">{{ t('incoming.allStatuses') }}</option>
                <option value="pending">{{ t('incoming.status_pending') }}</option>
                <option value="completed">{{ t('incoming.status_completed') }}</option>
                <option value="cancelled">{{ t('incoming.status_cancelled') }}</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">{{ t('incoming.arrivalNo') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.invoice') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.supplier') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.items') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.eta') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="a in arrivals.data" :key="a.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3">
                            <Link :href="route('incoming-arrivals.show', a.id)" class="font-medium text-primary hover:underline">{{ a.arrival_no }}</Link>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">{{ a.invoice_no ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ a.supplier?.supplier_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ a.items_count ?? 0 }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ a.eta_gci ?? a.eta ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge uppercase :status="a.status">{{ t('incoming.status_' + a.status) }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :href="route('incoming-arrivals.show', a.id)" :label="t('incoming.view')" variant="view" />
                                <ActionButton :href="route('incoming-arrivals.edit', a.id)" :label="t('incoming.edit')" variant="edit" />
                                <ActionButton :label="t('incoming.delete')" variant="delete" @click="remove(a)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="arrivals.data.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noArrivals') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="arrivals.links" />
    </AppLayout>
</template>