<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import { Link } from '@inertiajs/vue3';
import type { PurchaseOrder, Paginated } from '@/types';

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
    if (confirm(`Hapus PO ${po.po_no}?`)) router.delete(route('purchase-orders.destroy', po.id));
}

const statusTone = (s: string) => {
    switch (s) {
        case 'confirmed':
            return 'bg-info/10 text-info';
        case 'received':
            return 'bg-success/10 text-success';
        case 'cancelled':
            return 'bg-danger/10 text-danger';
        default:
            return 'bg-warning/10 text-warning';
    }
};
</script>

<template>
    <AppLayout>
        <BackButton :href="route('incoming-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">Purchase Order</h1>
                <p class="mt-1 text-sm text-ink-secondary">PO supplier dengan item part + qty + harga.</p>
            </div>
            <Link
                :href="route('purchase-orders.create')"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Tambah
            </Link>
        </div>

        <input v-model="search" @input="doSearch" type="search" placeholder="Cari PO / supplier…" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">PO No</th>
                        <th class="px-4 py-3">Supplier</th>
                        <th class="px-4 py-3">Items</th>
                        <th class="px-4 py-3">Expected</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
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
                            <span :class="statusTone(po.status)" class="rounded-md px-2 py-0.5 text-xs font-semibold uppercase">{{ po.status }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :href="route('purchase-orders.show', po.id)" label="Lihat" variant="view" />
                                <ActionButton :href="route('purchase-orders.edit', po.id)" label="Edit" variant="edit" />
                                <ActionButton label="Hapus" variant="delete" @click="remove(po)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="purchaseOrders.data.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">Tidak ada PO.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="purchaseOrders.links" />
    </AppLayout>
</template>