<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import StatusBadge from '@/Components/StatusBadge.vue';

const { t, locale } = useI18n();

interface Step {
    parent_part_id: number;
    part_number: string | null;
    part_name: string | null;
    part_type: string;
    process: string | null;
    machine: string | null;
    machine_id: number | null;
    sequence: number | null;
    target_qty: number;
    produced_qty: number;
}

interface ResultRow {
    id: number;
    qty_good: number;
    qty_reject: number;
    result_date: string;
    shift: string | null;
    parent_part?: { part_number: string; part_name: string } | null;
    process?: { process_name: string } | null;
    machine?: { machine_name: string } | null;
    reporter?: { name: string } | null;
}

const props = defineProps<{
    workOrder: { id: number; wo_no: string; qty: number; status: string };
    workOrderPart: { id: number; part_number: string; part_name: string } | null;
    steps: Step[];
    results: ResultRow[];
}>();

const fmt = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 4 });

const form = useForm({
    parent_part_id: '',
    qty_good: '',
    qty_reject: '',
    result_date: new Date().toISOString().slice(0, 10),
    shift: '',
    notes: '',
});

function pick(step: Step) {
    form.parent_part_id = String(step.parent_part_id);
    form.qty_good = String(Math.max(0, step.target_qty - step.produced_qty));
    form.qty_reject = '';
    form.clearErrors();
}

function submit() {
    form.post(route('work-orders.results.store', props.workOrder.id), {
        preserveScroll: true,
        onSuccess: () => form.reset('qty_good', 'qty_reject', 'notes'),
    });
}

function remove(row: ResultRow) {
    if (confirm(t('production.resultDeleteConfirm'))) {
        router.delete(route('work-orders.results.destroy', [props.workOrder.id, row.id]), { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout>
        <Head :title="t('production.resultsFor', { number: workOrder.wo_no })" />
        <BackButton :href="route('production-results.index')" class="mb-4" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.results') }}</h1>
            <p class="mt-1 text-sm text-ink-secondary">
                {{ workOrder.wo_no }} · {{ workOrderPart?.part_number }} {{ workOrderPart?.part_name }} · {{ t('production.fgQty') }} {{ fmt(workOrder.qty) }}
            </p>
        </div>

        <!-- Steps -->
        <div class="mb-6 rounded-xl border border-borderline bg-surface">
            <div class="border-b border-borderline px-4 py-3 text-sm font-semibold text-ink-primary">{{ t('production.steps') }}</div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-3 py-3">{{ t('production.sequence') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('production.parent') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('production.process') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('production.machine') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('production.target') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('production.produced') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('production.remain') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('production.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="s in steps" :key="s.parent_part_id" class="hover:bg-primary-light/40">
                            <td class="px-3 py-2.5 tabular-nums text-ink-secondary">{{ s.sequence ?? '—' }}</td>
                            <td class="px-3 py-2.5">
                                <div class="font-medium text-ink-primary">{{ s.part_number }}</div>
                                <div class="text-xs text-ink-secondary">{{ s.part_name }}</div>
                            </td>
                            <td class="px-3 py-2.5 text-ink-primary">{{ s.process ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-ink-primary">{{ s.machine ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-secondary">{{ fmt(s.target_qty) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums font-semibold text-ink-primary">{{ fmt(s.produced_qty) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums" :class="s.produced_qty >= s.target_qty ? 'text-success' : 'text-warning'">
                                {{ fmt(Math.max(0, s.target_qty - s.produced_qty)) }}
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <button type="button" class="rounded-md border border-primary px-3 py-1 text-xs font-semibold text-primary transition hover:bg-primary-light" @click="pick(s)">
                                    {{ t('production.report') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!steps.length">
                            <td colspan="8" class="px-3 py-10 text-center text-sm text-ink-secondary">{{ t('production.noSteps') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Form -->
        <form class="mb-6 rounded-xl border border-borderline bg-surface p-5" @submit.prevent="submit">
            <h2 class="mb-4 text-sm font-semibold text-ink-primary">{{ t('production.reportResult') }}</h2>
            <div class="grid gap-4 sm:grid-cols-12">
                <div class="sm:col-span-4">
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('production.step') }}</label>
                    <select :aria-label="t('production.step')" v-model="form.parent_part_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option value="">{{ t('production.selectStep') }}</option>
                        <option v-for="s in steps" :key="s.parent_part_id" :value="s.parent_part_id">
                            {{ s.sequence }} · {{ s.part_number }} — {{ s.process ?? '-' }}
                        </option>
                    </select>
                    <InputError :message="form.errors.parent_part_id" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('production.qtyGood') }}</label>
                    <input :aria-label="t('production.qtyGood')" v-model="form.qty_good" type="number" step="any" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <InputError :message="form.errors.qty_good" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('production.qtyReject') }}</label>
                    <input :aria-label="t('production.qtyReject')" v-model="form.qty_reject" type="number" step="any" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('production.resultDate') }}</label>
                    <input :aria-label="t('production.resultDate')" v-model="form.result_date" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('production.shift') }}</label>
                    <input :aria-label="t('production.shift')" v-model="form.shift" type="text" :placeholder="t('production.shiftPlaceholder')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                </div>
                <div class="sm:col-span-10">
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('production.notes') }}</label>
                    <input :aria-label="t('production.notes')" v-model="form.notes" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                </div>
                <div class="flex items-end sm:col-span-2">
                    <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                        {{ form.processing ? t('production.saving') : t('production.save') }}
                    </button>
                </div>
            </div>
        </form>

        <!-- History -->
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="border-b border-borderline px-4 py-3 text-sm font-semibold text-ink-primary">{{ t('production.resultHistory') }}</div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-3 py-3">{{ t('production.resultDate') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('production.parent') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('production.process') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('production.machine') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('production.qtyGood') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('production.qtyReject') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('production.reportedBy') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('production.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="r in results" :key="r.id" class="hover:bg-primary-light/40">
                            <td class="px-3 py-2.5 text-ink-primary">{{ r.result_date }}</td>
                            <td class="px-3 py-2.5">
                                <span class="font-medium text-ink-primary">{{ r.parent_part?.part_number }}</span>
                                <span class="text-xs text-ink-secondary"> · {{ r.parent_part?.part_name }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-ink-secondary">{{ r.process?.process_name ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-ink-secondary">{{ r.machine?.machine_name ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums font-semibold text-success">{{ fmt(r.qty_good) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-danger">{{ fmt(r.qty_reject) }}</td>
                            <td class="px-3 py-2.5 text-ink-secondary">{{ r.reporter?.name ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-right">
                                <button type="button" class="rounded-md border border-danger/30 px-3 py-1 text-xs font-semibold text-danger transition hover:bg-danger/10" @click="remove(r)">
                                    {{ t('production.delete') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!results.length">
                            <td colspan="8" class="px-3 py-10 text-center text-sm text-ink-secondary">{{ t('production.noResults') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
