<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import Modal from '@/Components/Modal.vue';
import FinishedGoodPicker from '@/Components/FinishedGoodPicker.vue';
import InputError from '@/Components/InputError.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type {
    AvailWorkOrderSummary,
    Part,
    ProductionPlan,
    ProductionPlanBoardMachine,
    ProductionPlanMachineRow,
} from '@/types';

type PartOpt = Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'>;
type DayKey = 'd' | 'd1' | 'd2';
type PlanHistory = {
    id: number;
    production_plan_item_id: number | null;
    event: 'work_order_added' | 'targets_updated' | 'item_updated' | 'sequence_updated' | 'item_detached';
    before: Record<string, number | null> | null;
    after: Record<string, number | string | null> | null;
    created_at: string;
    user: { id: number; name: string } | null;
};

interface ModalRowDraft {
    work_order_id: number;
    wo_no: string;
    wo_status: string;
    remaining_qty: number;
    fg_part: PartOpt | null;
    wip_part: PartOpt | null;
    cycle_time_seconds: number | null;
    qdc_minutes: number | null;
    d_seq: string;
    d_qty: string;
    d1_seq: string;
    d1_qty: string;
    d2_seq: string;
    d2_qty: string;
}

const { t, locale } = useI18n();

const props = defineProps<{
    date: string;
    dates: { d: string; d1: string; d2: string };
    plan: ProductionPlan | null;
    machines: ProductionPlanBoardMachine[];
    availWorkOrders: AvailWorkOrderSummary[];
    fgParts: PartOpt[];
    histories: PlanHistory[];
    canManage: boolean;
    canCreateWo: boolean;
}>();

const date = ref(props.date);
const search = ref('');
const machineFilter = ref('');

watch(() => props.date, (next) => {
    date.value = next;
});

function applyDate() {
    router.get(route('production-plans.index'), { date: date.value || undefined }, { preserveState: true, replace: true });
}

function fmtDate(value: string): string {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString(locale.value, { timeZone: 'UTC', day: '2-digit', month: 'short' });
}

const dayKeys: DayKey[] = ['d', 'd1', 'd2'];

const dayLabels = computed(() => ({
    d: fmtDate(props.dates.d),
    d1: fmtDate(props.dates.d1),
    d2: fmtDate(props.dates.d2),
}));

const fmt = (n: number | null | undefined) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 2 });

const summary = computed(() => {
    const plannedMachineCount = props.machines.filter((m) => m.planned_rows.length > 0).length;
    const plannedWoIds = new Set<number>();
    for (const m of props.machines) {
        for (const r of m.planned_rows) {
            plannedWoIds.add(r.work_order_id);
        }
    }
    return {
        plannedMachines: plannedMachineCount,
        plannedWo: plannedWoIds.size,
        availWo: props.availWorkOrders.length,
    };
});

function processNames(rows: ProductionPlanMachineRow[]): string {
    return [...new Set(rows.map((r) => r.process_name).filter(Boolean))].join(' · ');
}

function statusTone(status: string): 'info' | 'warning' | 'success' | 'danger' {
    if (status === 'in_progress') return 'info';
    if (status === 'completed') return 'success';
    if (status === 'cancelled') return 'danger';
    return 'warning';
}

const filteredMachines = computed(() => {
    const q = search.value.trim().toLowerCase();
    const mq = machineFilter.value.trim().toLowerCase();

    return props.machines
        .filter((m) => mq === '' || `${m.machine_name} ${m.machine_code}`.toLowerCase().includes(mq))
        .map((m) => {
            if (!q) return m;
            const matchingRows = m.planned_rows.filter((r) =>
                [
                    r.wo_no,
                    r.fg_part?.part_number,
                    r.fg_part?.part_name,
                    r.fg_part?.model,
                    r.input_part?.part_number,
                    r.wip_part?.part_number,
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase()
                    .includes(q),
            );
            return { ...m, planned_rows: matchingRows };
        })
        .filter((m) => q === '' || m.planned_rows.length > 0);
});

// ── History Modal ─────────────────────────────────────────
const showHistory = ref(false);

function fmtDateTime(value: string): string {
    return new Date(value).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' });
}

function historyEventLabel(event: PlanHistory['event']): string {
    return t(`production.historyEvents.${event}`);
}

function historyDetail(history: PlanHistory): string {
    if (history.event === 'targets_updated') {
        const before = history.before ?? {};
        const after = history.after ?? {};
        const labels: Record<string, string> = {
            target_d: t('production.qtyShort'),
            sequence_d: t('production.sequenceShort'),
        };
        return Object.keys(labels)
            .filter((key) => before[key] !== after[key])
            .map((key) => `${labels[key]}: ${fmt(before[key] as number | null)} → ${fmt(after[key] as number | null)}`)
            .join(' · ');
    }
    if (history.event === 'work_order_added') return String(history.after?.wo_no ?? '—');
    if (history.event === 'sequence_updated') return `${fmt(history.before?.sequence as number | null)} → ${fmt(history.after?.sequence as number | null)}`;
    return '';
}

// ── Standalone Create WO Modal (toolbar button) ───────────
const showCreateWo = ref(false);
const createWoForm = useForm({
    plan_date: props.date,
    fg_part_id: '',
    qty: '',
});

function openCreateWo() {
    createWoForm.reset();
    createWoForm.clearErrors();
    createWoForm.plan_date = props.date;
    showCreateWo.value = true;
}

function submitCreateWo() {
    createWoForm.post(route('production-plans.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreateWo.value = false;
            createWoForm.reset();
        },
    });
}

// ── Machine Add / Edit Plan Modal ─────────────────────────
const activeMachineId = ref<number | null>(null);
const showMachineModal = computed(() => activeMachineId.value !== null);
const activeMachine = computed<ProductionPlanBoardMachine | null>(() =>
    activeMachineId.value === null
        ? null
        : props.machines.find((m) => m.id === activeMachineId.value) ?? null,
);

const machinePlanForm = useForm<{
    date: string;
    initial_qdc: { d: boolean; d1: boolean; d2: boolean };
    rows: ModalRowDraft[];
}>({
    date: props.date,
    initial_qdc: { d: false, d1: false, d2: false },
    rows: [],
});

const showInlineCreateWo = ref(false);
const showAllFgInInlineCreate = ref(false);
const inlineWoForm = useForm({
    plan_date: props.date,
    fg_part_id: '',
    qty: '',
});

const inlineFgOptions = computed<PartOpt[]>(() => {
    const machine = activeMachine.value;
    if (!machine || showAllFgInInlineCreate.value || machine.fg_part_ids.length === 0) {
        return props.fgParts;
    }
    const allowed = new Set(machine.fg_part_ids);
    const filtered = props.fgParts.filter((p) => allowed.has(p.id));
    return filtered.length > 0 ? filtered : props.fgParts;
});

function toModalDraft(row: ProductionPlanMachineRow): ModalRowDraft {
    return {
        work_order_id: row.work_order_id,
        wo_no: row.wo_no,
        wo_status: row.wo_status,
        remaining_qty: row.remaining_qty,
        fg_part: row.fg_part,
        wip_part: row.wip_part,
        cycle_time_seconds: row.cycle_time_seconds,
        qdc_minutes: row.qdc_minutes,
        d_seq: row.days.d ? String(row.days.d.sequence) : '',
        d_qty: row.days.d ? String(row.days.d.qty) : '',
        d1_seq: row.days.d1 ? String(row.days.d1.sequence) : '',
        d1_qty: row.days.d1 ? String(row.days.d1.qty) : '',
        d2_seq: row.days.d2 ? String(row.days.d2.sequence) : '',
        d2_qty: row.days.d2 ? String(row.days.d2.qty) : '',
    };
}

function openMachineModal(machineId: number) {
    const machine = props.machines.find((m) => m.id === machineId);
    if (!machine) return;

    activeMachineId.value = machine.id;
    machinePlanForm.clearErrors();
    machinePlanForm.date = props.date;
    machinePlanForm.initial_qdc = {
        d: machine.initial_qdc.d,
        d1: machine.initial_qdc.d1,
        d2: machine.initial_qdc.d2,
    };
    machinePlanForm.rows = machine.modal_rows.map(toModalDraft);
    showInlineCreateWo.value = false;
    showAllFgInInlineCreate.value = false;
    inlineWoForm.reset();
    inlineWoForm.clearErrors();
}

function closeMachineModal() {
    activeMachineId.value = null;
    showInlineCreateWo.value = false;
}

// Bila WO baru dibuat dari dalam modal, sinkronkan baris WO baru ke modal tanpa menghapus ketikan user pada baris lain.
watch(
    () => activeMachine.value?.modal_rows,
    (nextRows) => {
        if (!showMachineModal.value || !nextRows) return;
        const existingByWo = new Map(machinePlanForm.rows.map((r) => [r.work_order_id, r]));
        machinePlanForm.rows = nextRows.map((row) => {
            const kept = existingByWo.get(row.work_order_id);
            if (!kept) return toModalDraft(row);
            return {
                ...kept,
                remaining_qty: row.remaining_qty,
                wo_status: row.wo_status,
            };
        });
    },
);

function numOrZero(val: string): number {
    if (val === '' || val == null) return 0;
    const n = Number(val);
    return Number.isFinite(n) && n > 0 ? n : 0;
}

function plannedSumForDraft(row: ModalRowDraft): number {
    return Number((numOrZero(row.d_qty) + numOrZero(row.d1_qty) + numOrZero(row.d2_qty)).toFixed(4));
}

function liveAvailForDraft(row: ModalRowDraft): number {
    return Number((row.remaining_qty - plannedSumForDraft(row)).toFixed(4));
}

const hasAnyOverLimit = computed(() =>
    machinePlanForm.rows.some((row) => liveAvailForDraft(row) < -1e-6),
);

function rowServerError(idx: number): string | undefined {
    const errors = machinePlanForm.errors as Record<string, string | undefined>;
    return errors[`rows.${idx}.qty`] ?? errors[`rows.${idx}.work_order_id`];
}

function submitMachinePlan() {
    if (!activeMachine.value || hasAnyOverLimit.value) return;

    const toNullableNum = (v: string) => (v === '' ? null : Number(v));

    machinePlanForm
        .transform((data) => ({
            date: props.date,
            initial_qdc: data.initial_qdc,
            rows: data.rows.map((r) => ({
                work_order_id: r.work_order_id,
                d_seq: toNullableNum(r.d_seq),
                d_qty: toNullableNum(r.d_qty),
                d1_seq: toNullableNum(r.d1_seq),
                d1_qty: toNullableNum(r.d1_qty),
                d2_seq: toNullableNum(r.d2_seq),
                d2_qty: toNullableNum(r.d2_qty),
            })),
        }))
        .put(route('production-plans.machines.save', activeMachine.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                closeMachineModal();
            },
        });
}

function openInlineCreateWo(fgPartId?: number | null) {
    inlineWoForm.reset();
    inlineWoForm.clearErrors();
    inlineWoForm.plan_date = props.date;
    if (fgPartId != null) {
        inlineWoForm.fg_part_id = String(fgPartId);
        showAllFgInInlineCreate.value = false;
    }
    showInlineCreateWo.value = true;
}

function submitInlineCreateWo() {
    inlineWoForm.post(route('production-plans.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            showInlineCreateWo.value = false;
            inlineWoForm.reset();
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('production.plan')" />
        <BackButton :href="route('production-data')" class="mb-4" />

        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.plan') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ t('production.planDateDescription', { date: fmtDate(date), d: dayLabels.d, d1: dayLabels.d1, d2: dayLabels.d2 }) }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border border-borderline bg-surface px-4 py-2 text-sm font-semibold text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    @click="showHistory = true"
                >
                    {{ t('production.planHistory') }}
                </button>
                <button
                    v-if="canCreateWo"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    @click="openCreateWo()"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    {{ t('production.newWo') }}
                </button>
            </div>
        </div>

        <!-- Toolbar: filter + ringkasan papan -->
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-borderline bg-surface p-3">
            <div class="min-w-56 flex-1">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.search') }}</label>
                <input
                    v-model="search"
                    type="search"
                    :aria-label="t('production.search')"
                    :placeholder="t('production.searchPlan')"
                    class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary placeholder-ink-secondary focus:border-primary focus:ring-primary"
                />
            </div>
            <div class="min-w-48">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.machineFilter') }}</label>
                <input
                    v-model="machineFilter"
                    type="search"
                    :aria-label="t('production.machineFilter')"
                    :placeholder="t('production.machineFilterPlaceholder')"
                    class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary placeholder-ink-secondary focus:border-primary focus:ring-primary"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.startDate') }}</label>
                <input
                    v-model="date"
                    type="date"
                    :aria-label="t('production.startDate')"
                    class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                    @change="applyDate"
                />
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-borderline bg-background px-2.5 py-1.5 text-xs text-ink-secondary">
                    <span class="font-semibold text-ink-primary">{{ summary.plannedMachines }}</span> {{ t('production.plannedMachinesLabel') }}
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-borderline bg-background px-2.5 py-1.5 text-xs text-ink-secondary">
                    <span class="font-semibold text-ink-primary">{{ summary.plannedWo }}</span> {{ t('production.woLabel') }}
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-warning/40 bg-warning/10 px-2.5 py-1.5 text-xs text-warning">
                    <span class="font-semibold">{{ summary.availWo }}</span> {{ t('production.availWoShort') }}
                </span>
            </div>
        </div>

        <!-- Panel Avail WO di atas papan -->
        <div v-if="availWorkOrders.length" class="mb-4 rounded-xl border border-warning/40 bg-warning/5 p-4">
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-warning">
                    {{ t('production.availWoTitle', { count: availWorkOrders.length }) }}
                </h2>
            </div>
            <p class="mb-3 text-xs text-ink-secondary">{{ t('production.availWoHint') }}</p>
            <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="wo in availWorkOrders"
                    :key="wo.id"
                    class="rounded-lg border border-borderline bg-surface p-3 text-xs"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <Link
                                :href="route('work-orders.show', wo.id)"
                                class="font-semibold text-ink-primary hover:text-primary"
                            >
                                {{ wo.wo_no }}
                            </Link>
                            <div class="mt-0.5 truncate font-medium text-ink-primary">
                                {{ wo.part?.part_number ?? '—' }}
                                <span v-if="wo.part?.model" class="font-normal text-ink-secondary">· {{ wo.part.model }}</span>
                            </div>
                            <div v-if="wo.part?.part_name" class="truncate text-[11px] text-ink-secondary">{{ wo.part.part_name }}</div>
                        </div>
                        <StatusBadge :tone="statusTone(wo.status)">{{ t(`production.statuses.${wo.status}`) }}</StatusBadge>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <button
                            v-for="m in wo.machines"
                            :key="m.machine_id"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                            :class="m.avail_qty > 0
                                ? 'border-warning/50 bg-warning/10 text-ink-primary hover:border-primary hover:bg-primary-light/50'
                                : 'border-success/40 bg-success/10 text-success'"
                            @click="openMachineModal(m.machine_id)"
                        >
                            <span>{{ m.machine_name }}:</span>
                            <span class="tabular-nums font-semibold">{{ fmt(m.avail_qty) }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Papan Harian (Read-Only) -->
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-borderline px-4 py-3">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-sm font-semibold text-ink-primary">{{ t('production.boardTitle') }}</h2>
                    <span class="text-xs text-ink-secondary">{{ t('production.boardReadOnlyHint') }}</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1380px] table-fixed border-separate border-spacing-0 text-sm">
                    <colgroup>
                        <col class="w-48" />
                        <col class="w-44" />
                        <col class="w-28" />
                        <col class="w-36" />
                        <col class="w-36" />
                        <col class="w-28" />
                        <col class="w-44" />
                        <col class="w-44" />
                        <col class="w-44" />
                        <col class="w-24" />
                    </colgroup>
                    <thead class="sticky top-0 z-10 bg-background">
                        <tr class="border-b border-borderline text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="sticky left-0 z-30 border-r border-borderline bg-background px-3 py-2.5">{{ t('production.machine') }}</th>
                            <th scope="col" class="px-3 py-2.5">{{ t('production.fgPart') }}</th>
                            <th scope="col" class="px-3 py-2.5">{{ t('master.model') }}</th>
                            <th scope="col" class="px-3 py-2.5">{{ t('production.inputPart') }}</th>
                            <th scope="col" class="px-3 py-2.5">{{ t('production.outputPart') }}</th>
                            <th scope="col" class="px-3 py-2.5 text-right">{{ t('production.availWoShort') }}</th>
                            <th scope="col" v-for="key in dayKeys" :key="key" class="border-l border-borderline px-3 py-2.5 text-center">
                                <div>{{ dayLabels[key] }}</div>
                                <div class="text-[10px] font-normal uppercase text-ink-secondary">
                                    {{ key === 'd' ? 'D' : (key === 'd1' ? 'D+1' : 'D+2') }}
                                </div>
                            </th>
                            <th scope="col" class="border-l border-borderline bg-background px-3 py-2.5 text-right">{{ t('production.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(machine, mi) in filteredMachines" :key="machine.id">
                            <!-- Mesin kosong (belum ada plan di D / D+1 / D+2) -->
                            <tr
                                v-if="machine.planned_rows.length === 0"
                                class="group border-t border-borderline hover:bg-primary-light/20"
                                :class="mi > 0 ? 'border-t-2 !border-t-ink-secondary/30' : ''"
                            >
                                <td class="sticky left-0 z-10 border-r border-borderline bg-surface px-3 py-3 align-top group-hover:bg-primary-light/20">
                                    <div class="flex flex-col items-start gap-1.5">
                                        <span class="font-semibold leading-tight text-ink-primary">{{ machine.machine_name }}</span>
                                        <span
                                            v-if="machine.avail_wo_count > 0"
                                            class="inline-flex rounded-md border border-warning/40 bg-warning/10 px-1.5 py-0.5 text-[11px] font-medium text-warning"
                                        >
                                            {{ t('production.availWoBadge', { n: machine.avail_wo_count }) }}
                                        </span>
                                    </div>
                                </td>
                                <td colspan="5" class="px-3 py-3 text-xs text-ink-secondary">
                                    {{ t('production.noMachinePlanYet') }}
                                </td>
                                <td v-for="key in dayKeys" :key="key" class="border-l border-borderline px-3 py-3 text-center text-xs text-ink-secondary">—</td>
                                <td class="border-l border-borderline bg-surface px-3 py-3 text-right group-hover:bg-primary-light/20">
                                    <button
                                        v-if="canManage"
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-md bg-primary px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                        @click="openMachineModal(machine.id)"
                                    >
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        {{ t('production.addPlan') }}
                                    </button>
                                </td>
                            </tr>

                            <!-- Mesin dengan rencana D / D+1 / D+2 -->
                            <tr
                                v-for="(row, rIdx) in machine.planned_rows"
                                :key="`${machine.id}-${row.work_order_id}`"
                                class="group border-t border-borderline transition hover:bg-primary-light/30"
                                :class="rIdx === 0 && mi > 0 ? 'border-t-2 !border-t-ink-secondary/30' : ''"
                            >
                                <td
                                    v-if="rIdx === 0"
                                    class="sticky left-0 z-10 border-r border-borderline bg-surface px-3 py-2.5 align-top group-hover:bg-primary-light/30"
                                    :rowspan="machine.planned_rows.length"
                                >
                                    <div class="flex flex-col items-start gap-1.5">
                                        <span class="font-semibold leading-tight text-ink-primary">{{ machine.machine_name }}</span>
                                        <span v-if="processNames(machine.planned_rows)" class="text-[11px] tracking-wide text-ink-secondary">{{ processNames(machine.planned_rows) }}</span>
                                        <div class="flex flex-wrap items-center gap-1">
                                            <span class="inline-flex rounded-md bg-background px-1.5 py-0.5 text-[11px] font-medium text-ink-secondary">
                                                {{ t('production.rowsCount', { n: machine.planned_rows.length }) }}
                                            </span>
                                            <span
                                                v-if="machine.avail_wo_count > 0"
                                                class="inline-flex rounded-md border border-warning/40 bg-warning/10 px-1.5 py-0.5 text-[11px] font-medium text-warning"
                                            >
                                                {{ t('production.availWoBadge', { n: machine.avail_wo_count }) }}
                                            </span>
                                        </div>
                                        <button
                                            v-if="canManage"
                                            type="button"
                                            class="mt-1 inline-flex items-center gap-1 rounded-md border border-primary/40 bg-primary-light/40 px-2 py-1 text-[11px] font-semibold text-primary transition hover:bg-primary hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                            @click="openMachineModal(machine.id)"
                                        >
                                            {{ t('production.addEditPlan') }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 align-top">
                                    <div class="font-medium text-ink-primary">{{ row.fg_part?.part_number ?? '—' }}</div>
                                    <div v-if="row.fg_part?.part_name" class="truncate text-xs text-ink-secondary">{{ row.fg_part.part_name }}</div>
                                    <div class="mt-1 flex items-center gap-1.5 text-[11px]">
                                        <Link :href="route('work-orders.show', row.work_order_id)" class="font-medium text-primary hover:underline">{{ row.wo_no }}</Link>
                                        <StatusBadge :tone="statusTone(row.wo_status)">{{ t(`production.statuses.${row.wo_status}`) }}</StatusBadge>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 align-top text-ink-primary">{{ row.fg_part?.model || '—' }}</td>
                                <td class="px-3 py-2.5 align-top">
                                    <div class="text-ink-primary">{{ row.input_part?.part_number ?? '—' }}</div>
                                    <div v-if="row.input_part?.part_name" class="truncate text-xs text-ink-secondary">{{ row.input_part.part_name }}</div>
                                </td>
                                <td class="px-3 py-2.5 align-top">
                                    <div class="font-medium text-ink-primary">{{ row.wip_part?.part_number ?? '—' }}</div>
                                    <div v-if="row.wip_part?.part_name" class="truncate text-xs text-ink-secondary">{{ row.wip_part.part_name }}</div>
                                </td>
                                <td class="px-3 py-2.5 text-right align-top">
                                    <div
                                        class="tabular-nums font-bold"
                                        :class="row.avail_qty === 0 ? 'text-success' : 'text-warning'"
                                    >
                                        {{ fmt(row.avail_qty) }}
                                    </div>
                                    <div class="mt-0.5 text-[11px] tabular-nums text-ink-secondary">
                                        {{ t('production.unproducedRemain', { qty: fmt(row.remaining_qty) }) }}
                                    </div>
                                </td>
                                <td
                                    v-for="key in dayKeys"
                                    :key="key"
                                    class="border-l border-borderline px-3 py-2.5 align-top"
                                    :class="row.days[key]?.is_overload ? 'bg-danger/10' : ''"
                                >
                                    <template v-if="row.days[key]">
                                        <div class="flex items-center justify-between gap-2 text-xs">
                                            <span class="inline-flex rounded bg-background px-1.5 py-0.5 font-semibold tabular-nums text-ink-secondary">
                                                #{{ row.days[key]!.sequence }}
                                            </span>
                                            <span class="tabular-nums font-bold text-ink-primary">
                                                {{ fmt(row.days[key]!.qty) }}
                                            </span>
                                        </div>
                                        <div
                                            v-if="row.days[key]!.start_time && row.days[key]!.end_time"
                                            class="mt-1.5 flex items-center justify-between gap-1 text-xs tabular-nums"
                                            :class="row.days[key]!.is_overload ? 'font-semibold text-danger' : 'font-medium text-primary'"
                                        >
                                            <span>{{ row.days[key]!.start_time }} – {{ row.days[key]!.end_time }}</span>
                                            <span
                                                v-if="row.days[key]!.is_overload"
                                                class="rounded bg-danger px-1 py-0.5 text-[10px] font-semibold uppercase text-white"
                                            >
                                                {{ t('production.overloadTag') }}
                                            </span>
                                        </div>
                                        <div v-else class="mt-1.5 text-[11px] text-ink-secondary">—</div>
                                        <div
                                            v-if="row.days[key]!.qdc_applied_minutes > 0"
                                            class="mt-1 inline-flex rounded bg-info/10 px-1.5 py-0.5 text-[10px] font-medium tabular-nums text-info"
                                        >
                                            {{ t('production.qdcShortTag', { minutes: fmt(row.days[key]!.qdc_applied_minutes) }) }}
                                        </div>
                                    </template>
                                    <div v-else class="text-center text-xs text-ink-secondary">—</div>
                                </td>
                                <td class="border-l border-borderline bg-surface px-3 py-2.5 align-top group-hover:bg-primary-light/30">
                                    <div class="flex justify-end gap-1">
                                        <Link
                                            :href="route('work-orders.show', row.work_order_id)"
                                            :title="t('production.viewWo')"
                                            :aria-label="t('production.viewWo')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-info transition hover:bg-info/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                        >
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                        </Link>
                                        <button
                                            v-if="canManage"
                                            type="button"
                                            :title="t('production.addEditPlan')"
                                            :aria-label="t('production.addEditPlan')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-primary transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                            @click="openMachineModal(machine.id)"
                                        >
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="filteredMachines.length === 0">
                            <td colspan="10" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('production.noPlanRows') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal: Add / Edit Plan per Mesin -->
        <Modal :show="showMachineModal" max-width="5xl" @close="closeMachineModal">
            <div v-if="activeMachine" class="p-6">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3 border-b border-borderline pb-4">
                    <div>
                        <h3 class="text-lg font-bold text-ink-primary">
                            {{ t('production.machinePlanModalTitle', { machine: activeMachine.machine_name }) }}
                        </h3>
                        <p class="mt-1 text-xs text-ink-secondary">{{ t('production.machinePlanModalHint') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="canCreateWo"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md border border-primary bg-primary-light/40 px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                            @click="openInlineCreateWo()"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            {{ t('production.newWo') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-borderline px-3 py-1.5 text-xs font-medium text-ink-secondary hover:bg-background"
                            @click="closeMachineModal"
                        >
                            {{ t('production.cancel') }}
                        </button>
                    </div>
                </div>

                <!-- Drawer cepat: Buat WO Baru dari dalam pop-up -->
                <form
                    v-if="showInlineCreateWo"
                    class="mb-4 rounded-xl border border-primary/40 bg-primary-light/20 p-4"
                    @submit.prevent="submitInlineCreateWo"
                >
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h4 class="text-sm font-semibold text-ink-primary">{{ t('production.addWo') }}</h4>
                            <p class="text-xs text-ink-secondary">{{ t('production.createWoForMachineHint') }}</p>
                        </div>
                        <button
                            v-if="activeMachine.fg_part_ids.length > 0"
                            type="button"
                            class="text-xs font-medium text-primary hover:underline"
                            @click="showAllFgInInlineCreate = !showAllFgInInlineCreate"
                        >
                            {{ showAllFgInInlineCreate ? t('production.onlyMachineFg') : t('production.showAllFg') }}
                        </button>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-7">
                            <label class="mb-1 block text-xs font-medium text-ink-primary">{{ t('production.finishedGood') }}</label>
                            <FinishedGoodPicker v-model="inlineWoForm.fg_part_id" :options="inlineFgOptions" />
                            <InputError :message="inlineWoForm.errors.fg_part_id" class="mt-1" />
                        </div>
                        <div class="sm:col-span-3">
                            <label class="mb-1 block text-xs font-medium text-ink-primary">{{ t('production.woQty') }}</label>
                            <input
                                v-model="inlineWoForm.qty"
                                type="number"
                                step="any"
                                min="0.0001"
                                :aria-label="t('production.woQty')"
                                class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                            />
                            <InputError :message="inlineWoForm.errors.qty" class="mt-1" />
                        </div>
                        <div class="flex items-end gap-2 sm:col-span-2">
                            <button
                                type="submit"
                                :disabled="inlineWoForm.processing"
                                class="w-full rounded-md bg-primary px-3 py-2 text-xs font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
                            >
                                {{ inlineWoForm.processing ? t('production.saving') : t('production.saveWo') }}
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-borderline bg-surface px-2.5 py-2 text-xs text-ink-secondary hover:bg-background"
                                @click="showInlineCreateWo = false"
                            >
                                ×
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Pengaturan QDC Awal (Seq 1) per hari D / D+1 / D+2 -->
                <div class="mb-4 rounded-lg border border-borderline bg-background px-4 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-xs font-semibold text-ink-primary">{{ t('production.initialQdcLabel') }}</div>
                            <div class="text-[11px] text-ink-secondary">{{ t('production.initialQdcHint') }}</div>
                        </div>
                        <div class="flex flex-wrap items-center gap-4">
                            <label
                                v-for="key in dayKeys"
                                :key="key"
                                class="inline-flex cursor-pointer items-center gap-2 text-xs font-medium text-ink-primary"
                            >
                                <input
                                    v-model="machinePlanForm.initial_qdc[key]"
                                    type="checkbox"
                                    class="rounded border-borderline text-primary focus:ring-primary"
                                />
                                <span>{{ key === 'd' ? 'D' : (key === 'd1' ? 'D+1' : 'D+2') }} ({{ dayLabels[key] }})</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Tabel input Add/Edit Plan -->
                <form @submit.prevent="submitMachinePlan">
                    <div class="max-h-[55vh] overflow-auto rounded-xl border border-borderline">
                        <table class="min-w-full divide-y divide-borderline text-xs">
                            <thead class="sticky top-0 z-10 bg-background">
                                <tr class="text-left font-semibold uppercase tracking-wide text-ink-secondary">
                                    <th scope="col" class="px-3 py-2.5">{{ t('production.fgPart') }} + {{ t('master.model') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-right">{{ t('production.availWoQty') }}</th>
                                    <th scope="col" v-for="key in dayKeys" :key="key" class="border-l border-borderline px-2 py-2.5 text-center">
                                        {{ key === 'd' ? 'D' : (key === 'd1' ? 'D+1' : 'D+2') }} · {{ dayLabels[key] }}
                                    </th>
                                    <th scope="col" class="border-l border-borderline px-3 py-2.5 text-right">{{ t('production.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-borderline bg-surface">
                                <tr
                                    v-for="(row, idx) in machinePlanForm.rows"
                                    :key="row.work_order_id"
                                    :class="liveAvailForDraft(row) < -1e-6 ? 'bg-danger/5' : ''"
                                >
                                    <td class="px-3 py-2.5 align-top">
                                        <div class="font-semibold text-ink-primary">
                                            {{ row.fg_part?.part_number ?? '—' }}
                                            <span class="font-normal text-ink-secondary">· {{ row.fg_part?.model || '—' }}</span>
                                        </div>
                                        <div v-if="row.fg_part?.part_name" class="text-[11px] text-ink-secondary">{{ row.fg_part.part_name }}</div>
                                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
                                            <span class="font-medium text-primary">{{ row.wo_no }}</span>
                                            <StatusBadge :tone="statusTone(row.wo_status)">{{ t(`production.statuses.${row.wo_status}`) }}</StatusBadge>
                                            <span v-if="row.wip_part?.part_number" class="text-ink-secondary">→ {{ row.wip_part.part_number }}</span>
                                        </div>
                                        <InputError :message="rowServerError(idx)" class="mt-1" />
                                    </td>
                                    <td class="px-3 py-2.5 text-right align-top">
                                        <div
                                            class="tabular-nums text-sm font-bold"
                                            :class="liveAvailForDraft(row) < -1e-6 ? 'text-danger' : (liveAvailForDraft(row) === 0 ? 'text-success' : 'text-warning')"
                                        >
                                            {{ fmt(liveAvailForDraft(row)) }}
                                        </div>
                                        <div class="mt-0.5 text-[11px] tabular-nums text-ink-secondary">
                                            {{ t('production.unproducedRemain', { qty: fmt(row.remaining_qty) }) }}
                                        </div>
                                    </td>
                                    <td v-for="key in dayKeys" :key="key" class="border-l border-borderline px-2 py-2 align-top">
                                        <div class="grid gap-1">
                                            <label class="flex items-center gap-1">
                                                <span class="w-8 text-[10px] font-medium uppercase text-ink-secondary">{{ t('production.sequenceShort') }}</span>
                                                <input
                                                    v-model="row[`${key}_seq`]"
                                                    type="number"
                                                    step="1"
                                                    min="1"
                                                    inputmode="numeric"
                                                    placeholder="1"
                                                    :aria-label="`${t('production.sequence')} ${dayLabels[key]} ${row.wo_no}`"
                                                    class="w-16 rounded-md border border-borderline bg-background px-2 py-1 text-right text-xs tabular-nums text-ink-primary focus:border-primary focus:ring-primary"
                                                />
                                            </label>
                                            <label class="flex items-center gap-1">
                                                <span class="w-8 text-[10px] font-medium uppercase text-ink-secondary">{{ t('production.qtyShort') }}</span>
                                                <input
                                                    v-model="row[`${key}_qty`]"
                                                    type="number"
                                                    step="any"
                                                    min="0"
                                                    inputmode="decimal"
                                                    placeholder="0"
                                                    :aria-label="`${t('production.qtyShort')} ${dayLabels[key]} ${row.wo_no}`"
                                                    class="w-24 rounded-md border px-2 py-1 text-right text-xs tabular-nums text-ink-primary focus:border-primary focus:ring-primary"
                                                    :class="row[`${key}_qty`] !== '' ? 'border-primary/50 bg-primary-light/30' : 'border-borderline bg-background'"
                                                />
                                            </label>
                                        </div>
                                    </td>
                                    <td class="border-l border-borderline px-3 py-2.5 text-right align-top">
                                        <button
                                            v-if="canCreateWo"
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded-md border border-borderline bg-background px-2.5 py-1.5 text-[11px] font-semibold text-ink-primary transition hover:border-primary hover:text-primary"
                                            @click="openInlineCreateWo(row.fg_part?.id)"
                                        >
                                            + {{ t('production.createWoAction') }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="machinePlanForm.rows.length === 0">
                                    <td colspan="6" class="px-4 py-10 text-center text-sm text-ink-secondary">
                                        {{ t('production.noAvailWoForMachine') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-if="hasAnyOverLimit"
                        class="mt-3 rounded-lg border border-danger/40 bg-danger/10 px-3 py-2 text-xs font-medium text-danger"
                    >
                        {{ t('production.qtyPlanExceedsAvail') }}
                    </div>

                    <div class="mt-4 flex items-center justify-end gap-3 border-t border-borderline pt-4">
                        <button
                            type="button"
                            class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background"
                            @click="closeMachineModal"
                        >
                            {{ t('production.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="machinePlanForm.processing || hasAnyOverLimit"
                            class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ machinePlanForm.processing ? t('production.saving') : t('production.savePlan') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Modal: History -->
        <Modal :show="showHistory" max-width="2xl" @close="showHistory = false">
            <div class="p-6">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-ink-primary">{{ t('production.planHistory') }}</h3>
                        <p class="mt-1 text-sm text-ink-secondary">{{ t('production.historyForDate', { date: fmtDate(props.date) }) }}</p>
                    </div>
                    <button type="button" class="text-sm text-ink-secondary hover:text-ink-primary" @click="showHistory = false">{{ t('production.cancel') }}</button>
                </div>
                <div v-if="histories.length" class="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
                    <div v-for="history in histories" :key="history.id" class="rounded-lg border border-borderline bg-background p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold text-ink-primary">{{ historyEventLabel(history.event) }}</span>
                            <span class="text-xs text-ink-secondary">{{ fmtDateTime(history.created_at) }}</span>
                        </div>
                        <p v-if="historyDetail(history)" class="mt-1 text-sm text-ink-secondary">{{ historyDetail(history) }}</p>
                        <p class="mt-1 text-xs text-ink-secondary">{{ history.user?.name ?? t('production.unknownUser') }}</p>
                    </div>
                </div>
                <p v-else class="rounded-lg border border-dashed border-borderline p-6 text-center text-sm text-ink-secondary">{{ t('production.noPlanHistory') }}</p>
            </div>
        </Modal>

        <!-- Modal: WO Baru (tombol toolbar atas) -->
        <Modal :show="showCreateWo" max-width="lg" @close="showCreateWo = false">
            <form class="space-y-4 p-6" @submit.prevent="submitCreateWo">
                <h3 class="text-lg font-bold text-ink-primary">{{ t('production.addWo') }}</h3>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.finishedGood') }}</label>
                    <FinishedGoodPicker v-model="createWoForm.fg_part_id" :options="fgParts" />
                    <InputError :message="createWoForm.errors.fg_part_id" class="mt-1" />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.woQty') }}</label>
                    <input
                        v-model="createWoForm.qty"
                        type="number"
                        step="any"
                        min="0.0001"
                        :aria-label="t('production.woQty')"
                        class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                    />
                    <InputError :message="createWoForm.errors.qty" class="mt-1" />
                </div>

                <p class="rounded-lg border border-borderline bg-background px-3 py-2 text-xs text-ink-secondary">{{ t('production.createWoForMachineHint') }}</p>

                <div class="flex items-center justify-end gap-3 border-t border-borderline pt-4">
                    <button type="button" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background" @click="showCreateWo = false">{{ t('production.cancel') }}</button>
                    <button type="submit" :disabled="createWoForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                        {{ createWoForm.processing ? t('production.saving') : t('production.saveWo') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
