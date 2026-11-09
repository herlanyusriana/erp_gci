<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import { Head, Link } from '@inertiajs/vue3';
import type { IncomingArrival } from '@/types';

const { t } = useI18n();

defineProps<{
    pendingArrivals: IncomingArrival[];
}>();
</script>

<template>
    <Head :title="t('incoming.receive')" />
    <AppLayout>
        <BackButton :href="route('incoming-data')" class="mb-4" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.receive') }}</h1>
            <p class="mt-1 text-sm text-ink-secondary">{{ t('incoming.receiveDescription') }}</p>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
                        <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('incoming.arrivalNo') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('incoming.invoice') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('incoming.supplier') }}</th>
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
                            <td class="px-4 py-3 text-ink-primary">{{ a.invoice_no ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-primary">{{ a.supplier?.supplier_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold text-warning">{{ a.remaining_qty }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ a.pending_items_count }}</td>
                            <td class="px-4 py-3 text-center whitespace-nowrap">
                                <span
                                    v-if="a.iqc_status === 'passed'"
                                    class="inline-flex items-center gap-1 rounded-full bg-success/15 px-2.5 py-0.5 text-xs font-bold text-success"
                                >
                                    ✓ PASS
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 rounded-full bg-warning/15 px-2.5 py-0.5 text-xs font-semibold text-warning"
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
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noPending') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>