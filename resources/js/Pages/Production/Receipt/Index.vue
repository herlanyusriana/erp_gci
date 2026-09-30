<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useConnectionStatus, useEcho } from '@laravel/echo-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import type { Machine } from '@/types';

const { t, locale } = useI18n();

interface ReceiptRow {
    id: number;
    tag: string;
    part: { part_number: string | null; part_name: string | null; model: string | null; size: string | null } | null;
    invoice: string | null;
    supplier: string | null;
    receiver_name: string | null;
    received_at: string | null;
    notes: string | null;
}

interface ReceiptPostedPayload {
    receipt: ReceiptRow;
}

const props = defineProps<{
    machines: Machine[];
    selectedMachineId: number | null;
    date: string;
    receipts: ReceiptRow[];
    timezone: string;
}>();

const machineId = ref(props.selectedMachineId ?? '');
const date = ref(props.date);
const rows = ref<ReceiptRow[]>([...props.receipts]);
const connectionStatus = useConnectionStatus();

const connectionLabel = computed(() => t(`outgoing.connection${connectionStatus.value.charAt(0).toUpperCase()}${connectionStatus.value.slice(1)}`));

function applyFilters() {
    router.get(route('production-receipts.index'), {
        machine_id: machineId.value || undefined,
        date: date.value || undefined,
    }, { preserveState: true, replace: true });
}

watch([machineId, date], applyFilters);

/** Terima receipt baru via Echo */
function handleReceiptPosted(payload: { receipt: ReceiptRow }) {
    const row = payload.receipt;
    if (rows.value.some((r) => r.id === row.id)) return;
    rows.value = [row, ...rows.value];
}

useEcho<{ receipt: ReceiptRow }>('production-receipt-monitoring', '.production-receipt.posted', handleReceiptPosted);

const total = computed(() => rows.value.length);

const fmt = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 2 });
</script>

<template>
    <AppLayout>
        <Head :title="t('production.receipt')" />
        <BackButton :href="route('production-data')" class="mb-4" />

        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.receipt') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('production.receiptDescription') }}</p>
            </div>
            <!-- Statistik realtime -->
            <div class="flex items-center gap-3 rounded-xl border border-borderline bg-surface px-4 py-2 text-sm">
                <div class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full" :class="connectionStatus === 'connected' ? 'bg-success' : 'bg-warning'"></span>
                    <span class="text-ink-secondary">{{ connectionLabel }}</span>
                </div>
                <span class="text-ink-secondary">|</span>
                <span class="font-semibold tabular-nums text-ink-primary">{{ total }}</span>
                <span class="text-ink-secondary">{{ t('production.receiptItems') }}</span>
            </div>
        </div>

        <!-- Filter: mesin + tanggal -->
        <div class="mb-4 flex flex-wrap gap-3">
            <select v-model="machineId" :aria-label="t('production.selectMachine')" class="rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-64">
                <option value="">{{ t('production.selectMachine') }}</option>
                <option v-for="m in machines" :key="m.id" :value="m.id">{{ m.machine_code }} · {{ m.machine_name }}</option>
            </select>
            <input v-model="date" type="date" :aria-label="t('production.receiptDate')" class="rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
        </div>

        <div class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('incoming.tag') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.part') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.model') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('incoming.invoice') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('incoming.supplier') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.receivedBy') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.receivedAt') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="r in rows" :key="r.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-mono font-medium text-ink-primary">{{ r.tag }}</td>
                        <td class="px-4 py-3 text-ink-primary">
                            <div class="font-medium">{{ r.part?.part_number ?? '—' }}</div>
                            <div class="text-xs text-ink-secondary">{{ r.part?.part_name ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="r.part?.model" class="rounded-md bg-background px-1.5 py-0.5 text-xs text-ink-secondary">{{ r.part.model }}</span>
                            <span v-else class="text-ink-secondary">—</span>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">{{ r.invoice ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ r.supplier ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ r.receiver_name ?? '—' }}</td>
                        <td class="px-4 py-3 tabular-nums text-ink-primary">{{ r.received_at ?? '—' }}</td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('production.noReceipt') }}</td>
                    </tr>
                </tbody>
            </table>
            <div class="border-t border-borderline bg-background px-4 py-2 text-right text-xs text-ink-secondary">
                {{ t('incoming.total') }} {{ total }} {{ t('production.receiptItems') }}
            </div>
        </div>
    </AppLayout>
</template>