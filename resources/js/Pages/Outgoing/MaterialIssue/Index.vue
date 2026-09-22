<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { MaterialIssue, Paginated } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    issues: Paginated<MaterialIssue>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(
        () => router.get(route('material-issues.index'), { search: search.value || undefined }, { preserveState: true, replace: true }),
        300,
    );
}
</script>

<template>
    <Head :title="t('outgoing.materialIssue')" />
    <AppLayout>
        <BackButton :href="route('outgoing-data')" class="mb-4" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('outgoing.materialIssue') }}</h1>
            <p class="mt-1 text-sm text-ink-secondary">{{ t('outgoing.materialIssueTile') }}</p>
        </div>

        <input v-model="search" @input="doSearch" type="search" :placeholder="t('outgoing.search')" :aria-label="t('outgoing.search')" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.issueNo') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.issueDate') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.workOrder') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.receivedBy') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('outgoing.items') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('outgoing.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="it in issues.data" :key="it.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3">
                            <Link :href="route('material-issues.show', it.id)" class="font-medium text-primary underline-offset-2 hover:underline">
                                {{ it.issue_no }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">{{ it.issue_date }}</td>
                        <td class="px-4 py-3 text-ink-primary">
                            {{ it.work_order?.wo_no ?? '—' }}
                            <span class="block text-xs text-ink-secondary">{{ it.work_order?.part?.part_number ?? '' }} {{ it.work_order?.part?.part_name ?? '' }}</span>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">{{ it.received_by ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ it.items_count ?? 0 }}</td>
                        <td class="px-4 py-3"><StatusBadge :status="it.status">{{ t(`outgoing.${it.status}`) }}</StatusBadge></td>
                    </tr>
                    <tr v-if="!issues.data.length">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('outgoing.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="issues.links" />
    </AppLayout>
</template>
