<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useConnectionStatus, useEcho } from '@laravel/echo-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { MaterialIssue, MaterialIssuePostedEvent, MaterialIssueSummary, Paginated, User } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    issues: Paginated<MaterialIssue>;
    filters: { search?: string; date_from?: string; date_to?: string; operator_id?: string | number; status?: string };
    summary: MaterialIssueSummary;
    operators: Array<Pick<User, 'id' | 'name'>>;
    statuses: string[];
}>();

const search = ref(props.filters.search ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');
const operatorId = ref(props.filters.operator_id?.toString() ?? '');
const status = ref(props.filters.status ?? '');
const rows = ref<MaterialIssue[]>([...props.issues.data]);
const liveSummary = ref<MaterialIssueSummary>({ ...props.summary, qty_by_uom: [...props.summary.qty_by_uom] });
const knownWorkOrderIds = ref(new Set(rows.value.map((row) => row.work_order_id)));
const highlightedId = ref<number | null>(null);
const liveNotice = ref('');
const refreshing = ref(false);
const connectionStatus = useConnectionStatus();
let timer: ReturnType<typeof setTimeout> | undefined;
let highlightTimer: ReturnType<typeof setTimeout> | undefined;
function applyFilters() {
    clearTimeout(timer);
    timer = setTimeout(
        () => router.get(route('material-issues.index'), {
            search: search.value || undefined,
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
            operator_id: operatorId.value || undefined,
            status: status.value || undefined,
        }, { preserveState: true, replace: true }),
        300,
    );
}
watch([search, dateFrom, dateTo, operatorId, status], applyFilters);
watch(() => props.issues.data, (data) => {
    rows.value = [...data];
    knownWorkOrderIds.value = new Set(data.map((row) => row.work_order_id));
}, { deep: true });
watch(() => props.summary, (summary) => {
    liveSummary.value = { ...summary, qty_by_uom: [...summary.qty_by_uom] };
}, { deep: true });

const connectionLabel = computed(() => t(`outgoing.connection${connectionStatus.value.charAt(0).toUpperCase()}${connectionStatus.value.slice(1)}`));

function matchesFilters(issue: MaterialIssuePostedEvent): boolean {
    if (dateFrom.value && issue.issue_date < dateFrom.value) {
        return false;
    }
    if (dateTo.value && issue.issue_date > dateTo.value) {
        return false;
    }
    if (operatorId.value && String(issue.operator_id ?? '') !== operatorId.value) {
        return false;
    }
    if (status.value && issue.status !== status.value) {
        return false;
    }
    if (search.value) {
        const term = search.value.toLocaleLowerCase();
        const searchable = [issue.issue_no, issue.work_order_no ?? '', issue.operator_name ?? '', issue.received_by ?? ''].join(' ').toLocaleLowerCase();
        if (!searchable.includes(term)) {
            return false;
        }
    }

    return true;
}

function mergeSummary(issue: MaterialIssuePostedEvent): void {
    liveSummary.value.issue_count += 1;
    if (!knownWorkOrderIds.value.has(issue.work_order_id)) {
        liveSummary.value.work_order_count += 1;
        knownWorkOrderIds.value.add(issue.work_order_id);
    }
    liveSummary.value.tag_count += issue.tag_count;
    const quantities = new Map(liveSummary.value.qty_by_uom.map((quantity) => [quantity.uom, quantity.qty]));
    Object.entries(issue.qty_by_uom).forEach(([uom, qty]) => quantities.set(uom, (quantities.get(uom) ?? 0) + qty));
    liveSummary.value.qty_by_uom = [...quantities.entries()].map(([uom, qty]) => ({ uom, qty }));
}

function handleIssuePosted(payload: { issue: MaterialIssuePostedEvent }): void {
    const issue = payload.issue;
    if (!matchesFilters(issue) || rows.value.some((row) => row.id === issue.issue_id)) {
        return;
    }

    const liveRow: MaterialIssue = {
        id: issue.issue_id,
        issue_no: issue.issue_no,
        work_order_id: issue.work_order_id,
        issue_date: issue.issue_date,
        created_at: new Date().toISOString(),
        issued_by: issue.operator_id,
        received_by: issue.received_by,
        status: issue.status,
        notes: null,
        items_count: issue.item_count,
        issuer: issue.operator_id !== null && issue.operator_name !== null ? { id: issue.operator_id, name: issue.operator_name } : null,
        work_order: issue.work_order_no !== null ? { id: issue.work_order_id, wo_no: issue.work_order_no, part_id: 0, part: null } : null,
    };
    rows.value = [liveRow, ...rows.value];
    mergeSummary(issue);
    highlightedId.value = issue.issue_id;
    liveNotice.value = t('outgoing.liveIssueReceived');
    clearTimeout(highlightTimer);
    highlightTimer = setTimeout(() => { highlightedId.value = null; }, 5000);
}

useEcho<{ issue: MaterialIssuePostedEvent }>('issue-out-monitoring', '.material-issue.posted', handleIssuePosted);

function refresh(): void {
    refreshing.value = true;
    router.reload({ only: ['issues', 'summary', 'filters', 'operators', 'statuses'], onFinish: () => { refreshing.value = false; } });
}

function formatDateTime(value: string | undefined, fallback: string): string {
    if (!value) {
        return fallback;
    }

    return new Date(value).toLocaleString(undefined, { timeZone: 'Asia/Jakarta' });
}
</script>

<template>
    <Head :title="t('outgoing.monitoringIssueOut')" />
    <AppLayout>
        <BackButton :href="route('outgoing-data')" class="mb-4" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('outgoing.monitoringIssueOut') }}</h1>
            <p class="mt-1 text-sm text-ink-secondary">{{ t('outgoing.monitoringDescription') }}</p>
        </div>

        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <label class="text-sm text-ink-secondary">
                <span class="mb-1 block font-medium">{{ t('outgoing.search') }}</span>
                <input v-model="search" type="search" :placeholder="t('outgoing.search')" :aria-label="t('outgoing.search')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </label>
            <label class="text-sm text-ink-secondary">
                <span class="mb-1 block font-medium">{{ t('outgoing.dateFrom') }}</span>
                <input v-model="dateFrom" type="date" :aria-label="t('outgoing.dateFrom')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </label>
            <label class="text-sm text-ink-secondary">
                <span class="mb-1 block font-medium">{{ t('outgoing.dateTo') }}</span>
                <input v-model="dateTo" type="date" :aria-label="t('outgoing.dateTo')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </label>
            <label class="text-sm text-ink-secondary">
                <span class="mb-1 block font-medium">{{ t('outgoing.operator') }}</span>
                <select v-model="operatorId" :aria-label="t('outgoing.operator')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                    <option value="">{{ t('outgoing.allOperators') }}</option>
                    <option v-for="operator in operators" :key="operator.id" :value="operator.id">{{ operator.name }}</option>
                </select>
            </label>
            <label class="text-sm text-ink-secondary">
                <span class="mb-1 block font-medium">{{ t('outgoing.status') }}</span>
                <select v-model="status" :aria-label="t('outgoing.status')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                    <option value="">{{ t('outgoing.allStatuses') }}</option>
                    <option v-for="itemStatus in statuses" :key="itemStatus" :value="itemStatus">{{ t(`outgoing.${itemStatus}`) }}</option>
                </select>
            </label>
        </div>

        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.summaryIssues') }}</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-ink-primary">{{ liveSummary.issue_count }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.summaryWorkOrders') }}</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-ink-primary">{{ liveSummary.work_order_count }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.summaryTags') }}</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-ink-primary">{{ liveSummary.tag_count }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.summaryQty') }}</div>
                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm text-ink-primary">
                    <span v-for="quantity in liveSummary.qty_by_uom" :key="quantity.uom" class="tabular-nums">{{ quantity.qty }} {{ quantity.uom }}</span>
                    <span v-if="!liveSummary.qty_by_uom.length" class="text-ink-secondary">—</span>
                </div>
            </div>
        </div>

        <div class="mb-3 flex flex-wrap items-center justify-between gap-3 text-sm text-ink-secondary" role="status" aria-live="polite">
            <span>{{ connectionLabel }}</span>
            <span v-if="liveNotice" class="text-success">{{ liveNotice }}</span>
            <button type="button" :disabled="refreshing" class="rounded-lg border border-borderline px-3 py-1.5 font-medium text-ink-primary hover:bg-background disabled:cursor-not-allowed disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" @click="refresh">
                {{ refreshing ? '…' : t('outgoing.refresh') }}
            </button>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.issueNo') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.issueDate') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.workOrder') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.issuedBy') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.receivedBy') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('outgoing.items') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('outgoing.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="it in rows" :key="it.id" :class="['hover:bg-primary-light/40', highlightedId === it.id ? 'bg-success/10' : '']">
                        <td class="px-4 py-3">
                            <Link :href="route('material-issues.show', it.id)" class="font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                                {{ it.issue_no }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">
                            {{ it.issue_date }}
                            <span class="block text-xs text-ink-secondary">{{ formatDateTime(it.created_at, '—') }}</span>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">
                            {{ it.work_order?.wo_no ?? '—' }}
                            <span class="block text-xs text-ink-secondary">{{ it.work_order?.part?.part_number ?? '' }} {{ it.work_order?.part?.part_name ?? '' }}</span>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">{{ it.issuer?.name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ it.received_by ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ it.items_count ?? 0 }}</td>
                        <td class="px-4 py-3"><StatusBadge :status="it.status">{{ t(`outgoing.${it.status}`) }}</StatusBadge></td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('material-issues.show', it.id)" class="font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                                {{ t('outgoing.view') }}
                            </Link>
                            <a :href="route('material-issues.print', it.id)" target="_blank" rel="noopener" class="ml-3 font-medium text-ink-secondary underline-offset-2 hover:text-ink-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                                {{ t('outgoing.print') }}
                            </a>
                        </td>
                    </tr>
                    <tr v-if="!issues.data.length">
                        <td colspan="8" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('outgoing.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="issues.links" />
    </AppLayout>
</template>
