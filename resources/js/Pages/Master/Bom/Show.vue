<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import type { Bom, PageProps } from '@/types';

const { t } = useI18n();

const page = usePage<PageProps>();

const props = defineProps<{
    bom: Bom;
}>();

const items = computed(() => props.bom.items ?? []);

const canUpdate = computed(() => {
    const list: string[] = page.props.auth.permissions ?? [];
    return list.some((x) => ['bom.update', 'master.bom.update'].includes(x));
});

const canViewCycleTimes = computed(() => {
    const list: string[] = page.props.auth.permissions ?? [];
    return list.some((x) => ['machine.view', 'machine.update', 'master.machine.view', 'master.machine.update'].includes(x));
});

const fmt = (n: number | null | undefined) => {
    if (n == null) return '—';
    return Number(n).toLocaleString('en-US', { maximumFractionDigits: 4 });
};


function sourceLabel(source: string | null): string {
    switch (source) {
        case 'Prod': return t('master.productionSource');
        case 'Vendor': return t('master.vendorSource');
        case 'Subcon': return t('master.subcontractSource');
        case 'FREE_ISSUE': return t('master.freeIssueSource');
        default: return source ?? '—';
    }
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('boms.index')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="min-w-0 break-words text-2xl font-bold tracking-tight text-ink-primary">
                    {{ t('master.bomPartTitle', { name: bom.part?.part_number ?? '' }) }}
                </h1>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-secondary">
                    <span v-if="bom.part?.part_name">{{ bom.part.part_name }}</span>
                    <span v-if="bom.part?.model" class="rounded bg-background px-1.5 py-0.5 text-xs text-ink-secondary">{{ bom.part.model }}</span>
                    <span class="font-medium text-ink-primary">{{ t('master.version', { version: bom.version }) }}</span>
                    <span>{{ t('master.operationCount', { count: items.length }) }}</span>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-lg border border-borderline bg-surface px-4 py-2 text-sm text-ink-secondary">
                <span>{{ t('master.type') }}: <span class="font-semibold text-primary">{{ bom.part?.part_type?.code ?? '-' }}</span></span>
                <span class="h-3.5 w-px bg-borderline" aria-hidden="true" />
                <span>{{ t('master.uom') }}: <span class="font-semibold text-ink-primary">{{ bom.part?.uom?.code ?? '-' }}</span></span>
            </div>
            <div class="flex gap-2">
                <Link
                    v-if="canViewCycleTimes"
                    :href="route('cycle-times.index')"
                    class="inline-flex items-center whitespace-nowrap rounded-md border border-borderline bg-surface px-3 py-1.5 text-xs font-semibold text-ink-primary transition hover:bg-background active:scale-[0.98] motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                >
                    {{ t('master.manageCycleTime') }}
                </Link>
                <ActionButton v-if="canUpdate" :href="route('boms.edit', bom.id)" :label="t('master.edit')" variant="edit" />
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold text-ink-secondary">
                            <th scope="col" class="px-3 py-3">{{ t('master.sequence') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('master.process') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('master.machine') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('master.parent') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('master.parentQty') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('master.child') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('master.model') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('master.size') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('master.childQty') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('master.uom') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('master.special') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('master.source') }}</th>
                            <th scope="col" class="px-3 py-3">{{ t('master.outputType') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('master.tactTimeShort') }}</th>
                            <th scope="col" class="px-3 py-3 text-right">{{ t('master.qdcShort') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="it in items" :key="it.id" class="align-top hover:bg-primary-light/40">
                            <td class="px-3 py-2.5 tabular-nums text-ink-secondary">{{ it.sequence ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-ink-primary">{{ it.process?.process_name ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-ink-secondary">{{ it.machine?.machine_name ?? '—' }}</td>
                            <td class="px-3 py-2.5">
                                <div class="font-medium text-ink-primary">{{ it.parent_part?.part_number ?? it.parent_part_name ?? '—' }}</div>
                                <div v-if="it.parent_part?.model" class="text-xs text-ink-secondary">{{ it.parent_part.model }}</div>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-secondary">{{ fmt(it.parent_qty) }}</td>
                            <td class="px-3 py-2.5">
                                <div class="font-medium text-ink-primary">{{ it.child_part?.part_number ?? it.child_part_name ?? '—' }}</div>
                                <div v-if="it.child_part_name" class="text-xs text-ink-secondary">{{ it.child_part_name }}</div>
                            </td>
                            <td class="px-3 py-2.5 text-ink-secondary">{{ it.child_part?.model || '—' }}</td>
                            <td class="px-3 py-2.5 text-ink-secondary">{{ it.size ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-primary">{{ fmt(it.child_qty) }}</td>
                            <td class="px-3 py-2.5 text-ink-secondary">{{ it.uom_rm ?? '-' }}</td>
                            <td class="px-3 py-2.5">
                                <StatusBadge v-if="it.special_code" tone="danger">{{ it.special_code }}</StatusBadge>
                                <span v-else class="text-ink-secondary">-</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <StatusBadge :status="it.source">{{ sourceLabel(it.source) }}</StatusBadge>
                            </td>
                            <td class="px-3 py-2.5">
                                <StatusBadge v-if="it.output_type === 'shared'" tone="info">{{ t('master.outputTypeShared') }}</StatusBadge>
                                <StatusBadge v-else-if="it.output_type === 'byproduct'" tone="warning">{{ t('master.outputTypeByproduct') }}</StatusBadge>
                                <span v-else class="text-xs text-ink-secondary">{{ t('master.outputTypeNormal') }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-primary">
                                {{ it.tact_time_seconds != null ? `${fmt(it.tact_time_seconds)} ${t('master.secondShort')}` : '—' }}
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-secondary">
                                {{ it.qdc_minutes != null ? `${fmt(it.qdc_minutes)} ${t('master.minuteShort')}` : '—' }}
                            </td>
                        </tr>
                        <tr v-if="items.length === 0">
                            <td colspan="15" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.bomItemsEmpty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>