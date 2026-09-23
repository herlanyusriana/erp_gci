<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import Modal from '@/Components/Modal.vue';
import FinishedGoodPicker from '@/Components/FinishedGoodPicker.vue';
import InputError from '@/Components/InputError.vue';
import type { Machine, Part, ProductionPlan, ProductionPlanItem } from '@/types';

type MachineOpt = Pick<Machine, 'id' | 'machine_code' | 'machine_name'>;
type PartOpt = Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'>;
type PlanHistory = {
    id: number;
    production_plan_item_id: number | null;
    event: 'work_order_added' | 'targets_updated' | 'item_updated' | 'sequence_updated' | 'item_detached';
    before: Record<string, number | null> | null;
    after: Record<string, number | string | null> | null;
    created_at: string;
    user: { id: number; name: string } | null;
};

const { t, locale } = useI18n();

const props = defineProps<{
    date: string;
    plan: ProductionPlan | null;
    items: ProductionPlanItem[];
    machines: MachineOpt[];
    fgParts: PartOpt[];
    wipParts: Array<Pick<Part, 'id' | 'part_number' | 'part_name'>>;
    histories: PlanHistory[];
    unplannedWorkOrders: Array<{
        id: number;
        wo_no: string;
        part_id: number;
        qty: number;
        status: string;
        planned_date: string | null;
        part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
    }>;
    offBoardWorkOrders: Array<{
        id: number;
        wo_no: string;
        part_id: number;
        qty: number;
        status: string;
        planned_date: string | null;
        plan_dates: string[];
        part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
    }>;
}>();

const date = ref(props.date);
const search = ref('');

function applyDate() {
    router.get(route('production-plans.index'), { date: date.value || undefined }, { preserveState: true, replace: true });
}

/** Lompat ke papan tanggal lain (dari panel WO di luar papan). */
function jumpToDate(target: string) {
    date.value = target;
    applyDate();
}

function addDays(value: string, days: number): string {
    const [y, m, d] = value.split('-').map(Number);
    const dt = new Date(Date.UTC(y, m - 1, d));
    dt.setUTCDate(dt.getUTCDate() + days);
    return dt.toISOString().slice(0, 10);
}

function fmtDate(value: string): string {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString(locale.value, { timeZone: 'UTC', day: '2-digit', month: 'short' });
}

const dayKeys = ['d', 'd1', 'd2'] as const;

const dayCols = computed(() => ({ d: dayLabels.value.d, d1: dayLabels.value.d1, d2: dayLabels.value.d2 }));

/** Ringkasan papan: jumlah mesin, WO, dan total estimasi waktu. */
const summary = computed(() => {
    const rows = props.items;
    return {
        machines: new Set(rows.map((r) => r.machine_id).filter((v) => v != null)).size,
        wo: new Set(rows.map((r) => r.work_order_id).filter((v) => v != null)).size,
        estimate: rows.reduce((sum, r) => sum + (Number(r.estimated_seconds) || 0), 0),
    };
});

const dayLabels = computed(() => ({
    d: fmtDate(props.date),
    d1: fmtDate(addDays(props.date, 1)),
    d2: fmtDate(addDays(props.date, 2)),
}));

const fmt = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 2 });

function processNames(rows: ProductionPlanItem[]): string {
    return [...new Set(rows.map((row) => row.process?.process_name).filter(Boolean))].join(' · ');
}

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
            target_d: `D ${t('production.qtyShort')}`,
            target_d1: `D+1 ${t('production.qtyShort')}`,
            target_d2: `D+2 ${t('production.qtyShort')}`,
            sequence_d: `D ${t('production.sequenceShort')}`,
            sequence_d1: `D+1 ${t('production.sequenceShort')}`,
            sequence_d2: `D+2 ${t('production.sequenceShort')}`,
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

/** Detik → durasi ringkas (jam/menit/detik). */
const fmtDuration = (seconds: number | null | undefined) => {
    if (seconds == null) return '—';
    const total = Number(seconds);
    if (!Number.isFinite(total) || total <= 0) return '—';
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const secs = Math.round(total % 60);
    if (hours > 0) return minutes > 0 ? `${hours} ${t('production.hours')} ${minutes} ${t('production.minutes')}` : `${hours} ${t('production.hours')}`;
    if (minutes > 0) return secs > 0 ? `${minutes} ${t('production.minutes')} ${secs} ${t('production.seconds')}` : `${minutes} ${t('production.minutes')}`;
    return `${secs} ${t('production.seconds')}`;
};

const filteredItems = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.items;
    return props.items.filter((it) => [
        it.fg_part?.part_number,
        it.fg_part?.part_name,
        it.wip_part?.part_number,
        it.wip_part?.part_name,
        it.work_order?.wo_no,
    ].filter(Boolean).join(' ').toLowerCase().includes(q));
});

// ── Inline targets (mockup: isi manual di baris) ──────────
type DailyDraft = {
    d: string;
    d1: string;
    d2: string;
    sequence_d: string;
    sequence_d1: string;
    sequence_d2: string;
};

const targetDrafts = reactive<Record<number, DailyDraft>>({});
const sequenceKeyByDay = {
    d: 'sequence_d',
    d1: 'sequence_d1',
    d2: 'sequence_d2',
} as const;
const quantityLabelKeyByDay = {
    d: 'production.dQty',
    d1: 'production.d1Qty',
    d2: 'production.d2Qty',
} as const;

const asText = (v: number | null | undefined) => (v == null ? '' : String(v));

function draftFor(it: ProductionPlanItem) {
    if (!targetDrafts[it.id]) {
        targetDrafts[it.id] = {
            d: asText(it.target_d),
            d1: asText(it.target_d1),
            d2: asText(it.target_d2),
            sequence_d: asText(it.sequence_d),
            sequence_d1: asText(it.sequence_d1),
            sequence_d2: asText(it.sequence_d2),
        };
    }
    return targetDrafts[it.id];
}

// Setelah server merespons, draft disamakan dengan nilai tersimpan.
watch(() => props.items, (list) => {
    for (const it of list) {
        const draft = targetDrafts[it.id];
        if (!draft) continue;
        draft.d = asText(it.target_d);
        draft.d1 = asText(it.target_d1);
        draft.d2 = asText(it.target_d2);
        draft.sequence_d = asText(it.sequence_d);
        draft.sequence_d1 = asText(it.sequence_d1);
        draft.sequence_d2 = asText(it.sequence_d2);
    }
});

/** Baris yang targetnya berbeda dari nilai tersimpan. */
const dirtyRows = computed(() => props.items.filter((it) => {
    const draft = targetDrafts[it.id];
    if (!draft) return false;

    return draft.d !== asText(it.target_d)
        || draft.d1 !== asText(it.target_d1)
        || draft.d2 !== asText(it.target_d2)
        || draft.sequence_d !== asText(it.sequence_d)
        || draft.sequence_d1 !== asText(it.sequence_d1)
        || draft.sequence_d2 !== asText(it.sequence_d2);
}));

const savingTargets = ref(false);
const targetSaveStatus = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');

/**
 * Simpan semua target yang berubah dalam SATU request.
 * Dipicu tombol "Simpan" — tidak ada auto-save, jadi perubahan baru
 * tersimpan ke server setelah user menekan tombol.
 */
function saveAllTargets() {
    if (dirtyRows.value.length === 0) return;

    savingTargets.value = true;
    targetSaveStatus.value = 'saving';

    router.patch(route('production-plans.targets'), {
        items: dirtyRows.value.map((it) => {
            const draft = draftFor(it);

            return {
                id: it.id,
                target_d: draft.d === '' ? null : Number(draft.d),
                target_d1: draft.d1 === '' ? null : Number(draft.d1),
                target_d2: draft.d2 === '' ? null : Number(draft.d2),
                sequence_d: Number(draft.sequence_d),
                sequence_d1: Number(draft.sequence_d1),
                sequence_d2: Number(draft.sequence_d2),
            };
        }),
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { targetSaveStatus.value = 'saved'; },
        onError: () => { targetSaveStatus.value = 'error'; },
        onFinish: () => { savingTargets.value = false; },
    });
}

/**
 * Grup mesin mengikuti URUTAN MASTER MESIN (`props.machines` sudah diurut
 * `sequence` lalu nama) — semua mesin tampil walau tanpa WO; saat mencari,
 * hanya mesin yang punya baris cocok.
 */
const groups = computed(() => {
    const byMachine = new Map<number, ProductionPlanItem[]>();

    for (const item of filteredItems.value) {
        const key = item.machine_id ?? 0;
        if (!byMachine.has(key)) {
            byMachine.set(key, []);
        }
        byMachine.get(key)!.push(item);
    }

    const sortRows = (rows: ProductionPlanItem[]) =>
        rows.slice().sort((a, b) => (a.sequence - b.sequence) || (a.id - b.id));

    const searching = search.value.trim() !== '';

    const result: Array<{ machine: MachineOpt | null; rows: ProductionPlanItem[] }> = props.machines
        .map((machine) => ({
            machine,
            rows: sortRows(byMachine.get(machine.id) ?? []),
        }))
        .filter((group) => !searching || group.rows.length > 0);

    // Baris dengan mesin di luar master (mis. mesin dihapus) tetap ditampilkan.
    const orphanRows = filteredItems.value.filter(
        (item) => !props.machines.some((m) => m.id === item.machine_id),
    );
    if (orphanRows.length > 0) {
        result.push({ machine: null, rows: sortRows(orphanRows) });
    }

    return result;
});

function detach(item: ProductionPlanItem) {
    const label = item.work_order?.wo_no ?? t('production.thisRow');
    if (confirm(t('production.detachConfirm', { name: label }))) {
        router.delete(route('production-plans.items.detach', item.id), { preserveScroll: true });
    }
}

// ── Create ────────────────────────────────────────────────
const showCreate = ref(false);
const createForm = useForm({
    plan_date: props.date,
    fg_part_id: '',
    qty: '',
});

function openCreate() {
    createForm.reset();
    createForm.clearErrors();
    createForm.plan_date = props.date;
    showCreate.value = true;
}

function submitCreate() {
    createForm.post(route('production-plans.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
            createForm.reset();
        },
    });
}

// ── WO belum masuk plan ───────────────────────────────────
const showAttach = ref(false);
const attachForm = useForm({
    work_order_id: '',
    plan_date: props.date,
});

function openAttach(wo?: { id: number; qty: number }) {
    attachForm.reset();
    attachForm.clearErrors();
    attachForm.plan_date = props.date;
    if (wo) {
        attachForm.work_order_id = String(wo.id);
    }
    showAttach.value = true;
}

function submitAttach() {
    attachForm.post(route('production-plans.attach'), {
        preserveScroll: true,
        onSuccess: () => {
            showAttach.value = false;
            attachForm.reset();
        },
    });
}

// ── Edit ──────────────────────────────────────────────────
const showEdit = ref(false);
const editing = ref<ProductionPlanItem | null>(null);
const editForm = useForm({
    machine_id: '',
    wip_part_id: '',
    target_d: '',
    target_d1: '',
    target_d2: '',
});

function openEdit(item: ProductionPlanItem) {
    editing.value = item;
    editForm.clearErrors();
    editForm.machine_id = item.machine_id ? String(item.machine_id) : '';
    editForm.wip_part_id = item.wip_part_id ? String(item.wip_part_id) : '';
    editForm.target_d = item.target_d != null ? String(item.target_d) : '';
    editForm.target_d1 = item.target_d1 != null ? String(item.target_d1) : '';
    editForm.target_d2 = item.target_d2 != null ? String(item.target_d2) : '';
    showEdit.value = true;
}

function submitEdit() {
    if (!editing.value) return;
    editForm.patch(route('production-plans.items.update', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => { showEdit.value = false; },
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
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    @click="openCreate()"
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
                <input :aria-label="t('production.search')"
                    v-model="search"
                    type="search"
                    :placeholder="t('production.searchPlan')"
                    class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary placeholder-ink-secondary focus:border-primary focus:ring-primary"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.startDate') }}</label>
                <input :aria-label="t('production.startDate')"
                    v-model="date"
                    type="date"
                    class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                    @change="applyDate"
                />
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-borderline bg-background px-2.5 py-1.5 text-xs text-ink-secondary">
                    <span class="font-semibold text-ink-primary">{{ summary.machines }}</span> {{ t('production.machinesLabel') }}
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-borderline bg-background px-2.5 py-1.5 text-xs text-ink-secondary">
                    <span class="font-semibold text-ink-primary">{{ summary.wo }}</span> {{ t('production.woLabel') }}
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-borderline bg-background px-2.5 py-1.5 text-xs text-ink-secondary">
                    {{ t('production.totalEstimate') }}
                    <span class="font-semibold tabular-nums text-ink-primary">{{ fmtDuration(summary.estimate) }}</span>
                </span>
            </div>
        </div>

        <!-- WO aktif yang barisnya ada di tanggal lain -->
        <div v-if="offBoardWorkOrders.length" class="mb-4 rounded-xl border border-info/40 bg-info/5 p-4">
            <h2 class="text-sm font-semibold text-info">
                {{ t('production.offBoardTitle', { count: offBoardWorkOrders.length }) }}
            </h2>
            <p class="mb-3 mt-1 text-xs text-ink-secondary">{{ t('production.offBoardHint') }}</p>
            <div class="flex flex-wrap gap-2">
                <div
                    v-for="wo in offBoardWorkOrders"
                    :key="wo.id"
                    class="rounded-lg border border-borderline bg-surface px-3 py-2 text-xs"
                >
                    <span class="block font-semibold text-ink-primary">{{ wo.wo_no }}</span>
                    <span class="block text-ink-secondary">{{ wo.part?.part_number ?? '—' }} · {{ fmt(wo.qty) }} · {{ t(`production.statuses.${wo.status}`) }}</span>
                    <span class="mt-1 flex flex-wrap gap-1">
                        <button
                            v-for="planDate in wo.plan_dates"
                            :key="planDate"
                            type="button"
                            class="rounded-md border border-info/50 px-2 py-0.5 font-medium text-info transition hover:bg-info/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                            @click="jumpToDate(planDate)"
                        >
                            {{ fmtDate(planDate) }}
                        </button>
                    </span>
                </div>
            </div>
        </div>

        <!-- WO planned yang belum masuk plan mana pun -->
        <div v-if="unplannedWorkOrders.length" class="mb-4 rounded-xl border border-warning/40 bg-warning/5 p-4">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-warning">
                    {{ t('production.unplannedTitle', { count: unplannedWorkOrders.length }) }}
                </h2>
                <button
                    type="button"
                    class="rounded-md border border-warning px-3 py-1.5 text-xs font-semibold text-warning transition hover:bg-warning/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    @click="openAttach()"
                >
                    {{ t('production.attachWo') }}
                </button>
            </div>
            <p class="mb-3 text-xs text-ink-secondary">{{ t('production.unplannedHint') }}</p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="wo in unplannedWorkOrders"
                    :key="wo.id"
                    type="button"
                    class="rounded-lg border border-borderline bg-surface px-3 py-2 text-left text-xs transition hover:border-warning focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    @click="openAttach(wo)"
                >
                    <span class="block font-semibold text-ink-primary">{{ wo.wo_no }}</span>
                    <span class="block text-ink-secondary">{{ wo.part?.part_number ?? '—' }} · {{ fmt(wo.qty) }}</span>
                </button>
            </div>
        </div>


        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-borderline px-4 py-3">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-sm font-semibold text-ink-primary">{{ t('production.boardTitle') }}</h2>
                    <span v-if="items.length === 0" class="text-xs text-ink-secondary">{{ t('production.planEmptyHint') }}</span>
                    <span v-else class="text-xs text-ink-secondary">{{ t('production.planHelp') }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <span v-if="targetSaveStatus === 'saving'" class="text-xs font-medium text-ink-secondary">{{ t('production.saving') }}</span>
                    <span v-else-if="targetSaveStatus === 'saved'" class="text-xs font-medium text-success">{{ t('production.targetsSaved') }}</span>
                    <span v-else-if="targetSaveStatus === 'error'" class="text-xs font-medium text-danger">{{ t('production.targetsSaveFailed') }}</span>
                    <span v-else-if="dirtyRows.length" class="text-xs font-medium text-warning">{{ t('production.dirtyRows', { n: dirtyRows.length }) }}</span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                        :disabled="savingTargets || dirtyRows.length === 0"
                        :title="t('production.save')"
                        @click="saveAllTargets()"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        {{ t('production.save') }}
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1536px] table-fixed border-separate border-spacing-0 text-sm">
                    <colgroup>
                        <col class="w-36" />
                        <col class="w-44" />
                        <col class="w-24" />
                        <col class="w-40" />
                        <col class="w-40" />
                        <col class="w-24" />
                        <col class="w-24" />
                        <col class="w-36" />
                        <col class="w-36" />
                        <col class="w-36" />
                        <col class="w-44" />
                    </colgroup>
                    <thead class="sticky top-0 z-10 bg-background">
                        <tr class="border-b border-borderline text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="sticky left-0 z-30 border-r border-borderline bg-background px-3 py-2.5">{{ t('production.machine') }}</th>
                            <th scope="col" class="px-3 py-2.5">{{ t('production.fgPart') }}</th>
                            <th scope="col" class="px-3 py-2.5">{{ t('production.modelPart') }}</th>
                            <th scope="col" class="px-3 py-2.5">{{ t('production.inputPart') }}</th>
                            <th scope="col" class="px-3 py-2.5">{{ t('production.outputPart') }}</th>
                            <th scope="col" class="px-3 py-2.5 text-right">{{ t('production.estimatedTime') }}</th>
                            <th scope="col" class="px-3 py-2.5 text-right">{{ t('production.availableQty') }}</th>
                            <th scope="col" v-for="(label, key) in dayCols" :key="key" class="border-l border-borderline px-2 py-2.5 text-center">{{ label }}</th>
                            <th scope="col" class="border-l border-borderline bg-background px-3 py-2.5 text-right">{{ t('production.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(group, gi) in groups" :key="group.machine?.id ?? 'none'">
                            <tr v-if="group.rows.length === 0" class="group border-t border-borderline" :class="gi > 0 ? 'border-t-2 !border-t-borderline' : ''">
                                <td class="sticky left-0 z-10 border-r border-borderline bg-surface px-3 py-2.5 align-top group-hover:bg-primary-light">
                                    <div class="flex flex-col items-start gap-1">
                                        <span class="font-semibold leading-tight text-ink-primary">{{ group.machine?.machine_name ?? t('production.noMachine') }}</span>
                                        <span v-if="processNames(group.rows)" class="text-[11px] tracking-wide text-ink-secondary">{{ processNames(group.rows) }}</span>
                                    </div>
                                </td>
                                <td colspan="6" class="px-3 py-2.5 text-xs text-ink-secondary">{{ t('production.noMachineWo') }}</td>
                                <td colspan="3" class="border-l border-borderline px-2 py-2.5 text-center text-xs text-ink-secondary">—</td>
                                <td class="border-l border-borderline bg-surface px-3 py-2.5 text-right group-hover:bg-primary-light">
                                    <button
                                        type="button"
                                        :title="t('production.newWo')"
                                        :aria-label="t('production.newWo')"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-ink-secondary transition hover:bg-primary-light hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                        @click="openCreate()"
                                    >
                                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    </button>
                                </td>
                            </tr>
                            <tr
                                v-for="(row, index) in group.rows"
                                :key="row.id"
                                class="group border-t border-borderline transition hover:bg-primary-light/40"
                                :class="[index === 0 && gi > 0 ? 'border-t-2 !border-t-borderline' : '', row.avail_qty < 0 ? 'bg-danger/5' : '']"
                            >
                                <td v-if="index === 0" class="sticky left-0 z-10 border-r border-borderline bg-surface px-3 py-2.5 align-top group-hover:bg-primary-light" :rowspan="group.rows.length">
                                    <div class="flex flex-col items-start gap-1">
                                        <span class="font-semibold leading-tight text-ink-primary">{{ group.machine?.machine_name ?? t('production.noMachine') }}</span>
                                        <span v-if="processNames(group.rows)" class="text-[11px] tracking-wide text-ink-secondary">{{ processNames(group.rows) }}</span>
                                        <span class="mt-0.5 inline-flex rounded-md bg-background px-1.5 py-0.5 text-[11px] font-medium text-ink-secondary">{{ t('production.rowsCount', { n: group.rows.length }) }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="font-medium text-ink-primary">{{ row.fg_part?.part_number ?? row.work_order?.part?.part_number ?? '—' }}</div>
                                    <div class="truncate text-xs text-ink-secondary">{{ row.fg_part?.part_name ?? row.work_order?.part?.part_name ?? '' }}</div>
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="text-ink-primary">{{ row.fg_part?.model ?? '—' }}</span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="text-ink-primary">{{ row.input_part?.part_number ?? '—' }}</div>
                                    <div class="truncate text-xs text-ink-secondary">{{ row.input_part?.part_name ?? '' }}</div>
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="font-medium text-ink-primary">{{ row.wip_part?.part_number ?? '—' }}</div>
                                    <div class="truncate text-xs text-ink-secondary">{{ row.wip_part?.part_name ?? '' }}</div>
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <span class="tabular-nums font-medium text-ink-primary">{{ fmtDuration(row.estimated_seconds) }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <span
                                        class="tabular-nums font-semibold"
                                        :class="row.avail_qty < 0 ? 'text-danger' : (Number(row.avail_qty) === 0 ? 'text-success' : 'text-ink-secondary')"
                                    >
                                        {{ fmt(row.avail_qty) }}
                                    </span>
                                </td>
                                <td v-for="(key, idx) in dayKeys" :key="key" class="border-l border-borderline px-2 py-1.5">
                                    <div class="grid gap-1">
                                        <label class="flex items-center gap-1">
                                            <span class="w-8 text-[10px] font-medium uppercase text-ink-secondary">{{ t('production.sequenceShort') }}</span>
                                            <input
                                                v-model="draftFor(row)[sequenceKeyByDay[key]]"
                                                type="number"
                                                step="1"
                                                min="0"
                                                inputmode="numeric"
                                                :aria-label="`${t('production.sequence')} ${dayCols[key]} ${row.fg_part?.part_number ?? ''}`"
                                                :disabled="savingTargets"
                                                class="min-w-0 w-full rounded-md border border-borderline bg-background px-2 py-1 text-right text-xs tabular-nums text-ink-primary focus:border-primary focus:ring-primary disabled:cursor-wait disabled:opacity-60"
                                            />
                                        </label>
                                        <label class="flex items-center gap-1">
                                            <span class="w-8 text-[10px] font-medium uppercase text-ink-secondary">{{ t('production.qtyShort') }}</span>
                                            <input
                                                v-model="draftFor(row)[key]"
                                                type="number"
                                                step="any"
                                                min="0"
                                                inputmode="decimal"
                                                :placeholder="idx === 0 && row.avail_qty > 0 ? fmt(row.avail_qty) : '…'"
                                                :aria-label="`${t(quantityLabelKeyByDay[key])} ${row.fg_part?.part_number ?? ''}`"
                                                :disabled="savingTargets"
                                                class="min-w-0 w-full rounded-md border px-2 py-1 text-right text-xs tabular-nums text-ink-primary placeholder:text-ink-secondary/70 focus:border-primary focus:ring-primary disabled:cursor-wait disabled:opacity-60"
                                                :class="draftFor(row)[key] !== '' ? 'border-primary/50 bg-primary-light/40' : 'border-borderline bg-background'"
                                            />
                                        </label>
                                    </div>
                                </td>
                                <td class="border-l border-borderline bg-surface px-3 py-2.5 group-hover:bg-primary-light">
                                    <div class="flex justify-end gap-1">
                                        <a
                                            v-if="row.work_order"
                                            :href="route('work-orders.show', row.work_order.id)"
                                            :title="t('production.viewWo')"
                                            :aria-label="t('production.viewWo')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-info transition hover:bg-info/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                        >
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                        </a>
                                        <button
                                            type="button"
                                            :title="t('production.editPlan')"
                                            :aria-label="t('production.editPlan')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-primary transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                            @click="openEdit(row)"
                                        >
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                                        </button>
                                        <button
                                            type="button"
                                            :title="t('production.addBelow')"
                                            :aria-label="t('production.addBelow')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-secondary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                            @click="openCreate()"
                                        >
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        </button>
                                        <button
                                            type="button"
                                            :title="t('production.detach')"
                                            :aria-label="t('production.detach')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-danger transition hover:bg-danger/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                            @click="detach(row)"
                                        >
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="groups.length === 0">
                            <td colspan="12" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('production.noPlanRows') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>

        <!-- Modal: History -->
        <Modal :show="showHistory" max-width="2xl" @close="showHistory = false">
            <div class="p-6">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-ink-primary">{{ t('production.planHistory') }}</h3>
                        <p class="mt-1 text-sm text-ink-secondary">{{ t('production.historyForDate', { date: fmtDate(props.date) }) }}</p>
                    </div>
                    <button type="button" class="text-sm text-ink-secondary hover:text-ink-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface" @click="showHistory = false">{{ t('production.cancel') }}</button>
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

        <!-- Modal: WO Baru -->
        <Modal :show="showCreate" max-width="lg" @close="showCreate = false">
            <form class="space-y-4 p-6" @submit.prevent="submitCreate">
                <h3 class="text-lg font-bold text-ink-primary">{{ t('production.addWo') }}</h3>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.finishedGood') }}</label>
                    <FinishedGoodPicker v-model="createForm.fg_part_id" :options="fgParts" />
                    <InputError :message="createForm.errors.fg_part_id" class="mt-1" />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.woQty') }}</label>
                    <input :aria-label="t('production.woQty')" v-model="createForm.qty" type="number" step="any" min="0.0001" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <InputError :message="createForm.errors.qty" class="mt-1" />
                </div>

                <p class="rounded-lg border border-borderline bg-background px-3 py-2 text-xs text-ink-secondary">{{ t('production.autoPlanHint') }}</p>

                <div class="flex items-center justify-end gap-3 border-t border-borderline pt-4">
                    <button type="button" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface" @click="showCreate = false">{{ t('production.cancel') }}</button>
                    <button type="submit" :disabled="createForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                        {{ createForm.processing ? t('production.saving') : t('production.saveWo') }}
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Modal: Edit plan -->
        <Modal :show="showEdit" max-width="lg" @close="showEdit = false">
            <form class="space-y-4 p-6" @submit.prevent="submitEdit">
                <div>
                    <h3 class="text-lg font-bold text-ink-primary">{{ t('production.editPlan') }}</h3>
                    <p v-if="editing?.work_order" class="mt-1 text-sm text-ink-secondary">{{ editing.work_order.wo_no }} · {{ editing.fg_part?.part_number }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.machine') }}</label>
                    <select :aria-label="t('production.machine')" v-model="editForm.machine_id" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option value="">{{ t('production.selectMachine') }}</option>
                        <option v-for="m in machines" :key="m.id" :value="m.id">{{ m.machine_name }}</option>
                    </select>
                    <InputError :message="editForm.errors.machine_id" class="mt-1" />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.wipPart') }}</label>
                    <select :aria-label="t('production.wipPart')" v-model="editForm.wip_part_id" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option value="">{{ t('production.useBom') }}</option>
                        <option v-for="w in wipParts" :key="w.id" :value="w.id">{{ w.part_number }} · {{ w.part_name }}</option>
                    </select>
                    <InputError :message="editForm.errors.wip_part_id" class="mt-1" />
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.dQty') }}</label>
                        <input :aria-label="t('production.dQty')" v-model="editForm.target_d" type="number" step="any" min="0" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="editForm.errors.target_d" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.d1Qty') }}</label>
                        <input :aria-label="t('production.d1Qty')" v-model="editForm.target_d1" type="number" step="any" min="0" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="editForm.errors.target_d1" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.d2Qty') }}</label>
                        <input :aria-label="t('production.d2Qty')" v-model="editForm.target_d2" type="number" step="any" min="0" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="editForm.errors.target_d2" class="mt-1" />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-borderline pt-4">
                    <button type="button" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface" @click="showEdit = false">{{ t('production.cancel') }}</button>
                    <button type="submit" :disabled="editForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                        {{ editForm.processing ? t('production.saving') : t('production.save') }}
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Modal: tempel WO ke plan -->
        <Modal :show="showAttach" max-width="lg" @close="showAttach = false">
            <form class="space-y-4 p-6" @submit.prevent="submitAttach">
                <div>
                    <h3 class="text-lg font-bold text-ink-primary">{{ t('production.attachWo') }}</h3>
                    <p class="mt-1 text-sm text-ink-secondary">{{ t('production.attachHint') }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.workOrder') }}</label>
                    <select :aria-label="t('production.workOrder')" v-model="attachForm.work_order_id" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option value="">{{ t('production.selectWo') }}</option>
                        <option v-for="wo in unplannedWorkOrders" :key="wo.id" :value="wo.id">
                            {{ wo.wo_no }} · {{ wo.part?.part_number ?? '—' }} · {{ fmt(wo.qty) }}
                        </option>
                    </select>
                    <InputError :message="attachForm.errors.work_order_id" class="mt-1" />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.startDate') }}</label>
                    <input :aria-label="t('production.startDate')" v-model="attachForm.plan_date" type="date" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <InputError :message="attachForm.errors.plan_date" class="mt-1" />
                </div>

                <p class="rounded-lg border border-borderline bg-background px-3 py-2 text-xs text-ink-secondary">{{ t('production.autoPlanHint') }}</p>

                <div class="flex items-center justify-end gap-3 border-t border-borderline pt-4">
                    <button type="button" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface" @click="showAttach = false">{{ t('production.cancel') }}</button>
                    <button type="submit" :disabled="attachForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                        {{ attachForm.processing ? t('production.saving') : t('production.attachWo') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
