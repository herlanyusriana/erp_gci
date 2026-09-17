<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import type { WorkOrder, WorkOrderItem } from '@/types';

const props = defineProps<{
    workOrder: WorkOrder;
    can: { release: boolean; complete: boolean; cancel: boolean; delete: boolean };
}>();

const items = computed(() => props.workOrder.items ?? []);

const fmt = (n: number | null | undefined, decimals = 4) => {
    if (n == null) return '—';
    return Number(n).toLocaleString('en-US', { maximumFractionDigits: decimals });
};

const sourceBadge = (s: string | null) => {
    switch (s) {
        case 'Prod': return 'bg-primary-light text-primary';
        case 'Vendor': return 'bg-amber-100 text-amber-700';
        case 'Subcon': return 'bg-purple-100 text-purple-700';
        case 'FREE_ISSUE': return 'bg-emerald-100 text-emerald-700';
        default: return 'bg-ink-secondary/10 text-ink-secondary';
    }
};

const statusBadge = (s: string) => {
    switch (s) {
        case 'planned': return 'bg-info/10 text-info';
        case 'in_progress': return 'bg-warning/10 text-warning';
        case 'completed': return 'bg-success/10 text-success';
        case 'cancelled': return 'bg-ink-secondary/10 text-ink-secondary';
        default: return 'bg-ink-secondary/10 text-ink-secondary';
    }
};

const releaseForm = useForm({});
const completeForm = useForm({});
const cancelForm = useForm({});
const deleteForm = useForm({});
const itemForms = new Map<number, ReturnType<typeof useForm>>();

function itemForm(id: number) {
    if (!itemForms.has(id)) itemForms.set(id, useForm({ selected_part_id: '' }));
    return itemForms.get(id)!;
}

function saveMaterial(item: WorkOrderItem) {
    const selected = item.selected_part_id || item.child_part_id;
    itemForm(item.id).selected_part_id = String(selected ?? '');
    itemForm(item.id).patch(route('work-orders.items.update', [props.workOrder.id, item.id]));
}

function doRelease() {
    if (confirm('Release WO ini? Material & WIP akan dikonsumsi stok (FIFO).')) {
        releaseForm.post(route('work-orders.release', props.workOrder.id));
    }
}
function doComplete() {
    if (confirm('Tandai WO selesai?')) {
        completeForm.post(route('work-orders.complete', props.workOrder.id));
    }
}
function doCancel() {
    if (confirm('Batalkan WO ini?')) {
        cancelForm.post(route('work-orders.cancel', props.workOrder.id));
    }
}
function doDestroy() {
    if (confirm('Hapus WO ini?')) {
        deleteForm.delete(route('work-orders.destroy', props.workOrder.id));
    }
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('work-orders.index')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-3 text-2xl font-bold tracking-tight text-ink-primary">
                    {{ workOrder.wo_no }}
                    <span :class="statusBadge(workOrder.status)" class="rounded-md px-2 py-0.5 text-xs font-semibold uppercase">{{ workOrder.status }}</span>
                </h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ workOrder.part?.part_number }} · {{ workOrder.part?.part_name }}
                    <span v-if="workOrder.part?.model"> · {{ workOrder.part.model }}</span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button v-if="workOrder.status === 'planned' && can.release" @click="doRelease" :disabled="releaseForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                    {{ releaseForm.processing ? 'Me-release…' : 'Release' }}
                </button>
                <button v-if="workOrder.status === 'in_progress' && can.complete" @click="doComplete" :disabled="completeForm.processing"
                    class="rounded-md bg-success px-4 py-2 text-sm font-semibold text-white transition hover:bg-success/90 disabled:opacity-60">
                    {{ completeForm.processing ? '…' : 'Complete' }}
                </button>
                <button v-if="['planned', 'in_progress'].includes(workOrder.status) && can.cancel" @click="doCancel" :disabled="cancelForm.processing"
                    class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background">
                    Cancel
                </button>
                <button v-if="can.delete" @click="doDestroy" :disabled="deleteForm.processing"
                    class="rounded-md border border-danger/30 px-4 py-2 text-sm font-medium text-danger transition hover:bg-danger/10">
                    Hapus
                </button>
            </div>
        </div>

        <!-- Summary -->
        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-medium uppercase text-ink-secondary">Qty FG</div>
                <div class="mt-1 text-xl font-bold tabular-nums text-ink-primary">{{ fmt(workOrder.qty, 2) }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-medium uppercase text-ink-secondary">Item Routing</div>
                <div class="mt-1 text-xl font-bold tabular-nums text-ink-primary">{{ items.length }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-medium uppercase text-ink-secondary">Rencana</div>
                <div class="mt-1 text-lg font-semibold text-ink-primary">{{ workOrder.planned_date ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-medium uppercase text-ink-secondary">Release</div>
                <div class="mt-1 text-lg font-semibold text-ink-primary">{{ workOrder.released_at ?? '—' }}</div>
            </div>
        </div>

        <!-- Routing table -->
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="border-b border-borderline px-4 py-3 text-sm font-semibold text-ink-primary">Routing Produksi (snapshot BOM)</div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th class="px-3 py-3">Seq</th>
                            <th class="px-3 py-3">Proses</th>
                            <th class="px-3 py-3">Mesin</th>
                            <th class="px-3 py-3">Material / Subs</th>
                            <th class="px-3 py-3">Parent</th>
                            <th class="px-3 py-3 text-right">Child Qty</th>
                            <th class="px-3 py-3">UOM</th>
                            <th class="px-3 py-3">Source</th>
                            <th class="px-3 py-3 text-right">Butuh</th>
                            <th class="px-3 py-3 text-right">Terpakai</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="it in items" :key="it.id" class="align-top hover:bg-primary-light/40">
                            <td class="px-3 py-2.5 tabular-nums text-ink-secondary">{{ it.sequence ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-ink-primary">{{ it.process?.process_name ?? '—' }}</td>
                            <td class="px-3 py-2.5">
                                <div class="flex items-center gap-2 whitespace-nowrap">
                                    <span class="text-ink-primary">{{ it.machine?.machine_name ?? '—' }}</span>
                                    <a v-if="it.machine_id" :href="route('machines.edit', it.machine_id)" title="Edit mesin di Master" class="text-ink-secondary hover:text-primary">✎</a>
                                </div>
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="min-w-64">
                                    <div class="flex items-center gap-2">
                                        <input
                                            :list="`wo-material-${it.id}`"
                                            :value="it.selected_part?.part_number ?? it.child_part?.part_number ?? it.child_part_name ?? ''"
                                            :disabled="workOrder.status !== 'planned'"
                                            @change="(e) => { const p = [...(it.child_part?.partSubstitutes ?? []).map((s) => s.substitute_part), it.child_part].find((p) => p && p.part_number === (e.target as HTMLInputElement).value); if (p) { it.selected_part_id = p.id; saveMaterial(it); } }"
                                            class="w-full rounded-md border-borderline bg-background px-2 py-1 text-sm text-ink-primary"
                                        />
                                        <span v-if="itemForm(it.id).processing" class="text-xs text-ink-secondary">Menyimpan…</span>
                                    </div>
                                    <datalist :id="`wo-material-${it.id}`">
                                        <option v-if="it.child_part" :value="it.child_part.part_number">{{ it.child_part.part_name }} (Main)</option>
                                        <option v-for="s in (it.child_part?.partSubstitutes ?? [])" :key="s.id" :value="s.substitute_part?.part_number ?? ''">{{ s.substitute_part?.part_name }} (Subs)</option>
                                    </datalist>
                                    <div class="text-xs text-ink-secondary">Main: {{ it.child_part?.part_number ?? it.child_part_name ?? '—' }}</div>
                                </div>
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="font-medium text-ink-primary">{{ it.parent_part?.part_number ?? it.parent_part_name ?? '—' }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-primary">{{ fmt(it.child_qty) }}</td>
                            <td class="px-3 py-2.5 text-ink-secondary">{{ it.uom_rm ?? '—' }}</td>
                            <td class="px-3 py-2.5">
                                <span :class="sourceBadge(it.source)" class="rounded-md px-2 py-0.5 text-xs font-semibold">{{ it.source ?? '—' }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-secondary">{{ fmt(it.qty_required) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums" :class="Number(it.qty_consumed) < Number(it.qty_required) ? 'text-danger' : 'text-success'">{{ fmt(it.qty_consumed) }}</td>
                        </tr>
                        <tr v-if="items.length === 0">
                            <td colspan="10" class="px-4 py-12 text-center text-sm text-ink-secondary">Tidak ada routing.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>