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
import type { PurchaseOrder, Paginated } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    purchaseOrders: Paginated<PurchaseOrder>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('purchase-orders.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
}

function remove(po: PurchaseOrder) {
    if (confirm(t('incoming.deletePo', { number: po.po_no }))) router.delete(route('purchase-orders.destroy', po.id));
}

</script>

<template>
    <Head :title="t('incoming.purchaseOrder')" />
    <AppLayout>
        <BackButton :href="route('incoming-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.purchaseOrder') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('incoming.poDescription') }}</p>
            </div>
            <Link
                :href="route('purchase-orders.create')"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('incoming.add') }}
            </Link>
        </div>

        <input v-model="search" @input="doSearch" type="search" :placeholder="t('incoming.searchPo')" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">{{ t('incoming.poNo') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.supplier') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.items') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.expected') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="po in purchaseOrders.data" :key="po.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3">
                            <Link :href="route('purchase-orders.show', po.id)" class="font-medium text-primary hover:underline">{{ po.po_no }}</Link>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">{{ po.supplier?.supplier_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ po.items_count ?? 0 }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ po.expected_date ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge uppercase :status="po.status">{{ t('incoming.status_' + po.status) }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :href="route('purchase-orders.show', po.id)" :label="t('incoming.view')" variant="view" />
                                <ActionButton :href="route('purchase-orders.edit', po.id)" :label="t('incoming.edit')" variant="edit" />
                                <ActionButton :label="t('incoming.delete')" variant="delete" @click="remove(po)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="purchaseOrders.data.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noPo') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="purchaseOrders.links" />
    </AppLayout>
</template>