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

const { t, locale } = useI18n();

const props = defineProps<{
    date: string;
    plan: ProductionPlan | null;
    items: ProductionPlanItem[];
    machines: MachineOpt[];
    fgParts: PartOpt[];
    wipParts: Array<Pick<Part, 'id' | 'part_number' | 'part_name'>>;
    unplannedWorkOrders: Array<{
        id: number;
        wo_no: string;
        part_id: number;
        qty: number;
        status: string;
        planned_date: string | null;
        part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
    }>;
}>();

const date = ref(props.date);
const search = ref('');

function applyDate() {
    router.get(route('production-plans.index'), { date: date.value || undefined }, { preserveState: true, replace: true });
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

const dayLabels = computed(() => ({
    d: fmtDate(props.date),
    d1: fmtDate(addDays(props.date, 1)),
    d2: fmtDate(addDays(props.date, 2)),
}));

const fmt = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 2 });

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
const targetDrafts = reactive<Record<number, { d: string; d1: string; d2: string; saving: boolean }>>({});

function draftFor(it: ProductionPlanItem) {
    if (!targetDrafts[it.id]) {
        targetDrafts[it.id] = {
            d: it.target_d != null ? String(it.target_d) : '',
            d1: it.target_d1 != null ? String(it.target_d1) : '',
            d2: it.target_d2 != null ? String(it.target_d2) : '',
            saving: false,
        };
    }
    return targetDrafts[it.id];
}

watch(() => props.items, (list) => {
    for (const it of list) {
        const draft = targetDrafts[it.id];
        if (draft && !draft.saving) {
            draft.d = it.target_d != null ? String(it.target_d) : '';
            draft.d1 = it.target_d1 != null ? String(it.target_d1) : '';
            draft.d2 = it.target_d2 != null ? String(it.target_d2) : '';
        }
    }
});

function saveTargets(it: ProductionPlanItem) {
    const draft = draftFor(it);
    draft.saving = true;
    router.patch(route('production-plans.items.update', it.id), {
        machine_id: it.machine_id,
        wip_part_id: it.wip_part_id,
        target_d: draft.d,
        target_d1: draft.d1,
        target_d2: draft.d2,
    }, {
        preserveScroll: true,
        onFinish: () => { draft.saving = false; },
    });
}

const emptyRow = (machineId: number | null): ProductionPlanItem => ({
    id: -(machineId ?? 0),
    production_plan_id: 0,
    machine_id: machineId,
    work_order_id: null,
    fg_part_id: null,
    wip_part_id: null,
    sequence: 0,
    target_d: null,
    target_d1: null,
    target_d2: null,
    avail_qty: 0,
});

const groups = computed(() => {
    const list: Array<{ machine: MachineOpt | null; rows: ProductionPlanItem[] }> = props.machines.map((m) => {
        const rows = filteredItems.value
            .filter((it) => it.machine_id === m.id)
            .sort((a, b) => (a.sequence - b.sequence) || (a.id - b.id));
        return {
            machine: m,
            rows: rows.length > 0 ? rows : [emptyRow(m.id)],
        };
    });

    const orphans = filteredItems.value.filter((it) => !props.machines.some((m) => m.id === it.machine_id));
    if (orphans.length > 0) {
        list.push({ machine: null, rows: orphans.sort((a, b) => (a.sequence - b.sequence) || (a.id - b.id)) });
    }

    return list;
});

function move(rows: ProductionPlanItem[], index: number, dir: number) {
    const target = index + dir;
    if (target < 0 || target >= rows.length) return;
    const reordered = rows.slice();
    [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
    router.post(route('production-plans.items.reorder'), {
        items: reordered.map((it, i) => ({ id: it.id, sequence: i + 1 })),
    }, { preserveScroll: true });
}

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
    machine_id: '',
    qty: '',
    wip_part_id: '',
    target_d: '',
    target_d1: '',
    target_d2: '',
});

function openCreate(machineId?: number | null) {
    createForm.reset();
    createForm.clearErrors();
    createForm.plan_date = props.date;
    createForm.machine_id = machineId ? String(machineId) : '';
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
    machine_id: '',
    plan_date: props.date,
    wip_part_id: '',
    target_d: '',
    target_d1: '',
    target_d2: '',
});

function openAttach(wo?: { id: number; qty: number }) {
    attachForm.reset();
    attachForm.clearErrors();
    attachForm.plan_date = props.date;
    if (wo) {
        attachForm.work_order_id = String(wo.id);
        attachForm.target_d = String(wo.qty);
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

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.plan') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ t('production.planDateDescription', { date: fmtDate(date), d: dayLabels.d, d1: dayLabels.d1, d2: dayLabels.d2 }) }}
                </p>
            </div>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                @click="openCreate()"
            >
                {{ t('production.newWo') }}
            </button>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.search') }}</label>
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('production.searchPlan')"
                    class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary placeholder-ink-secondary focus:border-primary focus:ring-primary sm:w-72"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.startDate') }}</label>
                <input
                    v-model="date"
                    type="date"
                    class="rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                    @change="applyDate"
                />
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
                    class="rounded-md border border-warning px-3 py-1.5 text-xs font-semibold text-warning transition hover:bg-warning/10"
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
                    class="rounded-lg border border-borderline bg-surface px-3 py-2 text-left text-xs transition hover:border-warning"
                    @click="openAttach(wo)"
                >
                    <span class="block font-semibold text-ink-primary">{{ wo.wo_no }}</span>
                    <span class="block text-ink-secondary">{{ wo.part?.part_number ?? '—' }} · {{ fmt(wo.qty) }}</span>
                </button>
            </div>
        </div>


        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th class="px-3 py-2 w-36">{{ t('production.machine') }}</th>
                            <th class="px-3 py-2 w-20">{{ t('production.sequence') }}</th>
                            <th class="px-3 py-2">{{ t('production.fgPart') }}</th>
                            <th class="px-3 py-2">{{ t('production.wipPart') }}</th>
                            <th class="px-3 py-2 text-right">{{ t('production.availableQty') }}</th>
                            <th v-for="(label, key) in { d: dayLabels.d, d1: dayLabels.d1, d2: dayLabels.d2 }" :key="key" class="w-24 px-2 py-2 text-center">{{ label }}</th>
                            <th class="px-3 py-2 text-right">{{ t('production.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <template v-for="group in groups" :key="group.machine?.id ?? 'none'">
                            <tr v-for="(row, index) in group.rows" :key="row.id" :class="row.id < 0 ? '' : 'hover:bg-primary-light/40'">
                                <td v-if="index === 0" class="px-3 py-2 align-top" :rowspan="group.rows.length">
                                    <span class="font-semibold text-ink-primary">{{ group.machine?.machine_name ?? t('production.noMachine') }}</span>
                                    <span v-if="group.machine" class="block text-xs text-ink-secondary">{{ group.machine.machine_code }}</span>
                                </td>
                                <td v-if="row.id > 0" class="px-3 py-2">
                                    <div class="flex items-center gap-1">
                                        <span class="w-5 text-center tabular-nums text-ink-secondary">{{ index + 1 }}</span>
                                        <div class="flex flex-col">
                                            <button
                                                type="button"
                                                :title="t('production.moveUp')"
                                                :aria-label="t('production.moveUp')"
                                                :disabled="index === 0"
                                                class="flex h-4 items-center justify-center rounded text-ink-secondary transition hover:bg-background disabled:opacity-30"
                                                @click="move(group.rows, index, -1)"
                                            >
                                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>
                                            </button>
                                            <button
                                                type="button"
                                                :title="t('production.moveDown')"
                                                :aria-label="t('production.moveDown')"
                                                :disabled="index === group.rows.length - 1"
                                                class="flex h-4 items-center justify-center rounded text-ink-secondary transition hover:bg-background disabled:opacity-30"
                                                @click="move(group.rows, index, 1)"
                                            >
                                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                            </button>
                                        </div>
                                    </div>
                                </td>
                                <td v-else-if="row.id < 0" class="px-3 py-2 text-xs text-ink-secondary">—</td>
                                <td v-if="row.id > 0" class="px-3 py-2">
                                    <div class="font-medium text-ink-primary">{{ row.fg_part?.part_name ?? row.work_order?.part?.part_name ?? '—' }}</div>
                                    <div class="text-xs text-ink-secondary">{{ row.fg_part?.part_number ?? row.work_order?.part?.part_number ?? '' }}</div>
                                </td>
                                <td v-else-if="row.id < 0" class="px-3 py-2 text-xs text-ink-secondary">—</td>
                                <td v-if="row.id > 0" class="px-3 py-2">
                                    <div class="text-ink-primary">{{ row.wip_part?.part_name ?? '—' }}</div>
                                    <div class="text-xs text-ink-secondary">{{ row.wip_part?.part_number ?? '' }}</div>
                                </td>
                                <td v-else-if="row.id < 0" class="px-3 py-2 text-xs text-ink-secondary">—</td>
                                <td v-if="row.id > 0" class="px-3 py-2 text-right tabular-nums font-semibold" :class="row.avail_qty < 0 ? 'text-danger' : 'text-ink-secondary'">
                                    {{ fmt(row.avail_qty) }}
                                </td>
                                <td v-else-if="row.id < 0" class="px-3 py-2 text-right text-xs text-ink-secondary">—</td>
                                <template v-if="row.id > 0">
                                    <td v-for="(key, idx) in ['d', 'd1', 'd2'] as const" :key="idx" class="px-2 py-1.5">
                                        <input
                                            v-model="draftFor(row)[key]"
                                            type="number"
                                            step="any"
                                            min="0"
                                            inputmode="decimal"
                                            :placeholder="'…'"
                                            :aria-label="t('production.dQty') + ' ' + row.fg_part?.part_number"
                                            class="w-full rounded-md border-borderline bg-background px-2 py-1.5 text-right text-sm tabular-nums text-ink-primary focus:border-primary focus:ring-primary"
                                            @change="saveTargets(row)"
                                        />
                                    </td>
                                </template>
                                <template v-else>
                                    <td v-for="(_, idx) in ['d', 'd1', 'd2']" :key="idx" class="px-2 py-1.5 text-center text-xs text-ink-secondary">—</td>
                                </template>
                                <td v-if="row.id > 0" class="px-3 py-2">
                                    <div class="flex justify-end gap-1">
                                        <a
                                            v-if="row.work_order"
                                            :href="route('work-orders.show', row.work_order.id)"
                                            :title="t('production.viewWo')"
                                            :aria-label="t('production.viewWo')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-info transition hover:bg-info/10"
                                        >
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                        </a>
                                        <button
                                            type="button"
                                            :title="t('production.editPlan')"
                                            :aria-label="t('production.editPlan')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-primary transition hover:bg-primary-light"
                                            @click="openEdit(row)"
                                        >
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                                        </button>
                                        <button
                                            type="button"
                                            :title="t('production.addBelow')"
                                            :aria-label="t('production.addBelow')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-secondary transition hover:bg-background"
                                            @click="openCreate(row.machine_id)"
                                        >
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        </button>
                                        <button
                                            type="button"
                                            :title="t('production.detach')"
                                            :aria-label="t('production.detach')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-danger transition hover:bg-danger/10"
                                            @click="detach(row)"
                                        >
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                        </button>
                                    </div>
                                </td>
                                <td v-else class="px-3 py-2">
                                    <div class="flex justify-end gap-1">
                                        <button
                                            type="button"
                                            :title="t('production.addBelow')"
                                            :aria-label="t('production.addBelow')"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-secondary transition hover:bg-background"
                                            @click="openCreate(group.machine?.id)"
                                        >
                                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

        </div>

        <!-- Modal: WO Baru -->
        <Modal :show="showCreate" max-width="lg" @close="showCreate = false">
            <form class="space-y-4 p-6" @submit.prevent="submitCreate">
                <h3 class="text-lg font-bold text-ink-primary">{{ t('production.addWo') }}</h3>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.finishedGood') }}</label>
                    <FinishedGoodPicker v-model="createForm.fg_part_id" :options="fgParts" />
                    <InputError :message="createForm.errors.fg_part_id" class="mt-1" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.machine') }}</label>
                        <select v-model="createForm.machine_id" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('production.selectMachine') }}</option>
                            <option v-for="m in machines" :key="m.id" :value="m.id">{{ m.machine_name }}</option>
                        </select>
                        <InputError :message="createForm.errors.machine_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.woQty') }}</label>
                        <input v-model="createForm.qty" type="number" step="any" min="0.0001" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="createForm.errors.qty" class="mt-1" />
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.wipPart') }} <span class="font-normal text-ink-secondary">{{ t('production.optionalBom') }}</span></label>
                    <select v-model="createForm.wip_part_id" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option value="">{{ t('production.useBom') }}</option>
                        <option v-for="w in wipParts" :key="w.id" :value="w.id">{{ w.part_number }} · {{ w.part_name }}</option>
                    </select>
                    <InputError :message="createForm.errors.wip_part_id" class="mt-1" />
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.dQty') }}</label>
                        <input v-model="createForm.target_d" type="number" step="any" min="0" :placeholder="t('production.sameWoQty')" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="createForm.errors.target_d" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.d1Qty') }}</label>
                        <input v-model="createForm.target_d1" type="number" step="any" min="0" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="createForm.errors.target_d1" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.d2Qty') }}</label>
                        <input v-model="createForm.target_d2" type="number" step="any" min="0" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="createForm.errors.target_d2" class="mt-1" />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-borderline pt-4">
                    <button type="button" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background" @click="showCreate = false">{{ t('production.cancel') }}</button>
                    <button type="submit" :disabled="createForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
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
                    <select v-model="editForm.machine_id" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option value="">{{ t('production.selectMachine') }}</option>
                        <option v-for="m in machines" :key="m.id" :value="m.id">{{ m.machine_name }}</option>
                    </select>
                    <InputError :message="editForm.errors.machine_id" class="mt-1" />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.wipPart') }}</label>
                    <select v-model="editForm.wip_part_id" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option value="">{{ t('production.useBom') }}</option>
                        <option v-for="w in wipParts" :key="w.id" :value="w.id">{{ w.part_number }} · {{ w.part_name }}</option>
                    </select>
                    <InputError :message="editForm.errors.wip_part_id" class="mt-1" />
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.dQty') }}</label>
                        <input v-model="editForm.target_d" type="number" step="any" min="0" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="editForm.errors.target_d" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.d1Qty') }}</label>
                        <input v-model="editForm.target_d1" type="number" step="any" min="0" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="editForm.errors.target_d1" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.d2Qty') }}</label>
                        <input v-model="editForm.target_d2" type="number" step="any" min="0" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="editForm.errors.target_d2" class="mt-1" />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-borderline pt-4">
                    <button type="button" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background" @click="showEdit = false">{{ t('production.cancel') }}</button>
                    <button type="submit" :disabled="editForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
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
                    <select v-model="attachForm.work_order_id" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option value="">{{ t('production.selectWo') }}</option>
                        <option v-for="wo in unplannedWorkOrders" :key="wo.id" :value="wo.id">
                            {{ wo.wo_no }} · {{ wo.part?.part_number ?? '—' }} · {{ fmt(wo.qty) }}
                        </option>
                    </select>
                    <InputError :message="attachForm.errors.work_order_id" class="mt-1" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.machine') }}</label>
                        <select v-model="attachForm.machine_id" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('production.selectMachine') }}</option>
                            <option v-for="m in machines" :key="m.id" :value="m.id">{{ m.machine_name }}</option>
                        </select>
                        <InputError :message="attachForm.errors.machine_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.startDate') }}</label>
                        <input v-model="attachForm.plan_date" type="date" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="attachForm.errors.plan_date" class="mt-1" />
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.wipPart') }}</label>
                    <select v-model="attachForm.wip_part_id" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option value="">{{ t('production.useBom') }}</option>
                        <option v-for="w in wipParts" :key="w.id" :value="w.id">{{ w.part_number }} · {{ w.part_name }}</option>
                    </select>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.dQty') }}</label>
                        <input v-model="attachForm.target_d" type="number" step="any" min="0" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="attachForm.errors.target_d" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.d1Qty') }}</label>
                        <input v-model="attachForm.target_d1" type="number" step="any" min="0" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.d2Qty') }}</label>
                        <input v-model="attachForm.target_d2" type="number" step="any" min="0" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-borderline pt-4">
                    <button type="button" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background" @click="showAttach = false">{{ t('production.cancel') }}</button>
                    <button type="submit" :disabled="attachForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                        {{ attachForm.processing ? t('production.saving') : t('production.attachWo') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
