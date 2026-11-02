<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';

const { t, locale } = useI18n();

interface LabelRow {
    id: number;
    work_order_id: number;
    wo_no: string | null;
    part_id: number;
    part_number: string | null;
    part_name: string | null;
    machine_name: string | null;
    shift: string | null;
    period_label: string;
    is_backfilled: boolean;
    qty_good: number;
    qty_reject: number;
    uom: string | null;
    label_status: string;
}

const props = defineProps<{
    rows: LabelRow[];
    filters: { date?: string; status?: string };
    statuses: string[];
}>();

const date = ref(props.filters.date ?? '');
const status = ref(props.filters.status ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;
watch([date, status], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('production-labels.index'), {
            date: date.value || undefined,
            status: status.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
});

const fmt = (n: number | null | undefined) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 4 });

function printLabel(row: LabelRow, kind: 'good' | 'ng') {
    window.open(route('production-labels.print', [row.id, kind]), '_blank', 'noopener');
    // Status bergerak pending -> printed di server; muat ulang agar badge ikut.
    setTimeout(() => router.reload({ only: ['rows'] }), 800);
}

function deliver(row: LabelRow) {
    router.post(route('production-labels.deliver', row.id), {}, { preserveScroll: true });
}

const canPrint = (row: LabelRow) => row.label_status === 'pending' || row.label_status === 'printed';
const canDeliver = (row: LabelRow) => row.label_status === 'printed';
</script>

<template>
    <Head :title="t('production.labelQueue')" />
    <AppLayout>
        <BackButton :href="route('production-data')" class="mb-4" />

        <div class="mb-5">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.labelQueue') }}</h1>
            <p class="mt-1 text-sm text-ink-secondary">{{ t('production.labelQueueDescription') }}</p>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-borderline bg-surface p-3">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.receiptDate') }}</label>
                <input v-model="date" type="date" :aria-label="t('production.receiptDate')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.labelStatus') }}</label>
                <select v-model="status" :aria-label="t('production.labelStatus')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-48">
                    <option value="">{{ t('production.allStatuses') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ t(`production.label_${s}`) }}</option>
                </select>
            </div>
            <span class="ml-auto text-sm text-ink-secondary">
                <span class="font-semibold text-ink-primary">{{ rows.length }}</span> {{ t('production.wipRows') }}
            </span>
        </div>

        <div class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('production.shift') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.period') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.machine') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.woNo') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.part') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.good') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.reject') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.labelStatus') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="row in rows" :key="row.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 text-ink-primary">{{ row.shift ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="tabular-nums text-ink-primary">{{ row.period_label }}</div>
                            <span v-if="row.is_backfilled" class="mt-1 inline-flex rounded bg-warning/15 px-1.5 py-0.5 text-[11px] font-semibold text-warning">
                                {{ t('production.backfilled') }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-ink-secondary">{{ row.machine_name ?? '—' }}</td>
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ row.wo_no ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="text-ink-primary">{{ row.part_number ?? '—' }}</div>
                            <div class="text-xs text-ink-secondary">{{ row.part_name ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-success">{{ fmt(row.qty_good) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-danger">{{ fmt(row.qty_reject) }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge :status="row.label_status">{{ t(`production.label_${row.label_status}`) }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap justify-end gap-1.5">
                                <button
                                    type="button"
                                    :disabled="!canPrint(row) || row.qty_good <= 0"
                                    class="rounded-md border border-primary px-2.5 py-1 text-xs font-semibold text-primary transition hover:bg-primary-light disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    @click="printLabel(row, 'good')"
                                >
                                    {{ t('production.printGood') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="!canPrint(row) || row.qty_reject <= 0"
                                    class="rounded-md border border-danger px-2.5 py-1 text-xs font-semibold text-danger transition hover:bg-danger/10 disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger"
                                    @click="printLabel(row, 'ng')"
                                >
                                    {{ t('production.printNg') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="!canDeliver(row)"
                                    class="rounded-md border border-borderline px-2.5 py-1 text-xs font-semibold text-ink-secondary transition hover:bg-background disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    @click="deliver(row)"
                                >
                                    {{ t('production.markDelivered') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td colspan="9" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('production.labelQueueEmpty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
