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
import type { MachineCycleTime, Machine, Part, Paginated, PageProps } from '@/types';

const { t, locale } = useI18n();

const page = usePage<PageProps>();

const props = defineProps<{
    cycleTimes: Paginated<MachineCycleTime>;
    filters: { search?: string };
    machines: Array<Pick<Machine, 'id' | 'machine_code' | 'machine_name'>>;
    parts: Array<Pick<Part, 'id' | 'part_number' | 'part_name'>>;
}>();

const search = ref(props.filters.search ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('cycle-times.index'), { search: search.value || undefined }, { preserveState: true, replace: true });
    }, 300);
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

const showForm = ref(false);
const editing = ref<MachineCycleTime | null>(null);
const form = useForm({
    machine_id: '',
    part_id: '',
    cycle_time_seconds: '' as number | '',
    is_active: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}
function openEdit(c: MachineCycleTime) {
    editing.value = c;
    form.clearErrors();
    form.machine_id = c.machine_id != null ? String(c.machine_id) : '';
    form.part_id = c.part_id != null ? String(c.part_id) : '';
    form.cycle_time_seconds = c.cycle_time_seconds ?? '';
    form.is_active = c.is_active;
    showForm.value = true;
}
function closeForm() {
    showForm.value = false;
    editing.value = null;
}
function submit() {
    if (editing.value) form.put(route('cycle-times.update', editing.value.id), { onSuccess: closeForm });
    else form.post(route('cycle-times.store'), { onSuccess: closeForm });
}
function remove(c: MachineCycleTime) {
    if (confirm(t('master.deleteCycleTime'))) router.delete(route('cycle-times.destroy', c.id));
}

/** Detik → teks durasi yang enak dibaca. */
const fmtDuration = (seconds: number | null | undefined) => {
    if (seconds == null) return '—';
    const total = Number(seconds);
    if (!Number.isFinite(total) || total <= 0) return '—';
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const secs = Math.round(total % 60);
    if (hours > 0) return `${hours} ${t('master.hourShort')} ${minutes} ${t('master.minuteShort')}`;
    if (minutes > 0) return `${minutes} ${t('master.minuteShort')} ${secs} ${t('master.secondShort')}`;
    return `${secs} ${t('master.secondShort')}`;
};

const fmtNumber = (n: number | null | undefined, decimals = 2) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: decimals });
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.cycleTimeTitle') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('master.cycleTimeSubtitle') }}</p>
            </div>
            <button v-if="perms.create" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('master.add') }}
            </button>
        </div>

        <input v-model="search" type="search" :placeholder="t('master.cycleTimeSearch')" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <div class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">{{ t('master.machine') }}</th>
                        <th class="px-4 py-3">{{ t('master.part') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('master.cycleTimeSeconds') }}</th>
                        <th class="px-4 py-3">{{ t('master.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('master.actions') }}</th>
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
                        <td class="px-4 py-3 text-right">
                            <div class="tabular-nums font-semibold text-ink-primary">{{ fmtNumber(c.cycle_time_seconds, 4) }}</div>
                            <div class="text-xs text-ink-secondary">{{ t('master.secondsPerPiece') }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="c.is_active ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'" class="rounded-md px-2 py-0.5 text-xs font-semibold">{{ c.is_active ? t('master.active') : t('master.inactive') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton v-if="perms.update" :label="t('master.edit')" variant="edit" @click="openEdit(c)" />
                                <ActionButton v-if="perms.delete" :label="t('master.delete')" variant="delete" @click="remove(c)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="cycleTimes.data.length === 0">
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.cycleTimeEmpty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="cycleTimes.links" />

        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? t('master.editCycleTime') : t('master.addCycleTime') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.machine') }}</label>
                        <select v-model="form.machine_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('master.selectMachine') }}</option>
                            <option v-for="m in machines" :key="m.id" :value="String(m.id)">{{ m.machine_name }}</option>
                        </select>
                        <InputError :message="form.errors.machine_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.part') }}</label>
                        <select v-model="form.part_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('master.selectPart') }}</option>
                            <option v-for="p in parts" :key="p.id" :value="String(p.id)">{{ p.part_number }} · {{ p.part_name }}</option>
                        </select>
                        <InputError :message="form.errors.part_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.cycleTimeSeconds') }}</label>
                        <input v-model="form.cycle_time_seconds" type="number" step="0.0001" min="0.0001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <p class="mt-1 text-xs text-ink-secondary">{{ t('master.cycleTimeHelp') }}</p>
                        <InputError :message="form.errors.cycle_time_seconds" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.status') }}</label>
                        <select v-model="form.is_active" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option :value="true">{{ t('master.active') }}</option>
                            <option :value="false">{{ t('master.inactive') }}</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background">{{ t('master.cancel') }}</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">{{ form.processing ? t('master.saving') : t('master.save') }}</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
