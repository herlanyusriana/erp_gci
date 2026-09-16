<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import type { WorkOrder, Paginated } from '@/types';

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

const statusBadge = (s: string) => {
    switch (s) {
        case 'planned': return 'bg-info/10 text-info';
        case 'in_progress': return 'bg-warning/10 text-warning';
        case 'completed': return 'bg-success/10 text-success';
        case 'cancelled': return 'bg-ink-secondary/10 text-ink-secondary';
        default: return 'bg-ink-secondary/10 text-ink-secondary';
    }
};

const fmt = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
</script>

<template>
    <AppLayout>
        <BackButton :href="route('launcher')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">Work Order</h1>
                <p class="mt-1 text-sm text-ink-secondary">Perintah produksi FG · explode BOM · konsumsi FIFO saat release.</p>
            </div>
            <div>
                <Link :href="route('work-orders.create')" class="inline-flex items-center gap-1 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">
                    + New WO
                </Link>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-3">
            <input v-model="search" @input="applyFilter" type="search" placeholder="Cari WO / FG…" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary placeholder-ink-secondary focus:border-primary focus:ring-primary sm:w-72" />
            <select v-model="status" @change="applyFilter" class="rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                <option value="">Semua Status</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">WO No</th>
                        <th class="px-4 py-3">FG</th>
                        <th class="px-4 py-3 text-right">Qty</th>
                        <th class="px-4 py-3 text-center">Item</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Tanggal</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="wo in workOrders.data" :key="wo.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ wo.wo_no }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-ink-primary">{{ wo.part?.part_number }}</div>
                            <div class="text-xs text-ink-secondary">{{ wo.part?.part_name }}</div>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-ink-primary">{{ fmt(wo.qty) }}</td>
                        <td class="px-4 py-3 text-center tabular-nums text-ink-secondary">{{ wo.items_count ?? 0 }}</td>
                        <td class="px-4 py-3">
                            <span :class="statusBadge(wo.status)" class="rounded-md px-2 py-0.5 text-xs font-semibold uppercase">{{ wo.status }}</span>
                        </td>
                        <td class="px-4 py-3 text-ink-secondary">{{ wo.planned_date ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :href="route('work-orders.show', wo.id)" label="Lihat" variant="view" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="workOrders.data.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">Belum ada Work Order.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="workOrders.links" />
    </AppLayout>
</template>