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
    origin_machine_id: number;
    origin_machine_name: string | null;
    is_reassigned: boolean;
    wo_no: string;
    wo_status: string;
    remaining_qty: number;
    has_upstream: boolean;
    day_caps: { d: number; d1: number; d2: number };
    fg_part: PartOpt | null;
    wip_part: PartOpt | null;
    cycle_time_seconds: number | null;
    qdc_minutes: number | null;
    d_seq: string;
    d_qty: string;
    d_qdc: boolean;
    d_qdc_touched: boolean;
    d_machine_id: number;
    d1_seq: string;
    d1_qty: string;
    d1_qdc: boolean;
    d1_qdc_touched: boolean;
    d1_machine_id: number;
    d2_seq: string;
    d2_qty: string;
    d2_qdc: boolean;
    d2_qdc_touched: boolean;
    d2_machine_id: number;
}

interface OverloadPrompt {
    dayKey: DayKey;
    nextDayKey: DayKey | null;
    shifts: number;
    rowIndex: number;
    woNo: string;
    partLabel: string;
    maxQty: number;
    overflowQty: number;
}

interface UnusedShiftItem {
    dayKey: DayKey;
    dayLabel: string;
    shifts: number;
    remainMinutes: number;
}

const { t, locale } = useI18n();

const props = defineProps<{
    date: string;
    dates: { d: string; d1: string; d2: string };
    dayShiftCapacities: Record<DayKey, Record<number, number>>;
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

const dayShortName = (key: DayKey) => (key === 'd' ? 'D' : (key === 'd1' ? 'D+1' : 'D+2'));
const dayFullLabel = (key: DayKey) => `${dayShortName(key)} (${dayLabels.value[key]})`;

const fmt = (n: number | null | undefined) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 2 });

function fmtMinutes(totalMinutes: number): string {
    const rounded = Math.max(0, Math.round(totalMinutes));
    const hours = Math.floor(rounded / 60);
    const mins = rounded % 60;
    if (hours > 0 && mins > 0) return `${hours} ${t('production.hours')} ${mins} ${t('production.minutes')}`;
    if (hours > 0) return `${hours} ${t('production.hours')}`;
    return `${mins} ${t('production.minutes')}`;
}

function fgPrimaryName(part: Pick<Part, 'part_number' | 'part_name'> | null | undefined): string {
    const name = part?.part_name?.trim();
    return name && name !== '' ? name : (part?.part_number ?? '—');
}

function fgSecondaryNumber(part: Pick<Part, 'part_number' | 'part_name'> | null | undefined): string {
    const name = part?.part_name?.trim();
    if (!name || name === '') return '';
    return part?.part_number ?? '';
}

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
    shift_counts: { d: number; d1: number; d2: number };
    rows: ModalRowDraft[];
}>({
    date: props.date,
    shift_counts: { d: 3, d1: 3, d2: 3 },
    rows: [],
});

const showInlineCreateWo = ref(false);
const showAllFgInInlineCreate = ref(false);
const inlineWoForm = useForm({
    plan_date: props.date,
    fg_part_id: '',
    qty: '',
});

const overloadPrompt = ref<OverloadPrompt | null>(null);
const unusedShiftItems = ref<UnusedShiftItem[]>([]);

const inlineFgOptions = computed<PartOpt[]>(() => {
    const machine = activeMachine.value;
    if (!machine || showAllFgInInlineCreate.value || machine.fg_part_ids.length === 0) {
        return props.fgParts;
    }
    const allowed = new Set(machine.fg_part_ids);
    const filtered = props.fgParts.filter((p) => allowed.has(p.id));
    return filtered.length > 0 ? filtered : props.fgParts;
});

function toModalDraft(row: ProductionPlanMachineRow, machineId: number): ModalRowDraft {
    return {
        work_order_id: row.work_order_id,
        origin_machine_id: row.origin_machine_id ?? machineId,
        origin_machine_name: row.origin_machine_name ?? null,
        is_reassigned: Boolean(row.is_reassigned),
        wo_no: row.wo_no,
        wo_status: row.wo_status,
        remaining_qty: row.remaining_qty,
        has_upstream: Boolean(row.has_upstream),
        day_caps: row.day_caps
            ? { d: row.day_caps.d, d1: row.day_caps.d1, d2: row.day_caps.d2 }
            : { d: row.remaining_qty, d1: row.remaining_qty, d2: row.remaining_qty },
        fg_part: row.fg_part,
        wip_part: row.wip_part,
        cycle_time_seconds: row.cycle_time_seconds,
        qdc_minutes: row.qdc_minutes,
        d_seq: row.days.d ? String(row.days.d.sequence) : '',
        d_qty: row.days.d ? String(row.days.d.qty) : '',
        d_qdc: row.days.d ? Boolean(row.days.d.apply_qdc) : false,
        d_qdc_touched: row.days.d !== null,
        d_machine_id: row.days.d?.machine_id ?? machineId,
        d1_seq: row.days.d1 ? String(row.days.d1.sequence) : '',
        d1_qty: row.days.d1 ? String(row.days.d1.qty) : '',
        d1_qdc: row.days.d1 ? Boolean(row.days.d1.apply_qdc) : false,
        d1_qdc_touched: row.days.d1 !== null,
        d1_machine_id: row.days.d1?.machine_id ?? machineId,
        d2_seq: row.days.d2 ? String(row.days.d2.sequence) : '',
        d2_qty: row.days.d2 ? String(row.days.d2.qty) : '',
        d2_qdc: row.days.d2 ? Boolean(row.days.d2.apply_qdc) : false,
        d2_qdc_touched: row.days.d2 !== null,
        d2_machine_id: row.days.d2?.machine_id ?? machineId,
    };
}

function openMachineModal(machineId: number) {
    const machine = props.machines.find((m) => m.id === machineId);
    if (!machine) return;

    activeMachineId.value = machine.id;
    machinePlanForm.clearErrors();
    machinePlanForm.date = props.date;
    machinePlanForm.shift_counts = {
        d: machine.shift_counts?.d ?? 3,
        d1: machine.shift_counts?.d1 ?? 3,
        d2: machine.shift_counts?.d2 ?? 3,
    };
    machinePlanForm.rows = machine.modal_rows.map((row) => toModalDraft(row, machine.id));
    showInlineCreateWo.value = false;
    showAllFgInInlineCreate.value = false;
    overloadPrompt.value = null;
    unusedShiftItems.value = [];
    inlineWoForm.reset();
    inlineWoForm.clearErrors();
}

function closeMachineModal() {
    activeMachineId.value = null;
    showInlineCreateWo.value = false;
    overloadPrompt.value = null;
    unusedShiftItems.value = [];
}

watch(
    () => activeMachine.value?.modal_rows,
    (nextRows) => {
        const machineId = activeMachine.value?.id;
        if (!showMachineModal.value || !nextRows || machineId == null) return;
        const existingByKey = new Map(
            machinePlanForm.rows.map((r) => [`${r.work_order_id}-${r.origin_machine_id}`, r]),
        );
        machinePlanForm.rows = nextRows.map((row) => {
            const rowOriginId = row.origin_machine_id ?? machineId;
            const kept = existingByKey.get(`${row.work_order_id}-${rowOriginId}`);
            if (!kept) return toModalDraft(row, machineId);
            return {
                ...kept,
                origin_machine_id: rowOriginId,
                origin_machine_name: row.origin_machine_name ?? null,
                is_reassigned: Boolean(row.is_reassigned),
                remaining_qty: row.remaining_qty,
                wo_status: row.wo_status,
                has_upstream: Boolean(row.has_upstream),
                day_caps: row.day_caps
                    ? { d: row.day_caps.d, d1: row.day_caps.d1, d2: row.day_caps.d2 }
                    : { d: row.remaining_qty, d1: row.remaining_qty, d2: row.remaining_qty },
            };
        });
    },
);

function numOrZero(val: string): number {
    if (val === '' || val == null) return 0;
    const n = Number(val);
    return Number.isFinite(n) && n > 0 ? n : 0;
}

function hasMasterQdc(row: ModalRowDraft): boolean {
    return row.qdc_minutes != null && Number(row.qdc_minutes) > 0;
}

/** Auto-default QDC checkbox saat Seq diubah (Seq 1 = roll over / unchecked, Seq >= 2 = checked) selama user belum klik manual. */
function handleSeqInput(row: ModalRowDraft, key: DayKey) {
    if (row[`${key}_qdc_touched`] || !hasMasterQdc(row)) return;
    const seq = Number(row[`${key}_seq`]) || 1;
    row[`${key}_qdc`] = seq >= 2;
}

function handleQdcToggle(row: ModalRowDraft, key: DayKey) {
    row[`${key}_qdc_touched`] = true;
}

function plannedSumForDraft(row: ModalRowDraft): number {
    return Number((numOrZero(row.d_qty) + numOrZero(row.d1_qty) + numOrZero(row.d2_qty)).toFixed(4));
}

function liveAvailForDraft(row: ModalRowDraft): number {
    const ceiling = row.has_upstream ? row.day_caps.d2 : row.remaining_qty;
    return Math.round((ceiling - plannedSumForDraft(row)) * 10000) / 10000;
}

function isDayLockedForDraft(row: ModalRowDraft, key: DayKey): boolean {
    return row.has_upstream && row.day_caps[key] <= 1e-6 && numOrZero(row[`${key}_qty`]) <= 1e-6;
}

function dayMaxForDraft(row: ModalRowDraft, key: DayKey): number {
    if (key === 'd') return Math.round(Math.max(0, row.day_caps.d) * 10000) / 10000;
    if (key === 'd1') return Math.round(Math.max(0, row.day_caps.d1 - numOrZero(row.d_qty)) * 10000) / 10000;
    return Math.round(Math.max(0, row.day_caps.d2 - numOrZero(row.d_qty) - numOrZero(row.d1_qty)) * 10000) / 10000;
}

function isDayOverCap(row: ModalRowDraft, key: DayKey): boolean {
    if (!row.has_upstream) return false;
    const dQty = numOrZero(row.d_qty);
    if (key === 'd') return dQty > row.day_caps.d + 1e-6;
    const d1Qty = numOrZero(row.d1_qty);
    if (key === 'd1') return dQty + d1Qty > row.day_caps.d1 + 1e-6;
    return dQty + d1Qty + numOrZero(row.d2_qty) > row.day_caps.d2 + 1e-6;
}

const hasAnyDayCapViolation = computed(() =>
    machinePlanForm.rows.some((row) => dayKeys.some((key) => isDayOverCap(row, key))),
);

const hasAnyTotalAvailViolation = computed(() =>
    machinePlanForm.rows.some((row) => !row.has_upstream && liveAvailForDraft(row) < -1e-6),
);

const hasAnyOverLimit = computed(() =>
    machinePlanForm.rows.some((row) => liveAvailForDraft(row) < -1e-6) || hasAnyDayCapViolation.value,
);

function capacityMinutesFor(key: DayKey): number {
    const shifts = machinePlanForm.shift_counts[key] ?? 3;
    return props.dayShiftCapacities?.[key]?.[shifts] ?? shifts * 420;
}

function dayUsageSummary(key: DayKey) {
    const capMinutes = capacityMinutesFor(key);
    const currentMachineId = activeMachine.value?.id;
    let usedSeconds = 0;
    let plannedCount = 0;
    let timedCount = 0;

    for (const row of machinePlanForm.rows) {
        if (currentMachineId != null && row[`${key}_machine_id`] !== currentMachineId) continue;
        const qty = numOrZero(row[`${key}_qty`]);
        if (qty <= 0) continue;
        plannedCount++;
        if (row.cycle_time_seconds != null && row.cycle_time_seconds > 0) {
            timedCount++;
            const qdcSecs = row[`${key}_qdc`] && hasMasterQdc(row) ? Number(row.qdc_minutes) * 60 : 0;
            usedSeconds += qdcSecs + (qty * row.cycle_time_seconds);
        }
    }

    const usedMinutes = usedSeconds / 60;
    const diffMinutes = capMinutes - usedMinutes;

    return {
        capMinutes,
        usedMinutes,
        remainMinutes: Math.max(0, diffMinutes),
        overMinutes: Math.max(0, -diffMinutes),
        isOverload: diffMinutes < -0.01,
        plannedCount,
        timedCount,
    };
}

function findFirstOverload(): OverloadPrompt | null {
    const nextMap: Record<DayKey, DayKey | null> = { d: 'd1', d1: 'd2', d2: null };
    const currentMachineId = activeMachine.value?.id;

    for (const key of dayKeys) {
        const capSeconds = capacityMinutesFor(key) * 60;
        const shifts = machinePlanForm.shift_counts[key] ?? 3;

        const ordered = machinePlanForm.rows
            .map((row, idx) => ({
                row,
                idx,
                seq: Number(row[`${key}_seq`]) > 0 ? Number(row[`${key}_seq`]) : 1,
                qty: numOrZero(row[`${key}_qty`]),
            }))
            .filter((e) =>
                (currentMachineId == null || e.row[`${key}_machine_id`] === currentMachineId)
                && e.qty > 0
                && e.row.cycle_time_seconds != null
                && e.row.cycle_time_seconds > 0,
            )
            .sort((a, b) => (a.seq - b.seq) || (a.idx - b.idx));

        let remainingSeconds = capSeconds;
        for (const entry of ordered) {
            const tt = Number(entry.row.cycle_time_seconds);
            const qdcSeconds = entry.row[`${key}_qdc`] && hasMasterQdc(entry.row) ? Number(entry.row.qdc_minutes) * 60 : 0;
            const prodSeconds = entry.qty * tt;

            if (qdcSeconds + prodSeconds > remainingSeconds + 1e-6) {
                const availForProd = Math.max(0, remainingSeconds - qdcSeconds);
                const maxQty = Math.max(0, Math.floor(availForProd / tt));
                const overflowQty = Number(Math.max(0, entry.qty - maxQty).toFixed(4));

                return {
                    dayKey: key,
                    nextDayKey: nextMap[key],
                    shifts,
                    rowIndex: entry.idx,
                    woNo: entry.row.wo_no,
                    partLabel: fgPrimaryName(entry.row.fg_part),
                    maxQty,
                    overflowQty,
                };
            }

            remainingSeconds -= (qdcSeconds + prodSeconds);
        }
    }

    return null;
}

function collectUnusedShiftDays(): UnusedShiftItem[] {
    const list: UnusedShiftItem[] = [];
    for (const key of dayKeys) {
        const usage = dayUsageSummary(key);
        if (usage.plannedCount > 0 && usage.timedCount > 0 && !usage.isOverload && usage.remainMinutes >= 1) {
            list.push({
                dayKey: key,
                dayLabel: dayFullLabel(key),
                shifts: machinePlanForm.shift_counts[key] ?? 3,
                remainMinutes: usage.remainMinutes,
            });
        }
    }
    return list;
}

function applyRollOver() {
    const prompt = overloadPrompt.value;
    if (!prompt || !prompt.nextDayKey) return;

    const targetRow = machinePlanForm.rows[prompt.rowIndex];
    if (!targetRow) return;

    const curr = prompt.dayKey;
    const next = prompt.nextDayKey;
    const currentMachineId = activeMachine.value?.id;

    targetRow[`${curr}_qty`] = prompt.maxQty > 0 ? String(prompt.maxQty) : '';
    if (prompt.maxQty <= 0) {
        targetRow[`${curr}_seq`] = '';
        targetRow[`${curr}_qdc`] = false;
    }

    for (let i = 0; i < machinePlanForm.rows.length; i++) {
        if (i === prompt.rowIndex) continue;
        const other = machinePlanForm.rows[i];
        if (currentMachineId != null && other[`${next}_machine_id`] !== currentMachineId) continue;
        if (numOrZero(other[`${next}_qty`]) > 0) {
            const prevSeq = Number(other[`${next}_seq`]) > 0 ? Number(other[`${next}_seq`]) : 1;
            other[`${next}_seq`] = String(prevSeq + 1);
            if (!other[`${next}_qdc_touched`] && hasMasterQdc(other)) {
                other[`${next}_qdc`] = true;
            }
        }
    }

    const existingNextQty = numOrZero(targetRow[`${next}_qty`]);
    if (currentMachineId != null) {
        targetRow[`${next}_machine_id`] = currentMachineId;
    }
    targetRow[`${next}_qty`] = String(Number((existingNextQty + prompt.overflowQty).toFixed(4)));
    targetRow[`${next}_seq`] = '1';
    targetRow[`${next}_qdc`] = false;
    targetRow[`${next}_qdc_touched`] = true;

    overloadPrompt.value = null;
}

function rowServerError(idx: number): string | undefined {
    const errors = machinePlanForm.errors as Record<string, string | undefined>;
    return errors[`rows.${idx}.qty`]
        ?? errors[`rows.${idx}.work_order_id`]
        ?? errors[`rows.${idx}.origin_machine_id`]
        ?? errors[`rows.${idx}.d_machine_id`]
        ?? errors[`rows.${idx}.d1_machine_id`]
        ?? errors[`rows.${idx}.d2_machine_id`];
}

function executeSaveMachinePlan() {
    if (!activeMachine.value || hasAnyOverLimit.value) return;

    const toNullableNum = (v: string) => (v === '' ? null : Number(v));

    machinePlanForm
        .transform((data) => ({
            date: props.date,
            shift_counts: data.shift_counts,
            rows: data.rows.map((r) => ({
                work_order_id: r.work_order_id,
                origin_machine_id: r.origin_machine_id,
                d_seq: toNullableNum(r.d_seq),
                d_qty: toNullableNum(r.d_qty),
                d_qdc: r.d_qdc,
                d_machine_id: r.d_machine_id,
                d1_seq: toNullableNum(r.d1_seq),
                d1_qty: toNullableNum(r.d1_qty),
                d1_qdc: r.d1_qdc,
                d1_machine_id: r.d1_machine_id,
                d2_seq: toNullableNum(r.d2_seq),
                d2_qty: toNullableNum(r.d2_qty),
                d2_qdc: r.d2_qdc,
                d2_machine_id: r.d2_machine_id,
            })),
        }))
        .put(route('production-plans.machines.save', activeMachine.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                closeMachineModal();
            },
        });
}

function handleSaveClick() {
    if (!activeMachine.value || hasAnyOverLimit.value) return;

    const overload = findFirstOverload();
    if (overload) {
        overloadPrompt.value = overload;
        return;
    }

    const unused = collectUnusedShiftDays();
    if (unused.length > 0) {
        unusedShiftItems.value = unused;
        return;
    }

    executeSaveMachinePlan();
}

function confirmSaveDespiteOverload() {
    overloadPrompt.value = null;
    const unused = collectUnusedShiftDays();
    if (unused.length > 0) {
        unusedShiftItems.value = unused;
        return;
    }
    executeSaveMachinePlan();
}

function confirmSaveWithUnusedShift() {
    unusedShiftItems.value = [];
    executeSaveMachinePlan();
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
            <div class="min-w-0">
                <h1 class="min-w-0 break-words text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.plan') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ t('production.planDateDescription', { date: fmtDate(date), d: dayLabels.d, d1: dayLabels.d1, d2: dayLabels.d2 }) }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-borderline bg-surface px-4 py-2 text-sm font-semibold text-ink-primary transition hover:bg-background active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    @click="showHistory = true"
                >
                    {{ t('production.planHistory') }}
                </button>
                <button
                    v-if="canCreateWo"
                    type="button"
                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    @click="openCreateWo()"
                >
                    <svg aria-hidden="true" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    {{ t('production.newWoClean') }}
                </button>
            </div>
        </div>

        <!-- Toolbar: filter + ringkasan papan -->
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-borderline bg-surface p-3">
            <div class="min-w-56 flex-1">
                <label class="mb-1 block text-xs font-medium text-ink-secondary">{{ t('production.search') }}</label>
                <input
                    v-model="search"
                    type="search"
                    :aria-label="t('production.search')"
                    :placeholder="t('production.searchPlan')"
                    class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary placeholder-ink-secondary focus:border-primary focus:ring-primary"
                />
            </div>
            <div class="min-w-48">
                <label class="mb-1 block text-xs font-medium text-ink-secondary">{{ t('production.machineFilter') }}</label>
                <input
                    v-model="machineFilter"
                    type="search"
                    :aria-label="t('production.machineFilter')"
                    :placeholder="t('production.machineFilterPlaceholder')"
                    class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary placeholder-ink-secondary focus:border-primary focus:ring-primary"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-ink-secondary">{{ t('production.startDate') }}</label>
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
                    <span class="font-semibold tabular-nums text-ink-primary">{{ summary.plannedMachines }}</span> {{ t('production.plannedMachinesLabel') }}
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-borderline bg-background px-2.5 py-1.5 text-xs text-ink-secondary">
                    <span class="font-semibold tabular-nums text-ink-primary">{{ summary.plannedWo }}</span> {{ t('production.woLabel') }}
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-warning/40 bg-warning/10 px-2.5 py-1.5 text-xs text-warning">
                    <span class="font-semibold tabular-nums">{{ summary.availWo }}</span> {{ t('production.availWoShort') }}
                </span>
            </div>
        </div>

        <!-- Panel Avail WO di atas papan -->
        <div v-if="availWorkOrders.length" class="mb-4 rounded-xl bg-warning/5 p-4">
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h2 class="min-w-0 break-words text-sm font-semibold text-warning">
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
                            <div class="truncate text-sm font-semibold text-ink-primary">
                                {{ fgPrimaryName(wo.part) }}
                            </div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-ink-secondary">
                                <span v-if="fgSecondaryNumber(wo.part)" class="font-medium text-ink-secondary">{{ fgSecondaryNumber(wo.part) }}</span>
                                <span v-if="wo.part?.model" class="rounded bg-background px-1.5 py-0.5 text-ink-secondary">{{ wo.part.model }}</span>
                                <Link
                                    :href="route('work-orders.show', wo.id)"
                                    class="font-semibold text-primary hover:underline"
                                >
                                    {{ wo.wo_no }}
                                </Link>
                            </div>
                        </div>
                        <StatusBadge :tone="statusTone(wo.status)">{{ t(`production.statuses.${wo.status}`) }}</StatusBadge>
                    </div>
                    <div class="mt-2.5 flex flex-wrap gap-1.5">
                        <button
                            v-for="m in wo.machines"
                            :key="m.machine_id"
                            type="button"
                            class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border px-2 py-0.5 text-[11px] font-medium transition active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
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
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <h2 class="min-w-0 break-words text-sm font-semibold text-ink-primary">{{ t('production.boardTitle') }}</h2>
                    <span class="text-xs text-ink-secondary">{{ t('production.boardReadOnlyHint') }}</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1380px] table-fixed border-separate border-spacing-0 text-sm">
                    <colgroup>
                        <col class="w-48" />
                        <col class="w-52" />
                        <col class="w-28" />
                        <col class="w-36" />
                        <col class="w-36" />
                        <col class="w-28" />
                        <col class="w-44" />
                        <col class="w-44" />
                        <col class="w-44" />
                        <col class="w-28" />
                    </colgroup>
                    <thead class="sticky top-0 z-10 bg-background">
                        <tr class="border-b border-borderline text-left text-xs font-semibold text-ink-secondary">
                            <th scope="col" class="sticky left-0 z-30 border-r border-borderline bg-background px-3 py-2.5">{{ t('production.machine') }}</th>
                            <th scope="col" class="px-3 py-2.5">{{ t('production.fgPart') }}</th>
                            <th scope="col" class="px-3 py-2.5">{{ t('master.model') }}</th>
                            <th scope="col" class="px-3 py-2.5">{{ t('production.inputPart') }}</th>
                            <th scope="col" class="px-3 py-2.5">{{ t('production.outputPart') }}</th>
                            <th scope="col" class="px-3 py-2.5 text-right">{{ t('production.availWoShort') }}</th>
                            <th scope="col" v-for="key in dayKeys" :key="key" class="border-l border-borderline px-3 py-2.5 text-center">
                                <div>{{ dayLabels[key] }}</div>
                                <div class="text-[11px] font-normal text-ink-secondary">
                                    {{ dayShortName(key) }}
                                </div>
                            </th>
                            <th scope="col" class="border-l border-borderline bg-background px-3 py-2.5 text-right">{{ t('production.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(machine, mi) in filteredMachines" :key="machine.id">
                            <!-- Mesin kosong -->
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
                                <td v-for="key in dayKeys" :key="key" class="border-l border-borderline px-3 py-3 text-center text-xs text-ink-secondary">-</td>
                                <td class="border-l border-borderline bg-surface px-3 py-3 text-right group-hover:bg-primary-light/20">
                                    <button
                                        v-if="canManage"
                                        type="button"
                                        class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border px-2.5 py-1.5 text-xs font-semibold transition active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                        :class="machine.avail_wo_count > 0
                                            ? 'border-primary/50 bg-primary-light/60 text-primary hover:bg-primary hover:text-white'
                                            : 'border-borderline bg-surface text-ink-secondary hover:border-primary hover:text-primary'"
                                        @click="openMachineModal(machine.id)"
                                    >
                                        <svg aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        {{ t('production.addPlan') }}
                                    </button>
                                </td>
                            </tr>

                            <!-- Mesin dengan rencana D / D+1 / D+2 -->
                            <tr
                                v-for="(row, rIdx) in machine.planned_rows"
                                :key="`${machine.id}-${row.work_order_id}-${row.origin_machine_id ?? machine.id}`"
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
                                        <span v-if="processNames(machine.planned_rows)" class="text-[11px] text-ink-secondary">{{ processNames(machine.planned_rows) }}</span>
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
                                            class="mt-1 inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-primary/40 bg-primary-light/40 px-2 py-1 text-[11px] font-semibold text-primary transition hover:bg-primary hover:text-white active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                            @click="openMachineModal(machine.id)"
                                        >
                                            {{ t('production.addEditPlan') }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 align-top">
                                    <div class="font-semibold leading-snug text-ink-primary">{{ fgPrimaryName(row.fg_part) }}</div>
                                    <div v-if="fgSecondaryNumber(row.fg_part)" class="mt-0.5 text-xs text-ink-secondary">{{ fgSecondaryNumber(row.fg_part) }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
                                        <Link :href="route('work-orders.show', row.work_order_id)" class="font-medium text-primary hover:underline">{{ row.wo_no }}</Link>
                                        <StatusBadge :tone="statusTone(row.wo_status)">{{ t(`production.statuses.${row.wo_status}`) }}</StatusBadge>
                                        <span
                                            v-if="row.is_reassigned && row.origin_machine_name"
                                            class="inline-flex rounded bg-info/10 px-1.5 py-0.5 text-[11px] font-medium text-info"
                                        >
                                            {{ t('production.reassignedFrom', { machine: row.origin_machine_name }) }}
                                        </span>
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
                                            <span>{{ row.days[key]!.start_time }} - {{ row.days[key]!.end_time }}</span>
                                            <span
                                                v-if="row.days[key]!.is_overload"
                                                class="rounded bg-danger px-1.5 py-0.5 text-[11px] font-semibold text-white"
                                            >
                                                {{ t('production.overloadTag') }}
                                            </span>
                                        </div>
                                        <div v-else class="mt-1.5 text-[11px] text-ink-secondary">-</div>
                                        <div class="mt-1 flex flex-wrap items-center gap-1">
                                            <span
                                                v-if="row.days[key]!.qdc_applied_minutes > 0"
                                                class="inline-flex rounded bg-info/10 px-1.5 py-0.5 text-[11px] font-medium tabular-nums text-info"
                                            >
                                                {{ t('production.qdcShortTag', { minutes: fmt(row.days[key]!.qdc_applied_minutes) }) }}
                                            </span>
                                            <span class="inline-flex rounded bg-background px-1.5 py-0.5 text-[11px] font-medium text-ink-secondary">
                                                {{ t('production.shiftCountOption', { n: machine.shift_counts?.[key] ?? 3 }) }}
                                            </span>
                                        </div>
                                    </template>
                                    <div v-else class="text-center text-xs text-ink-secondary">-</div>
                                </td>
                                <td class="border-l border-borderline bg-surface px-3 py-2.5 align-top group-hover:bg-primary-light/30">
                                    <div class="flex justify-end gap-1">
                                        <Link
                                            :href="route('work-orders.show', row.work_order_id)"
                                            :title="t('production.viewWo')"
                                            :aria-label="t('production.viewWo')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-info transition hover:bg-info/10 active:scale-[0.96] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                        >
                                            <svg aria-hidden="true" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                        </Link>
                                        <button
                                            v-if="canManage"
                                            type="button"
                                            :title="t('production.addEditPlan')"
                                            :aria-label="t('production.addEditPlan')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-primary transition hover:bg-primary-light active:scale-[0.96] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                            @click="openMachineModal(machine.id)"
                                        >
                                            <svg aria-hidden="true" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
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
        <Modal :show="showMachineModal" max-width="6xl" @close="closeMachineModal">
            <div v-if="activeMachine" class="relative p-6">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3 border-b border-borderline pb-4">
                    <div class="min-w-0">
                        <h3 class="min-w-0 break-words text-lg font-bold text-ink-primary">
                            {{ t('production.machinePlanModalTitle', { machine: activeMachine.machine_name }) }}
                        </h3>
                        <p class="mt-1 text-xs text-ink-secondary">{{ t('production.machinePlanModalHint') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="canCreateWo"
                            type="button"
                            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-primary bg-primary-light/40 px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary hover:text-white active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                            @click="openInlineCreateWo()"
                        >
                            <svg aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            {{ t('production.newWoClean') }}
                        </button>
                        <button
                            type="button"
                            class="whitespace-nowrap rounded-md border border-borderline px-3 py-1.5 text-xs font-medium text-ink-secondary transition hover:bg-background active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
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
                            class="whitespace-nowrap text-xs font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded"
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
                                class="w-full whitespace-nowrap rounded-md bg-primary px-3 py-2 text-xs font-semibold text-white transition hover:bg-primary-hover active:scale-[0.98] motion-reduce:active:scale-100 disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                            >
                                {{ inlineWoForm.processing ? t('production.saving') : t('production.saveWo') }}
                            </button>
                            <button
                                type="button"
                                :aria-label="t('production.cancel')"
                                class="rounded-md border border-borderline bg-surface px-2.5 py-2 text-xs text-ink-secondary transition hover:bg-background active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                @click="showInlineCreateWo = false"
                            >
                                ×
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Tabel input Add/Edit Plan (Seq | Qty | QDC + Jumlah Shift di header tiap hari) -->
                <form @submit.prevent="handleSaveClick">
                    <div class="max-h-[58vh] overflow-auto rounded-xl border border-borderline">
                        <table class="min-w-full divide-y divide-borderline text-xs">
                            <thead class="sticky top-0 z-10 bg-background">
                                <tr class="text-left font-semibold text-ink-secondary">
                                    <th scope="col" class="px-3 py-2.5 align-bottom">{{ t('production.fgPart') }} + {{ t('master.model') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-right align-bottom">{{ t('production.availWoQty') }}</th>
                                    <th
                                        v-for="key in dayKeys"
                                        :key="key"
                                        scope="col"
                                        class="border-l border-borderline px-2.5 py-2 align-top"
                                    >
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="inline-flex items-center gap-1.5 font-semibold text-ink-primary">
                                                <span>{{ dayShortName(key) }}</span>
                                                <span class="font-normal text-ink-secondary">{{ dayLabels[key] }}</span>
                                            </span>
                                            <select
                                                v-model.number="machinePlanForm.shift_counts[key]"
                                                :aria-label="`${t('production.shift')} ${dayFullLabel(key)}`"
                                                class="rounded-md border-borderline bg-surface px-2 py-0.5 text-[11px] font-semibold text-ink-primary focus:border-primary focus:ring-primary"
                                            >
                                                <option :value="1">{{ t('production.shiftCountOption', { n: 1 }) }}</option>
                                                <option :value="2">{{ t('production.shiftCountOption', { n: 2 }) }}</option>
                                                <option :value="3">{{ t('production.shiftCountOption', { n: 3 }) }}</option>
                                            </select>
                                        </div>
                                        <div class="mt-1 flex items-center justify-between gap-1 text-[11px] font-medium">
                                            <span class="tabular-nums text-ink-secondary">
                                                {{ t('production.shiftUsedOf', { used: fmtMinutes(dayUsageSummary(key).usedMinutes), cap: fmtMinutes(dayUsageSummary(key).capMinutes) }) }}
                                            </span>
                                            <span
                                                v-if="dayUsageSummary(key).isOverload"
                                                class="tabular-nums font-semibold text-danger"
                                            >
                                                {{ t('production.shiftOverLabel', { time: fmtMinutes(dayUsageSummary(key).overMinutes) }) }}
                                            </span>
                                            <span
                                                v-else-if="dayUsageSummary(key).plannedCount > 0 && dayUsageSummary(key).remainMinutes >= 1"
                                                class="tabular-nums text-warning"
                                            >
                                                {{ t('production.shiftRemainLabel', { time: fmtMinutes(dayUsageSummary(key).remainMinutes) }) }}
                                            </span>
                                        </div>
                                        <div class="mt-1.5 grid grid-cols-[48px_76px_64px] items-center gap-1.5 border-t border-borderline/70 pt-1 text-[11px] font-semibold text-ink-secondary">
                                            <span class="text-center">{{ t('production.sequenceShort') }}</span>
                                            <span class="text-right">{{ t('production.qtyShort') }}</span>
                                            <span class="text-center">{{ t('production.qdcCol') }}</span>
                                        </div>
                                    </th>
                                    <th scope="col" class="border-l border-borderline px-3 py-2.5 text-right align-bottom">{{ t('production.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-borderline bg-surface">
                                <tr
                                    v-for="(row, idx) in machinePlanForm.rows"
                                    :key="`${row.work_order_id}-${row.origin_machine_id}`"
                                    :class="liveAvailForDraft(row) < -1e-6 || dayKeys.some((k) => isDayOverCap(row, k)) ? 'bg-danger/5' : ''"
                                >
                                    <td class="px-3 py-2.5 align-top">
                                        <div class="flex flex-wrap items-center gap-1.5 font-semibold text-ink-primary">
                                            <span>{{ fgPrimaryName(row.fg_part) }}</span>
                                            <span class="rounded bg-background px-1.5 py-0.5 text-[11px] font-normal text-ink-secondary">{{ row.fg_part?.model || '-' }}</span>
                                        </div>
                                        <div v-if="fgSecondaryNumber(row.fg_part)" class="text-[11px] text-ink-secondary">
                                            {{ fgSecondaryNumber(row.fg_part) }}
                                        </div>
                                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
                                            <span class="font-medium text-primary">{{ row.wo_no }}</span>
                                            <StatusBadge :tone="statusTone(row.wo_status)">{{ t(`production.statuses.${row.wo_status}`) }}</StatusBadge>
                                            <span v-if="row.wip_part?.part_number" class="text-ink-secondary">→ {{ row.wip_part.part_number }}</span>
                                            <span
                                                v-if="row.is_reassigned && row.origin_machine_name"
                                                class="inline-flex rounded bg-info/10 px-1.5 py-0.5 text-[11px] font-medium text-info"
                                            >
                                                {{ t('production.reassignedFrom', { machine: row.origin_machine_name }) }}
                                            </span>
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
                                    <td v-for="key in dayKeys" :key="key" class="border-l border-borderline px-2.5 py-2.5 align-top">
                                        <div class="grid grid-cols-[48px_76px_64px] items-center gap-1.5">
                                            <input
                                                v-model="row[`${key}_seq`]"
                                                type="number"
                                                step="1"
                                                min="1"
                                                inputmode="numeric"
                                                placeholder="1"
                                                :disabled="isDayLockedForDraft(row, key)"
                                                :aria-label="`${t('production.sequence')} ${dayLabels[key]} ${row.wo_no}`"
                                                class="w-full rounded-md border border-borderline bg-background px-1.5 py-1 text-center text-xs tabular-nums text-ink-primary focus:border-primary focus:ring-primary disabled:cursor-not-allowed disabled:opacity-50"
                                                @input="handleSeqInput(row, key)"
                                            />
                                            <input
                                                v-model="row[`${key}_qty`]"
                                                type="number"
                                                step="any"
                                                min="0"
                                                inputmode="decimal"
                                                placeholder="0"
                                                :disabled="isDayLockedForDraft(row, key)"
                                                :aria-label="`${t('production.qtyShort')} ${dayLabels[key]} ${row.wo_no}`"
                                                class="w-full rounded-md border px-2 py-1 text-right text-xs tabular-nums text-ink-primary focus:border-primary focus:ring-primary disabled:cursor-not-allowed disabled:opacity-50"
                                                :class="isDayOverCap(row, key)
                                                    ? 'border-danger bg-danger/10'
                                                    : (row[`${key}_qty`] !== '' ? 'border-primary/50 bg-primary-light/30' : 'border-borderline bg-background')"
                                            />
                                            <label
                                                v-if="hasMasterQdc(row)"
                                                class="inline-flex items-center justify-center gap-1 rounded-md border px-1.5 py-1 text-[11px] font-medium tabular-nums transition"
                                                :class="[
                                                    isDayLockedForDraft(row, key) ? 'cursor-not-allowed opacity-50' : 'cursor-pointer',
                                                    row[`${key}_qdc`]
                                                        ? 'border-info/50 bg-info/10 text-info'
                                                        : 'border-borderline bg-background text-ink-secondary',
                                                ]"
                                                :title="t('production.initialQdcHint')"
                                            >
                                                <input
                                                    v-model="row[`${key}_qdc`]"
                                                    type="checkbox"
                                                    :disabled="isDayLockedForDraft(row, key)"
                                                    class="h-3.5 w-3.5 rounded border-borderline text-primary focus:ring-primary disabled:cursor-not-allowed"
                                                    @change="handleQdcToggle(row, key)"
                                                />
                                                <span>{{ fmt(row.qdc_minutes) }}m</span>
                                            </label>
                                            <span v-else class="text-center text-[11px] text-ink-secondary">-</span>
                                        </div>
                                        <select
                                            v-model.number="row[`${key}_machine_id`]"
                                            :disabled="isDayLockedForDraft(row, key)"
                                            :aria-label="`${t('production.machine')} ${dayLabels[key]} ${row.wo_no}`"
                                            class="mt-1.5 w-full rounded-md border px-2 py-1 text-[11px] focus:border-primary focus:ring-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:cursor-not-allowed disabled:opacity-50"
                                            :class="row[`${key}_machine_id`] !== activeMachine.id
                                                ? 'border-info/60 bg-info/10 font-medium text-info'
                                                : 'border-borderline bg-surface text-ink-secondary'"
                                        >
                                            <option
                                                v-for="m in props.machines"
                                                :key="m.id"
                                                :value="m.id"
                                            >
                                                {{ m.machine_name }}
                                            </option>
                                        </select>
                                        <div
                                            v-if="row.has_upstream"
                                            class="mt-1 text-[11px] tabular-nums"
                                            :class="isDayOverCap(row, key) ? 'font-semibold text-danger' : 'text-ink-secondary'"
                                        >
                                            {{
                                                isDayLockedForDraft(row, key)
                                                    ? t('production.dayCapLocked')
                                                    : t('production.dayCapMax', { qty: fmt(dayMaxForDraft(row, key)) })
                                            }}
                                        </div>
                                    </td>
                                    <td class="border-l border-borderline px-3 py-2.5 text-right align-top">
                                        <button
                                            v-if="canCreateWo"
                                            type="button"
                                            class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-borderline bg-background px-2.5 py-1.5 text-[11px] font-semibold text-ink-primary transition hover:border-primary hover:text-primary active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
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
                        class="mt-3 space-y-1 rounded-lg border border-danger/40 bg-danger/10 px-3 py-2 text-xs font-medium text-danger"
                    >
                        <div v-if="hasAnyDayCapViolation">{{ t('production.qtyPlanExceedsUpstreamCap') }}</div>
                        <div v-if="hasAnyTotalAvailViolation || !hasAnyDayCapViolation">{{ t('production.qtyPlanExceedsAvail') }}</div>
                    </div>

                    <!-- Dialog Konfirmasi Overload + Roll Over (Centered Overlay inside Modal) -->
                    <div
                        v-if="overloadPrompt"
                        class="absolute inset-0 z-30 flex items-center justify-center rounded-lg bg-ink-primary/40 p-4"
                        role="alertdialog"
                        aria-modal="true"
                    >
                        <div class="w-full max-w-lg rounded-xl border-t-4 border-danger bg-surface p-5 shadow-xl">
                            <h4 class="min-w-0 break-words text-sm font-bold text-danger">{{ t('production.overloadRollOverTitle') }}</h4>
                            <p class="mt-1.5 text-xs leading-relaxed text-ink-primary">
                                {{
                                    overloadPrompt.nextDayKey
                                        ? t('production.overloadRollOverBody', {
                                            day: dayFullLabel(overloadPrompt.dayKey),
                                            shifts: overloadPrompt.shifts,
                                            part: overloadPrompt.partLabel,
                                            wo: overloadPrompt.woNo,
                                            maxQty: fmt(overloadPrompt.maxQty),
                                            overflow: fmt(overloadPrompt.overflowQty),
                                            nextDay: dayFullLabel(overloadPrompt.nextDayKey),
                                        })
                                        : t('production.overloadLastDayBody', {
                                            day: dayFullLabel(overloadPrompt.dayKey),
                                            shifts: overloadPrompt.shifts,
                                            part: overloadPrompt.partLabel,
                                            wo: overloadPrompt.woNo,
                                            maxQty: fmt(overloadPrompt.maxQty),
                                            overflow: fmt(overloadPrompt.overflowQty),
                                        })
                                }}
                            </p>
                            <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="whitespace-nowrap rounded-md border border-borderline bg-surface px-3 py-1.5 text-xs font-medium text-ink-primary transition hover:bg-background active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                    @click="overloadPrompt = null"
                                >
                                    {{ t('production.confirmNoBack') }}
                                </button>
                                <button
                                    v-if="overloadPrompt.nextDayKey"
                                    type="button"
                                    class="whitespace-nowrap rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                    @click="applyRollOver"
                                >
                                    {{ t('production.confirmYesRollOver') }}
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="whitespace-nowrap rounded-md bg-danger px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90 active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                    @click="confirmSaveDespiteOverload"
                                >
                                    {{ t('production.confirmKeepOverload') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Dialog Konfirmasi Sisa Waktu Shift Tanpa Planning (Centered Overlay inside Modal) -->
                    <div
                        v-if="unusedShiftItems.length > 0 && !overloadPrompt"
                        class="absolute inset-0 z-30 flex items-center justify-center rounded-lg bg-ink-primary/40 p-4"
                        role="alertdialog"
                        aria-modal="true"
                    >
                        <div class="w-full max-w-lg rounded-xl border-t-4 border-warning bg-surface p-5 shadow-xl">
                            <h4 class="min-w-0 break-words text-sm font-bold text-warning">{{ t('production.unusedShiftTitle') }}</h4>
                            <ul class="mt-2 list-disc space-y-1 pl-4 text-xs text-ink-primary">
                                <li v-for="item in unusedShiftItems" :key="item.dayKey">
                                    {{ t('production.unusedShiftItem', { day: item.dayLabel, shifts: item.shifts, remain: fmtMinutes(item.remainMinutes) }) }}
                                </li>
                            </ul>
                            <p class="mt-2.5 text-xs font-medium text-ink-primary">{{ t('production.unusedShiftBody') }}</p>
                            <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="whitespace-nowrap rounded-md border border-borderline bg-surface px-3 py-1.5 text-xs font-medium text-ink-primary transition hover:bg-background active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                    @click="unusedShiftItems = []"
                                >
                                    {{ t('production.confirmNoBack') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="machinePlanForm.processing"
                                    class="whitespace-nowrap rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover active:scale-[0.98] motion-reduce:active:scale-100 disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                    @click="confirmSaveWithUnusedShift"
                                >
                                    {{ t('production.confirmYesSave') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center justify-end gap-3 border-t border-borderline pt-4">
                        <button
                            type="button"
                            class="whitespace-nowrap rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                            @click="closeMachineModal"
                        >
                            {{ t('production.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="machinePlanForm.processing || hasAnyOverLimit"
                            class="whitespace-nowrap rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover active:scale-[0.98] motion-reduce:active:scale-100 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
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
                    <div class="min-w-0">
                        <h3 class="min-w-0 break-words text-lg font-bold text-ink-primary">{{ t('production.planHistory') }}</h3>
                        <p class="mt-1 text-sm text-ink-secondary">{{ t('production.historyForDate', { date: fmtDate(props.date) }) }}</p>
                    </div>
                    <button type="button" class="whitespace-nowrap rounded-md px-2 py-1 text-sm text-ink-secondary transition hover:text-ink-primary active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" @click="showHistory = false">{{ t('production.cancel') }}</button>
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
                <h3 class="min-w-0 break-words text-lg font-bold text-ink-primary">{{ t('production.addWo') }}</h3>

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
                    <button type="button" class="whitespace-nowrap rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface" @click="showCreateWo = false">{{ t('production.cancel') }}</button>
                    <button type="submit" :disabled="createWoForm.processing" class="whitespace-nowrap rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover active:scale-[0.98] motion-reduce:active:scale-100 disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                        {{ createWoForm.processing ? t('production.saving') : t('production.saveWo') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
