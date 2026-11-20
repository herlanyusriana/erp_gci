<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { arrivalSenderName, arrivalTypeBadgeClass, arrivalTypeLabel, resolveArrivalType } from '@/utils/arrivalType';
import type { IncomingArrival } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    pendingArrivals: IncomingArrival[];
    filters?: { arrival_type?: string | null };
}>();

const arrivalType = ref(props.filters?.arrival_type ?? '');

function doFilter() {
    router.get(
        route('receive.index'),
        { arrival_type: arrivalType.value || undefined },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <Head :title="t('incoming.receive')" />
    <AppLayout>
        <BackButton :href="route('incoming-data')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.receive') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('incoming.receiveDescription') }}</p>
            </div>
            <select
                v-model="arrivalType"
                :aria-label="t('incoming.arrivalType')"
                @change="doFilter"
                class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-48"
            >
                <option value="">{{ t('incoming.allArrivalTypes') }}</option>
                <option value="import">{{ t('incoming.arrivalType_import') }}</option>
                <option value="local">{{ t('incoming.arrivalType_local') }}</option>
                <option value="osp_gci">{{ t('incoming.arrivalType_osp_gci') }}</option>
                <option value="osp_customer">{{ t('incoming.arrivalType_osp_customer') }}</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
                        <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('incoming.arrivalNo') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('incoming.arrivalType') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('incoming.invoice') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('incoming.sender') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.remainingQty') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.pendingItems') }}</th>
                            <th scope="col" class="px-4 py-3 text-center">Status IQC</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="a in pendingArrivals" :key="a.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3">
                                <Link :href="route('incoming-arrivals.show', a.id)" class="font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ a.arrival_no }}</Link>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold', arrivalTypeBadgeClass(resolveArrivalType(a))]">
                                    {{ arrivalTypeLabel(a, t) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-ink-primary">
                                <div>{{ a.invoice_no ?? a.delivery_note_no ?? '—' }}</div>
                                <div v-if="a.contract_no" class="text-xs text-ink-secondary font-mono">{{ a.contract_no }}</div>
                            </td>
                            <td class="px-4 py-3 text-ink-primary">{{ arrivalSenderName(a) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold text-warning">{{ a.remaining_qty }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ a.pending_items_count }}</td>
                            <td class="px-4 py-3 text-center whitespace-nowrap">
                                <span
                                    v-if="a.iqc_status === 'passed'"
                                    class="inline-flex items-center gap-1 rounded-full bg-success/15 px-2.5 py-0.5 text-xs font-bold text-success-ink"
                                >
                                    ✓ PASS
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 rounded-full bg-warning/15 px-2.5 py-0.5 text-xs font-semibold text-warning-ink"
                                >
                                    ● MENUNGGU IQC
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <Link
                                        :href="route('receive.show', a.id)"
                                        :disabled="a.iqc_status !== 'passed'"
                                        class="rounded-md px-3 py-2 text-xs font-semibold text-white transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                        :class="a.iqc_status === 'passed' ? 'bg-primary hover:bg-primary-hover' : 'bg-ink-secondary/40 cursor-not-allowed pointer-events-none'"
                                    >
                                        {{ t('incoming.receiveAction') }}
                                    </Link>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!pendingArrivals.length">
                            <td colspan="8" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noPending') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>