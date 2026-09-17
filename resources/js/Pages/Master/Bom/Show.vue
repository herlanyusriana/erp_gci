<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import type { Bom } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    bom: Bom;
}>();

const items = computed(() => props.bom.items ?? []);

const fmt = (n: number | null | undefined) => {
    if (n == null) return '—';
    return Number(n).toLocaleString('en-US', { maximumFractionDigits: 4 });
};

const sourceBadge = (s: string | null) => {
    switch (s) {
        case 'Prod':
            return 'bg-primary-light text-primary';
        case 'Vendor':
            return 'bg-amber-100 text-amber-700';
        case 'Subcon':
            return 'bg-purple-100 text-purple-700';
        case 'FREE_ISSUE':
            return 'bg-emerald-100 text-emerald-700';
        default:
            return 'bg-ink-secondary/10 text-ink-secondary';
    }
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
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">
                    {{ t('master.bomPartTitle', { name: bom.part?.part_number ?? '' }) }}
                </h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ bom.part?.part_name }} · {{ bom.part?.model ?? '—' }} ·
                    <span class="font-medium text-ink-primary">{{ t('master.version', { version: bom.version }) }}</span> ·
                    {{ t('master.operationCount', { count: items.length }) }}
                </p>
            </div>
            <div class="rounded-lg border border-borderline bg-surface px-4 py-2 text-sm text-ink-secondary">
                {{ t('master.type') }}: <span class="font-semibold text-primary">{{ bom.part?.part_type?.code ?? '—' }}</span>
                · {{ t('master.uom') }}: <span class="font-semibold text-ink-primary">{{ bom.part?.uom?.code ?? '—' }}</span>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th class="px-3 py-3">{{ t('master.sequence') }}</th>
                            <th class="px-3 py-3">{{ t('master.process') }}</th>
                            <th class="px-3 py-3">{{ t('master.machine') }}</th>
                            <th class="px-3 py-3">{{ t('master.parent') }}</th>
                            <th class="px-3 py-3 text-right">{{ t('master.parentQty') }}</th>
                            <th class="px-3 py-3">{{ t('master.child') }}</th>
                            <th class="px-3 py-3">{{ t('master.size') }}</th>
                            <th class="px-3 py-3 text-right">{{ t('master.childQty') }}</th>
                            <th class="px-3 py-3">{{ t('master.uom') }}</th>
                            <th class="px-3 py-3">{{ t('master.special') }}</th>
                            <th class="px-3 py-3">{{ t('master.source') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="it in items" :key="it.id" class="align-top hover:bg-primary-light/40">
                            <td class="px-3 py-2.5 tabular-nums text-ink-secondary">{{ it.sequence ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-ink-primary">{{ it.process?.process_name ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-ink-secondary">{{ it.machine?.machine_name ?? '—' }}</td>
                            <td class="px-3 py-2.5">
                                <div class="font-medium text-ink-primary">{{ it.parent_part?.part_number ?? it.parent_part_name ?? '—' }}</div>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-secondary">{{ fmt(it.parent_qty) }}</td>
                            <td class="px-3 py-2.5">
                                <div class="font-medium text-ink-primary">{{ it.child_part?.part_number ?? it.child_part_name ?? '—' }}</div>
                                <div v-if="it.child_part_name" class="text-xs text-ink-secondary">{{ it.child_part_name }}</div>
                            </td>
                            <td class="px-3 py-2.5 text-ink-secondary">{{ it.size ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-primary">{{ fmt(it.child_qty) }}</td>
                            <td class="px-3 py-2.5 text-ink-secondary">{{ it.uom_rm ?? '-' }}</td>
                            <td class="px-3 py-2.5">
                                <span v-if="it.special_code" class="rounded-md bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">{{ it.special_code }}</span>
                                <span v-else class="text-ink-secondary">—</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <span :class="sourceBadge(it.source)" class="rounded-md px-2 py-0.5 text-xs font-semibold">{{ sourceLabel(it.source) }}</span>
                            </td>
                        </tr>
                        <tr v-if="items.length === 0">
                            <td colspan="11" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.bomItemsEmpty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>