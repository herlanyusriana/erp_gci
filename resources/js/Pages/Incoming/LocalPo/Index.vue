<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import type { IncomingArrival, Paginated } from '@/types';

const { t } = useI18n();

interface SupplierOpt { id: number; supplier_code: string | null; supplier_name: string; }

const props = defineProps<{
    localPos: Paginated<IncomingArrival>;
    suppliers: SupplierOpt[];
    filters: { search?: string; supplier_id?: string };
}>();

const search = ref(props.filters.search ?? '');
const supplierId = ref(props.filters.supplier_id ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;

function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('local-pos.index'), {
            search: search.value || undefined,
            supplier_id: supplierId.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
}


function remove(id: number, no: string | null) {
    if (confirm(t('incoming.deleteLocal', { number: no ?? '' }))) {
        router.delete(route('local-pos.destroy', id));
    }
}
</script>

<template>
    <Head :title="t('incoming.localPo')" />
    <AppLayout>
        <BackButton :href="route('incoming-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.localPo') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('incoming.localDescription') }}</p>
            </div>
            <div class="flex gap-2">
                <a :href="route('local-pos.export')" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background">{{ t('incoming.exportExcel') }}</a>
                <Link :href="route('local-pos.create')" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    {{ t('incoming.add') }}
                </Link>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-3">
            <input v-model="search" @input="doSearch" type="search" :placeholder="t('incoming.searchLocal')" :aria-label="t('incoming.searchLocal')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />
            <select :aria-label="t('incoming.allSuppliers')" v-model="supplierId" @change="doSearch" class="rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                <option value="">{{ t('incoming.allSuppliers') }}</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.supplier_code ? `${s.supplier_code} — ` : '' }}{{ s.supplier_name }}</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('incoming.poArrival') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('incoming.poDate') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('incoming.supplier') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.items') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.remaining') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('incoming.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="po in localPos.data" :key="po.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">
                            <Link :href="route('local-pos.show', po.id)" class="hover:text-primary">{{ po.po_no ?? '—' }}</Link>
                            <div class="text-xs text-ink-secondary">
                                {{ po.invoice_no ? `${t('incoming.invoiceNo')}: ${po.invoice_no} · ` : '' }}{{ po.arrival_no }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">{{ po.invoice_date ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ po.supplier?.supplier_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ po.items_count ?? 0 }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold" :class="(po.remaining_qty ?? 0) > 0 ? 'text-warning' : 'text-success'">{{ po.remaining_qty ?? 0 }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge uppercase :status="po.status">{{ t('incoming.status_' + po.status) }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :href="route('local-pos.show', po.id)" :label="t('incoming.view')" variant="view" />
                                <ActionButton :href="route('local-pos.edit', po.id)" :label="t('incoming.edit')" variant="edit" />
                                <ActionButton :label="t('incoming.delete')" variant="delete" @click="remove(po.id, po.po_no ?? po.arrival_no)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!localPos.data.length">
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noLocal') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="localPos.links" />
    </AppLayout>
</template>
