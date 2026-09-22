<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { MaterialIssue } from '@/types';

const { t } = useI18n();

const props = defineProps<{ issue: MaterialIssue }>();

const totalQty = () => (props.issue.items ?? []).reduce((sum, it) => sum + Number(it.qty ?? 0), 0);
</script>

<template>
    <Head :title="t('outgoing.detail')" />
    <AppLayout>
        <BackButton :href="route('material-issues.index')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ issue.issue_no }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ issue.work_order?.wo_no ?? '—' }} · {{ issue.work_order?.part?.part_number ?? '' }} {{ issue.work_order?.part?.part_name ?? '' }}
                </p>
            </div>
            <a :href="route('material-issues.print', issue.id)" target="_blank" rel="noopener" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                {{ t('outgoing.print') }}
            </a>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.issueDate') }}</div>
                <div class="mt-1 text-ink-primary">{{ issue.issue_date }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.issuedBy') }}</div>
                <div class="mt-1 text-ink-primary">{{ issue.issuer?.name ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.receivedBy') }}</div>
                <div class="mt-1 text-ink-primary">{{ issue.received_by ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.status') }}</div>
                <div class="mt-1"><StatusBadge :status="issue.status">{{ t(`outgoing.${issue.status}`) }}</StatusBadge></div>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.part') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.tag') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('outgoing.qty') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.unit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="it in issue.items" :key="it.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ it.part?.part_number }} · {{ it.part?.part_name }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ it.tag ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-ink-primary">{{ it.qty }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ it.uom ?? '—' }}</td>
                    </tr>
                    <tr v-if="!issue.items?.length">
                        <td colspan="4" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('outgoing.noItems') }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="issue.items?.length" class="bg-background">
                    <tr>
                        <td colspan="2" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.totalQty') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-ink-primary">{{ totalQty() }}</td>
                        <td />
                    </tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>
