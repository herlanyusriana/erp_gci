<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref, computed, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import type { MachineCycleTime, MachineCycleTimeMatrixRow, Machine, Part, Paginated, PageProps } from '@/types';
import StatusBadge from '@/Components/StatusBadge.vue';

const { t, locale } = useI18n();

const page = usePage<PageProps>();

const props = defineProps<{
    cycleTimes: Paginated<MachineCycleTime>;
    filters: { search?: string | null; machine_id?: string | null };
    machines: Array<Pick<Machine, 'id' | 'machine_code' | 'machine_name'>>;
    parts: Array<Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'>>;
    machineRows: MachineCycleTimeMatrixRow[];
}>();

const partOptions = computed(() =>
    props.parts.map((p) => ({
        id: p.id,
        part_number: p.part_number,
        sublabel: [p.part_name, p.model].filter(Boolean).join(' · '),
    })),
);

const search = ref(props.filters.search ?? '');
const machineId = ref(props.filters.machine_id ?? '');

const selectedMachine = computed(() =>
    props.machines.find((m) => String(m.id) === String(machineId.value)) ?? null,
);

function visitWithFilters() {
    router.get(
        route('cycle-times.index'),
        {
            search: search.value || undefined,
            machine_id: machineId.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(visitWithFilters, 300);
});

watch(machineId, () => {
    clearTimeout(timer);
    visitWithFilters();
});

const perms = computed(() => {
    const list: string[] = page.props.auth.permissions ?? [];
    const has = (p: string) => list.some((x) => [p, 'master.' + p].includes(x));
    return {
        create: has('machine.create') || has('machine.update'),
        update: has('machine.update'),
        delete: has('machine.delete') || has('machine.update'),
    };
});

interface BulkRowDraft {
    part_id: number;
    part_number: string | null;
    part_name: string | null;
    model: string | null;
    fg_part_number: string | null;
    fg_part_name: string | null;
    fg_model: string | null;
    process_name: string | null;
    cycle_time_seconds: number | '';
    qdc_minutes: number | '';
    is_active: boolean;
}

function toBulkDrafts(rows: MachineCycleTimeMatrixRow[]): BulkRowDraft[] {
    return rows.map((row) => ({
        ...row,
        cycle_time_seconds: row.cycle_time_seconds ?? '',
        qdc_minutes: row.qdc_minutes ?? '',
        is_active: row.is_active,
    }));
}

const bulkForm = useForm<{
    machine_id: string;
    rows: BulkRowDraft[];
}>({
    machine_id: props.filters.machine_id ?? '',
    rows: toBulkDrafts(props.machineRows),
});

watch(
    () => [props.filters.machine_id, props.machineRows] as const,
    ([nextMachineId, nextRows]) => {
        bulkForm.machine_id = nextMachineId ?? '';
        bulkForm.rows = toBulkDrafts(nextRows);
        bulkForm.clearErrors();
    },
);

function bulkError(index: number, field: 'cycle_time_seconds' | 'qdc_minutes'): string | undefined {
    const errors = bulkForm.errors as Record<string, string | undefined>;
    return errors[`rows.${index}.${field}`];
}

function submitBulk() {
    if (!selectedMachine.value || bulkForm.rows.length === 0) return;

    bulkForm
        .transform((data) => ({
            machine_id: Number(data.machine_id),
            rows: data.rows.map((r) => ({
                part_id: r.part_id,
                cycle_time_seconds: r.cycle_time_seconds === '' ? null : Number(r.cycle_time_seconds),
                qdc_minutes: r.qdc_minutes === '' ? null : Number(r.qdc_minutes),
                is_active: r.is_active,
            })),
        }))
        .post(route('cycle-times.bulk'), {
            preserveScroll: true,
        });
}

const showForm = ref(false);
const editing = ref<MachineCycleTime | null>(null);
const form = useForm({
    machine_id: '',
    part_id: '',
    cycle_time_seconds: '' as number | '',
    qdc_minutes: '' as number | '',
    is_active: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    if (machineId.value) {
        form.machine_id = String(machineId.value);
    }
    showForm.value = true;
}
function openEdit(c: MachineCycleTime) {
    editing.value = c;
    form.clearErrors();
    form.machine_id = c.machine_id != null ? String(c.machine_id) : '';
    form.part_id = c.part_id != null ? String(c.part_id) : '';
    form.cycle_time_seconds = c.cycle_time_seconds ?? '';
    form.qdc_minutes = c.qdc_minutes ?? '';
    form.is_active = c.is_active;
    showForm.value = true;
}
function closeForm() {
    showForm.value = false;
    editing.value = null;
}
function submit() {
    const options = { onSuccess: closeForm };
    const payload = form.transform((data) => ({
        ...data,
        qdc_minutes: data.qdc_minutes === '' ? null : data.qdc_minutes,
    }));
    if (editing.value) payload.put(route('cycle-times.update', editing.value.id), options);
    else payload.post(route('cycle-times.store'), options);
}
function remove(c: MachineCycleTime) {
    if (confirm(t('master.deleteCycleTime'))) router.delete(route('cycle-times.destroy', c.id));
}

const fmtNumber = (n: number | null | undefined, decimals = 2) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: decimals });
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.cycleTimeTitle') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('master.cycleTimeSubtitle') }}</p>
            </div>
            <button v-if="perms.create" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('master.add') }}
            </button>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <select
                v-model="machineId"
                :aria-label="t('master.machine')"
                class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-64"
            >
                <option value="">{{ t('master.allMachines') }}</option>
                <option v-for="m in machines" :key="m.id" :value="String(m.id)">
                    {{ m.machine_name }} ({{ m.machine_code }})
                </option>
            </select>
            <input
                v-if="!selectedMachine"
                v-model="search"
                type="search"
                :placeholder="t('master.cycleTimeSearch')"
                :aria-label="t('master.cycleTimeSearch')"
                class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80"
            />
        </div>

        <form v-if="selectedMachine" @submit.prevent="submitBulk" class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-borderline bg-background px-4 py-3">
                <div>
                    <h2 class="text-sm font-semibold text-ink-primary">
                        {{ t('master.machineBulkModeTitle', { machine: selectedMachine.machine_name }) }}
                    </h2>
                    <p class="mt-0.5 text-xs text-ink-secondary">{{ t('master.machineBulkModeHint') }}</p>
                </div>
                <button
                    v-if="perms.create && bulkForm.rows.length > 0"
                    type="submit"
                    :disabled="bulkForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                >
                    {{ bulkForm.processing ? t('master.saving') : t('master.saveAll') }}
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('master.fgPart') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.part') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.model') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.process') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('master.cycleTimeSeconds') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('master.qdcMinutes') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="(row, idx) in bulkForm.rows" :key="row.part_id" class="align-top hover:bg-primary-light/40">
                            <td class="px-4 py-3">
                                <div class="font-medium text-ink-primary">{{ row.fg_part_number ?? '—' }}</div>
                                <div v-if="row.fg_part_name" class="text-xs text-ink-secondary">{{ row.fg_part_name }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-ink-primary">{{ row.part_number ?? '—' }}</div>
                                <div v-if="row.part_name" class="text-xs text-ink-secondary">{{ row.part_name }}</div>
                            </td>
                            <td class="px-4 py-3 text-ink-secondary">{{ row.model || '—' }}</td>
                            <td class="px-4 py-3 text-ink-secondary">{{ row.process_name || '—' }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <input
                                    v-model="row.cycle_time_seconds"
                                    type="number"
                                    step="0.0001"
                                    min="0.0001"
                                    :disabled="!perms.create"
                                    :aria-label="`${t('master.cycleTimeSeconds')} ${row.part_number ?? ''}`"
                                    class="w-28 rounded-lg border-borderline bg-surface px-2.5 py-1.5 text-right text-sm tabular-nums text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-60"
                                />
                                <InputError :message="bulkError(idx, 'cycle_time_seconds')" class="mt-1 text-left" />
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <input
                                    v-model="row.qdc_minutes"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max="9999"
                                    :disabled="!perms.create"
                                    :aria-label="`${t('master.qdcMinutes')} ${row.part_number ?? ''}`"
                                    class="w-24 rounded-lg border-borderline bg-surface px-2.5 py-1.5 text-right text-sm tabular-nums text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-60"
                                />
                                <InputError :message="bulkError(idx, 'qdc_minutes')" class="mt-1 text-left" />
                            </td>
                            <td class="px-4 py-2.5">
                                <select
                                    v-model="row.is_active"
                                    :disabled="!perms.create"
                                    :aria-label="`${t('master.status')} ${row.part_number ?? ''}`"
                                    class="rounded-lg border-borderline bg-surface px-2.5 py-1.5 text-xs text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-60"
                                >
                                    <option :value="true">{{ t('master.active') }}</option>
                                    <option :value="false">{{ t('master.inactive') }}</option>
                                </select>
                            </td>
                        </tr>
                        <tr v-if="bulkForm.rows.length === 0">
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.noPartsForMachine') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </form>

        <template v-else>
            <div class="overflow-x-auto rounded-xl border border-borderline bg-surface">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('master.machine') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.part') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.model') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('master.cycleTimeSeconds') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('master.qdcMinutes') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.status') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('master.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="c in cycleTimes.data" :key="c.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3">
                                <div class="font-medium text-ink-primary">{{ c.machine?.machine_name ?? '—' }}</div>
                                <div class="text-xs text-ink-secondary">{{ c.machine?.machine_code ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-ink-primary">{{ c.part?.part_number ?? '—' }}</div>
                                <div class="text-xs text-ink-secondary">{{ c.part?.part_name ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 text-ink-secondary">{{ c.part?.model || '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="tabular-nums font-semibold text-ink-primary">{{ fmtNumber(c.cycle_time_seconds, 4) }}</div>
                                <div class="text-xs text-ink-secondary">{{ t('master.secondsPerPiece') }}</div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="tabular-nums font-medium text-ink-primary">
                                    {{ c.qdc_minutes != null ? `${fmtNumber(c.qdc_minutes, 2)} ${t('master.minuteShort')}` : '—' }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <StatusBadge :tone="c.is_active ? 'success' : 'warning'">{{ c.is_active ? t('master.active') : t('master.inactive') }}</StatusBadge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <ActionButton v-if="perms.update" :label="t('master.edit')" variant="edit" @click="openEdit(c)" />
                                    <ActionButton v-if="perms.delete" :label="t('master.delete')" variant="delete" @click="remove(c)" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="cycleTimes.data.length === 0">
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.cycleTimeEmpty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="cycleTimes.links" />
        </template>

        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? t('master.editCycleTime') : t('master.addCycleTime') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.machine') }}</label>
                        <div class="mt-1">
                            <SearchableSelect
                                v-model="form.machine_id"
                                :options="machines"
                                value-key="id"
                                label-key="machine_name"
                                sublabel-key="machine_code"
                                :placeholder="t('master.selectMachine')"
                                :aria-label="t('master.machine')"
                                :error="form.errors.machine_id"
                            />
                        </div>
                        <InputError :message="form.errors.machine_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.part') }}</label>
                        <div class="mt-1">
                            <SearchableSelect
                                v-model="form.part_id"
                                :options="partOptions"
                                value-key="id"
                                label-key="part_number"
                                sublabel-key="sublabel"
                                :placeholder="t('master.selectPart')"
                                :aria-label="t('master.part')"
                                :error="form.errors.part_id"
                            />
                        </div>
                        <InputError :message="form.errors.part_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.cycleTimeSeconds') }}</label>
                        <input :aria-label="t('master.cycleTimeSeconds')" v-model="form.cycle_time_seconds" type="number" step="0.0001" min="0.0001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <p class="mt-1 text-xs text-ink-secondary">{{ t('master.cycleTimeHelp') }}</p>
                        <InputError :message="form.errors.cycle_time_seconds" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.qdcMinutes') }}</label>
                        <input :aria-label="t('master.qdcMinutes')" v-model="form.qdc_minutes" type="number" step="0.01" min="0" max="9999" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <p class="mt-1 text-xs text-ink-secondary">{{ t('master.qdcHelp') }}</p>
                        <InputError :message="form.errors.qdc_minutes" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.status') }}</label>
                        <select :aria-label="t('master.status')" v-model="form.is_active" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-48">
                            <option :value="true">{{ t('master.active') }}</option>
                            <option :value="false">{{ t('master.inactive') }}</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('master.cancel') }}</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ form.processing ? t('master.saving') : t('master.save') }}</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
