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

/** Kunci grup mesin: 3 karakter pertama nama mesin (mis. "TPL"). */
const machineKey = (it: WorkOrderItem) => {
    const name = (it.machine?.machine_name ?? '').trim().toUpperCase();
    return name !== '' ? name.slice(0, 3) : (it.machine_id != null ? `id:${it.machine_id}` : 'none');
};

/** Baris routing: step berurutan di grup mesin yang sama digabung jadi satu baris. */
const head = (g: { key: string; items: WorkOrderItem[] }) => g.items[0];
const tail = (g: { key: string; items: WorkOrderItem[] }) => g.items[g.items.length - 1];

const routingGroups = computed(() => {
    const groups: Array<{ key: string; items: WorkOrderItem[] }> = [];
    for (const it of items.value) {
        const key = machineKey(it);
        const last = groups[groups.length - 1];
        if (last && last.key === key) {
            last.items.push(it);
            continue;
        }
        groups.push({ key, items: [it] });
    }
    return groups;
});

const fmt = (n: number | null | undefined, decimals = 4) => {
    if (n == null) return '—';
    return Number(n).toLocaleString(locale.value, { maximumFractionDigits: decimals });
};

const sourceLabel = (source: string | null) => source && te(`production.sources.${source}`) ? t(`production.sources.${source}`) : source ?? '—';


const statusLabel = (status: string) => te(`production.statuses.${status}`) ? t(`production.statuses.${status}`) : status;


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
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">
                    {{ t('production.results') }}
                </a>
                <button v-if="workOrder.status === 'planned' && can.release" @click="doRelease" :disabled="releaseForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                    {{ releaseForm.processing ? t('production.releasing') : t('production.release') }}
                </button>
                <button v-if="workOrder.status === 'in_progress' && can.complete" @click="doComplete" :disabled="completeForm.processing"
                    class="rounded-md bg-success px-4 py-2 text-sm font-semibold text-white transition hover:bg-success/90 disabled:opacity-60">
                    {{ completeForm.processing ? '…' : t('production.complete') }}
                </button>
                <button v-if="['planned', 'in_progress'].includes(workOrder.status) && can.cancel" @click="doCancel" :disabled="cancelForm.processing"
                    class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background">
                    {{ t('production.cancel') }}
                </button>
                <button v-if="can.delete" @click="doDestroy" :disabled="deleteForm.processing"
                    class="rounded-md border border-danger/30 px-4 py-2 text-sm font-medium text-danger transition hover:bg-danger/10">
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
            <div class="border-b border-borderline px-4 py-3 text-sm font-semibold text-ink-primary">{{ t('production.routing') }}</div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th class="px-3 py-3">{{ t('production.sequence') }}</th>
                            <th class="px-3 py-3">{{ t('production.process') }}</th>
                            <th class="px-3 py-3">{{ t('production.machine') }}</th>
                            <th class="px-3 py-3">{{ t('production.materialSubstitute') }}</th>
                            <th class="px-3 py-3">{{ t('production.parent') }}</th>
                            <th class="px-3 py-3 text-right">{{ t('production.childQty') }}</th>
                            <th class="px-3 py-3">{{ t('production.uom') }}</th>
                            <th class="px-3 py-3">{{ t('production.source') }}</th>
                            <th class="px-3 py-3 text-right">{{ t('production.required') }}</th>
                            <th class="px-3 py-3 text-right">{{ t('production.consumed') }}</th>
                            <th class="px-3 py-3 text-center">{{ t('production.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="g in routingGroups" :key="g.key + '-' + head(g).id" class="align-top hover:bg-primary-light/40">
                            <td class="px-3 py-2.5 tabular-nums text-ink-secondary">{{ head(g).sequence ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-ink-primary">{{ head(g).process?.process_name ?? '—' }}</td>
                            <td class="px-3 py-2.5 whitespace-nowrap text-ink-primary">{{ head(g).machine?.machine_name ?? '—' }}</td>
                            <td class="px-3 py-2.5">
                                <div class="font-medium text-ink-primary">{{ head(g).selected_part?.part_number ?? head(g).child_part?.part_number ?? head(g).child_part_name ?? '—' }}</div>
                                <div v-if="head(g).child_part" class="text-xs text-ink-secondary">{{ t('production.mainPart', { part: head(g).child_part?.part_number }) }}</div>
                                <div v-if="head(g).selected_part?.id != null && head(g).selected_part?.id !== head(g).child_part_id" class="text-xs font-medium text-info">{{ t('production.substitute') }}</div>
                                <div v-if="head(g).allocations?.length" class="mt-1 space-y-0.5">
                                    <div v-for="a in head(g).allocations" :key="a.id" class="text-xs text-ink-secondary">
                                        <span class="font-medium text-ink-primary">{{ a.part?.part_number ?? '#' + a.part_id }}</span>
                                        · {{ fmt(a.qty) }} {{ head(g).uom_rm ?? '' }}
                                    </div>
                                </div>
                                <div v-if="g.items.length > 1" class="mt-1">
                                    <span class="rounded-md bg-background px-1.5 py-0.5 text-[11px] font-medium text-ink-secondary">{{ t('production.stepCount', { n: g.items.length }) }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="font-medium text-ink-primary">{{ tail(g).parent_part?.part_number ?? tail(g).parent_part_name ?? '—' }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-primary">{{ fmt(head(g).child_qty) }}</td>
                            <td class="px-3 py-2.5 text-ink-secondary">{{ head(g).uom_rm ?? '—' }}</td>
                            <td class="px-3 py-2.5">
                                <StatusBadge :status="head(g).source">{{ sourceLabel(head(g).source) }}</StatusBadge>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-secondary">{{ fmt(tail(g).qty_required) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums" :class="Number(tail(g).qty_consumed) < Number(tail(g).qty_required) ? 'text-danger' : 'text-success'">{{ fmt(tail(g).qty_consumed) }}</td>
                            <td class="px-3 py-2.5 text-center">
                                <a
                                    v-if="workOrder.status === 'planned' && can.release"
                                    :href="route('work-orders.items.edit', [workOrder.id, head(g).id])"
                                    :title="t('production.editRouting')"
                                    :aria-label="t('production.editRouting')"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-md text-ink-secondary transition hover:bg-primary-light hover:text-primary"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4">
                                        <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                                    </svg>
                                </a>
                                <span v-else class="text-ink-secondary">—</span>
                            </td>
                        </tr>
                        <tr v-if="routingGroups.length === 0">
                            <td colspan="11" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('production.noRouting') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>