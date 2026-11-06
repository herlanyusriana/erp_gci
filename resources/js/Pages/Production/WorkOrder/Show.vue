<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import type { WorkOrder, WorkOrderItem } from '@/types';

const { t, locale, te } = useI18n();

const props = defineProps<{
    workOrder: WorkOrder;
    can: { release: boolean; complete: boolean; cancel: boolean; delete: boolean };
}>();

const items = computed(() => props.workOrder.items ?? []);

/** Cek apakah mesin merupakan continuous tandem line (TPL DONGSHIN & TPL KUKIL) */
const isTplMachine = (it: WorkOrderItem) => {
    const name = (it.machine?.machine_name ?? '').trim().toUpperCase();
    const code = (it.machine?.machine_code ?? '').trim().toUpperCase();
    return name.includes('TPL DONGSHIN') || name.includes('TPL KUKIL')
        || code.includes('TPL_DONGSHIN') || code.includes('TPL_KUKIL')
        || (name.startsWith('TPL') && !name.includes('ASSY'));
};

interface ProcessGroup {
    key: string;
    isTpl: boolean;
    sequence: number | string;
    processName: string;
    machineName: string;
    parentPartNumber: string;
    parentPartName: string;
    parentQty: number | null;
    parentUom: string | null;
    items: WorkOrderItem[];
}

const routingGroups = computed(() => {
    const groups: ProcessGroup[] = [];
    for (const it of items.value) {
        const isTpl = isTplMachine(it);
        const procKey = isTpl
            ? 'TPL_LINE'
            : `proc:${it.sequence ?? 0}_mach:${it.machine_id ?? 0}_parent:${it.parent_part_id ?? 0}`;

        const last = groups[groups.length - 1];
        if (last && last.key === procKey) {
            last.items.push(it);
            // Untuk TPL, update parent part ke step terakhir
            if (isTpl) {
                last.parentPartNumber = it.parent_part?.part_number ?? it.parent_part_name ?? '—';
                last.parentPartName = it.parent_part?.part_name ?? '';
            }
            continue;
        }

        groups.push({
            key: procKey,
            isTpl,
            sequence: it.sequence ?? '—',
            processName: it.process?.process_name ?? '—',
            machineName: it.machine?.machine_name ?? '—',
            parentPartNumber: it.parent_part?.part_number ?? it.parent_part_name ?? '—',
            parentPartName: it.parent_part?.part_name ?? '',
            parentQty: it.parent_qty,
            parentUom: it.parent_uom,
            items: [it],
        });
    }
    return groups;
});

const fmt = (n: number | null | undefined, decimals = 4) => {
    if (n == null) return '—';
    return Number(n).toLocaleString(locale.value, { maximumFractionDigits: decimals });
};

const sourceLabel = (source: string | null) => source && te(`production.sources.${source}`) ? t(`production.sources.${source}`) : source ?? '—';


const statusLabel = (status: string) => te(`production.statuses.${status}`) ? t(`production.statuses.${status}`) : status;

/** Apakah material ini sudah di-issue (stok dialokasikan / status in_progress / completed)? */
const isMaterialIssued = (item: WorkOrderItem) => {
    if (props.workOrder.status === 'in_progress' || props.workOrder.status === 'completed') {
        return true;
    }
    if (item.allocations && item.allocations.length > 0) {
        return true;
    }
    return Number(item.qty_consumed) > 0;
};

/** Nama & nomor part yang ditampilkan secara dinamis (Main BOM vs Substitute) */
const displayPartInfo = (item: WorkOrderItem) => {
    const issued = isMaterialIssued(item);
    const mainPart = item.child_part;
    const substitutePart = item.selected_part;

    if (!issued) {
        // Sebelum issue: tampilkan Main Part BOM
        return {
            partNumber: mainPart?.part_number ?? item.child_part_name ?? '—',
            partName: mainPart?.part_name ?? '',
            isSubstitute: false,
            badge: 'Main BOM',
        };
    }

    // Sesudah issue: tampilkan Part Substitute riil
    const partNumber = substitutePart?.part_number ?? mainPart?.part_number ?? item.child_part_name ?? '—';
    const partName = substitutePart?.part_name ?? mainPart?.part_name ?? '';
    const isSub = Boolean(substitutePart && mainPart && substitutePart.id !== mainPart.id);

    return {
        partNumber,
        partName,
        isSubstitute: isSub,
        badge: isSub ? 'Substitusi' : 'Main BOM',
    };
};


const releaseForm = useForm({});
const completeForm = useForm({});
const cancelForm = useForm({});
const deleteForm = useForm({});

function doRelease() {
    if (confirm(t('production.releaseConfirm'))) {
        releaseForm.post(route('work-orders.release', props.workOrder.id));
    }
}
function doComplete() {
    if (confirm(t('production.completeConfirm'))) {
        completeForm.post(route('work-orders.complete', props.workOrder.id));
    }
}
function doCancel() {
    if (confirm(t('production.cancelConfirm'))) {
        cancelForm.post(route('work-orders.cancel', props.workOrder.id));
    }
}
function doDestroy() {
    if (confirm(t('production.deleteConfirm'))) {
        deleteForm.delete(route('work-orders.destroy', props.workOrder.id));
    }
}
</script>

<template>
    <AppLayout>
        <Head :title="t('production.detailTitle', { number: workOrder.wo_no })" />
        <BackButton :href="route('work-orders.index')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-3 text-2xl font-bold tracking-tight text-ink-primary">
                    {{ workOrder.wo_no }}
                    <StatusBadge uppercase :status="workOrder.status">{{ statusLabel(workOrder.status) }}</StatusBadge>
                </h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ workOrder.part?.part_number }} · {{ workOrder.part?.part_name }}
                    <span v-if="workOrder.part?.model"> · {{ workOrder.part.model }}</span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a v-if="workOrder.status === 'in_progress' && can.complete" :href="route('production-results.create', workOrder.id)"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                    {{ t('production.results') }}
                </a>
                <button v-if="workOrder.status === 'planned' && can.release" @click="doRelease" :disabled="releaseForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                    {{ releaseForm.processing ? t('production.releasing') : t('production.release') }}
                </button>
                <button v-if="workOrder.status === 'in_progress' && can.complete" @click="doComplete" :disabled="completeForm.processing"
                    class="rounded-md bg-success px-4 py-2 text-sm font-semibold text-white transition hover:bg-success/90 disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                    {{ completeForm.processing ? '…' : t('production.complete') }}
                </button>
                <button v-if="['planned', 'in_progress'].includes(workOrder.status) && can.cancel" @click="doCancel" :disabled="cancelForm.processing"
                    class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                    {{ t('production.cancel') }}
                </button>
                <button v-if="can.delete" @click="doDestroy" :disabled="deleteForm.processing"
                    class="rounded-md border border-danger/30 px-4 py-2 text-sm font-medium text-danger transition hover:bg-danger/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                    {{ t('production.delete') }}
                </button>
            </div>
        </div>

        <!-- Summary -->
        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-medium uppercase text-ink-secondary">{{ t('production.fgQty') }}</div>
                <div class="mt-1 text-xl font-bold tabular-nums text-ink-primary">{{ fmt(workOrder.qty, 2) }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-medium uppercase text-ink-secondary">{{ t('production.routingItems') }}</div>
                <div class="mt-1 text-xl font-bold tabular-nums text-ink-primary">{{ items.length }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-medium uppercase text-ink-secondary">{{ t('production.planned') }}</div>
                <div class="mt-1 text-lg font-semibold text-ink-primary">{{ workOrder.planned_date ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-medium uppercase text-ink-secondary">{{ t('production.releasedAt') }}</div>
                <div class="mt-1 text-lg font-semibold text-ink-primary">{{ workOrder.released_at ?? '—' }}</div>
            </div>
        </div>

        <!-- Routing table -->
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="border-b border-borderline px-4 py-3 flex items-center justify-between text-sm font-semibold text-ink-primary">
                <span>{{ t('production.routing') }}</span>
                <span class="text-xs font-normal text-ink-secondary">
                    Status Part: <span class="font-medium text-ink-primary">{{ workOrder.status === 'planned' ? 'Main Part BOM (Sebelum Issue)' : 'Substitute Part (Setelah Issue)' }}</span>
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-3 py-3">{{ t('production.sequence') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('production.process') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('production.machine') }}</th>
                            <th scope="col" class="px-3 py-3">Material Input</th>
                            <th scope="col" class="px-3 py-3">{{ t('production.parent') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">Rasio</th>
                            <th scope="col" class="px-3 py-3">{{ t('production.uom') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('production.source') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('production.required') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('production.consumed') }}</th>
                            <th scope="col" class="px-3 py-3 text-center">{{ t('production.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <template v-for="g in routingGroups" :key="g.key">
                            <!-- KASUS A: MESIN TPL CONTINUOUS LINE (Dirangkum 1 baris) -->
                            <tr v-if="g.isTpl" class="align-top hover:bg-primary-light/40">
                                <td class="px-3 py-3 tabular-nums font-semibold text-ink-secondary">{{ g.sequence }}</td>
                                <td class="px-3 py-3 font-medium text-ink-primary">{{ g.processName }}</td>
                                <td class="px-3 py-3 whitespace-nowrap text-ink-primary">
                                    <span class="inline-flex items-center rounded-md bg-primary-light px-2 py-0.5 text-xs font-semibold text-primary">
                                        {{ g.machineName }} (Tandem Line)
                                    </span>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="font-medium text-ink-primary">{{ displayPartInfo(g.items[0]).partNumber }}</div>
                                    <div class="text-xs text-ink-secondary">{{ displayPartInfo(g.items[0]).partName }}</div>
                                    <div class="mt-1 flex items-center gap-1.5">
                                        <span :class="['inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium', displayPartInfo(g.items[0]).isSubstitute ? 'bg-info/15 text-info' : 'bg-background text-ink-secondary']">
                                            {{ displayPartInfo(g.items[0]).badge }}
                                        </span>
                                        <span class="rounded bg-background px-1.5 py-0.5 text-[11px] text-ink-secondary">
                                            {{ g.items.length }} step terintegrasi
                                        </span>
                                    </div>
                                    <div v-if="g.items[0].allocations?.length" class="mt-1 space-y-0.5">
                                        <div v-for="a in g.items[0].allocations" :key="a.id" class="text-xs text-ink-secondary">
                                            <span class="font-medium text-ink-primary">{{ a.part?.part_number ?? '#' + a.part_id }}</span> · {{ fmt(a.qty) }} {{ g.items[0].uom_rm ?? '' }}
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="font-medium text-ink-primary">{{ g.parentPartNumber }}</span>
                                    <div class="text-xs text-ink-secondary">{{ g.parentPartName }}</div>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink-primary">{{ fmt(g.items[0].child_qty) }}</td>
                                <td class="px-3 py-3 text-ink-secondary">{{ g.items[0].uom_rm ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    <StatusBadge :status="g.items[0].source">{{ sourceLabel(g.items[0].source) }}</StatusBadge>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink-secondary">{{ fmt(g.items[g.items.length - 1].qty_required) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums" :class="Number(g.items[g.items.length - 1].qty_consumed) < Number(g.items[g.items.length - 1].qty_required) ? 'text-danger' : 'text-success'">
                                    {{ fmt(g.items[g.items.length - 1].qty_consumed) }}
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <a
                                        v-if="workOrder.status === 'planned' && can.release"
                                        :href="route('work-orders.items.edit', [workOrder.id, g.items[0].id])"
                                        :title="t('production.editRouting')"
                                        :aria-label="t('production.editRouting')"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-md text-ink-secondary transition hover:bg-primary-light hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" /></svg>
                                    </a>
                                    <span v-else class="text-ink-secondary">—</span>
                                </td>
                            </tr>

                            <!-- KASUS B: MESIN NON-TPL / ASSEMBLY MULTI-INPUT (Menjabarkan Seluruh Material Input) -->
                            <tr v-else v-for="(it, idx) in g.items" :key="g.key + '-' + it.id" class="align-top hover:bg-primary-light/40" :class="idx > 0 ? 'bg-surface/50 border-t border-dashed border-borderline/60' : ''">
                                <!-- Sequence & Process hanya dirender jelas di baris pertama proses, baris komponen berikutnya diberi visual grouping -->
                                <td class="px-3 py-2.5 tabular-nums text-ink-secondary">
                                    <span v-if="idx === 0" class="font-semibold">{{ g.sequence }}</span>
                                    <span v-else class="text-ink-secondary/40 text-xs">↳</span>
                                </td>
                                <td class="px-3 py-2.5 text-ink-primary">
                                    <div v-if="idx === 0" class="font-medium">{{ g.processName }}</div>
                                    <div v-else class="text-xs text-ink-secondary italic">Komponen {{ idx + 1 }} dari {{ g.items.length }}</div>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-ink-primary">
                                    <span v-if="idx === 0">{{ g.machineName }}</span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="font-medium text-ink-primary">{{ displayPartInfo(it).partNumber }}</div>
                                    <div class="text-xs text-ink-secondary">{{ displayPartInfo(it).partName }}</div>
                                    <div class="mt-1 flex items-center gap-1.5">
                                        <span :class="['inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium', displayPartInfo(it).isSubstitute ? 'bg-info/15 text-info' : 'bg-background text-ink-secondary']">
                                            {{ displayPartInfo(it).badge }}
                                        </span>
                                        <span v-if="displayPartInfo(it).isSubstitute && it.child_part" class="text-[11px] text-ink-secondary">
                                            (Main: {{ it.child_part.part_number }})
                                        </span>
                                    </div>
                                    <div v-if="it.allocations?.length" class="mt-1 space-y-0.5">
                                        <div v-for="a in it.allocations" :key="a.id" class="text-xs text-ink-secondary">
                                            <span class="font-medium text-ink-primary">{{ a.part?.part_number ?? '#' + a.part_id }}</span> · {{ fmt(a.qty) }} {{ it.uom_rm ?? '' }}
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5">
                                    <div v-if="idx === 0">
                                        <span class="font-medium text-ink-primary">{{ g.parentPartNumber }}</span>
                                        <div class="text-xs text-ink-secondary">{{ g.parentPartName }}</div>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-primary">{{ fmt(it.child_qty) }}</td>
                                <td class="px-3 py-2.5 text-ink-secondary">{{ it.uom_rm ?? '—' }}</td>
                                <td class="px-3 py-2.5">
                                    <StatusBadge :status="it.source">{{ sourceLabel(it.source) }}</StatusBadge>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-secondary">{{ fmt(it.qty_required) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums" :class="Number(it.qty_consumed) < Number(it.qty_required) ? 'text-danger' : 'text-success'">
                                    {{ fmt(it.qty_consumed) }}
                                </td>
                                <td class="px-3 py-2.5 text-center">
                                    <a
                                        v-if="workOrder.status === 'planned' && can.release"
                                        :href="route('work-orders.items.edit', [workOrder.id, it.id])"
                                        :title="t('production.editRouting')"
                                        :aria-label="t('production.editRouting')"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-md text-ink-secondary transition hover:bg-primary-light hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" /></svg>
                                    </a>
                                    <span v-else class="text-ink-secondary">—</span>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="routingGroups.length === 0">
                            <td colspan="11" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('production.noRouting') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>