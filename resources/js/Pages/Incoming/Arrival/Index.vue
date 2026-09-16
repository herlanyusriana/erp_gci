<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import { Link } from '@inertiajs/vue3';
import type { IncomingArrival, Paginated } from '@/types';

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
    if (confirm(`Hapus arrival ${a.arrival_no}?`)) router.delete(route('incoming-arrivals.destroy', a.id));
}

const statusTone = (s: string) => {
    switch (s) {
        case 'completed':
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
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">Incoming Arrival</h1>
                <p class="mt-1 text-sm text-ink-secondary">Kedatangan barang inbound · item · container · dokumen.</p>
            </div>
            <Link
                :href="route('incoming-arrivals.create')"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Tambah
            </Link>
        </div>

        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <input v-model="search" @input="doSearch" type="search" placeholder="Cari arrival / invoice / supplier…" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />
            <select v-model="status" @change="doSearch" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-40">
                <option value="">Semua Status</option>
                <option value="pending">Pending</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">Arrival No</th>
                        <th class="px-4 py-3">Invoice</th>
                        <th class="px-4 py-3">Supplier</th>
                        <th class="px-4 py-3">Items</th>
                        <th class="px-4 py-3">ETA</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
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
                            <span :class="statusTone(a.status)" class="rounded-md px-2 py-0.5 text-xs font-semibold uppercase">{{ a.status }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :href="route('incoming-arrivals.show', a.id)" label="Lihat" variant="view" />
                                <ActionButton :href="route('incoming-arrivals.edit', a.id)" label="Edit" variant="edit" />
                                <ActionButton label="Hapus" variant="delete" @click="remove(a)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="arrivals.data.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">Tidak ada arrival.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="arrivals.links" />
    </AppLayout>
</template>