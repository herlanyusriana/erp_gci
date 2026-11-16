<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDate } from '@/utils/date';

const { t } = useI18n();

interface PickingItem {
    id: number;
    sequence: number;
    qty: number;
    qty_unit: string | null;
    stock_available: number | null;
    stock_shortage: number | null;
    stock_status: string;
    notes: string | null;
    customer?: { customer_code: string; customer_name: string } | null;
    part?: { part_number: string; part_name: string; model: string | null; uom?: { code: string } | null } | null;
}

interface DeliveryRow {
    id: number;
    delivery_no: string;
    status: string;
    customer?: { customer_code: string; customer_name: string } | null;
}

interface PickingList {
    id: number;
    picking_no: string;
    picking_date: string;
    status: string;
    notes: string | null;
    creator?: { name: string } | null;
    items: PickingItem[];
    deliveries: DeliveryRow[];
}

const props = defineProps<{ pickingList: PickingList }>();

const shortItems = computed(() => props.pickingList.items.filter((item) => item.stock_status !== 'ok'));
const hasDeliveries = computed(() => props.pickingList.deliveries.length > 0);
const isDraft = computed(() => props.pickingList.status === 'draft');

const statusLabel = (status: string) =>
    ({
        ok: t('picking.statusOk'),
        short_in_production: t('picking.statusInProduction'),
        short_no_production: t('picking.statusNoProduction'),
        no_data: t('picking.statusNoData'),
    })[status] ?? status;

const statusTone = (status: string) => {
    if (status === 'ok') {
        return 'success';
    }
    if (status === 'no_data') {
        return 'danger';
    }
    return 'warning';
};

function generate() {
    if (!confirm(t('picking.generateConfirm', { no: props.pickingList.picking_no }))) {
        return;
    }

    router.post(route('picking-lists.deliveries.generate', props.pickingList.id), {}, { preserveScroll: true });
}

function destroy() {
    if (!confirm(t('picking.deleteConfirm', { no: props.pickingList.picking_no }))) {
        return;
    }

    router.delete(route('picking-lists.destroy', props.pickingList.id));
}
</script>

<template>
    <Head :title="pickingList.picking_no" />
    <AppLayout>
        <BackButton :href="route('picking-lists.index')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="font-mono text-2xl font-bold tracking-tight text-ink-primary">{{ pickingList.picking_no }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ formatDate(pickingList.picking_date) }}
                    <span v-if="pickingList.creator"> · {{ pickingList.creator.name }}</span>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <StatusBadge :tone="isDraft ? 'neutral' : 'success'" uppercase>
                    {{ t(`picking.status_${pickingList.status}`) }}
                </StatusBadge>
                <ActionButton
                    v-if="isDraft && !hasDeliveries"
                    :href="route('picking-lists.edit', pickingList.id)"
                    :label="t('picking.editTitle', { no: pickingList.picking_no })"
                    variant="edit"
                />
                <button
                    v-if="!hasDeliveries"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    @click="generate"
                >
                    {{ t('picking.generate') }}
                </button>
                <button
                    v-if="!hasDeliveries"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border border-danger px-3 py-2 text-sm font-semibold text-danger transition hover:bg-danger/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger"
                    @click="destroy"
                >
                    {{ t('picking.delete') }}
                </button>
            </div>
        </div>

        <div v-if="shortItems.length > 0" class="mb-4 rounded-xl border border-warning bg-warning/10 p-4" role="status">
            <h2 class="text-sm font-semibold text-warning-ink">{{ t('picking.stockWarningTitle') }}</h2>
            <p class="mt-0.5 text-sm text-warning-ink">{{ t('picking.stockWarningHint') }}</p>
            <ul class="mt-2 space-y-1 text-sm text-warning-ink">
                <li v-for="item in shortItems" :key="item.id">
                    {{ t('picking.seq') }} {{ item.sequence }} · {{ item.part?.part_name ?? '—' }} ·
                    {{ item.customer?.customer_name ?? '—' }}:
                    {{ t('picking.shortage') }} {{ item.stock_shortage }} {{ item.qty_unit ?? '' }}
                    ({{ statusLabel(item.stock_status) }})
                </li>
            </ul>
        </div>

        <div class="mb-6 overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="border-b border-borderline px-4 py-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-secondary">{{ t('picking.dataTable') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('picking.date') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('picking.seq') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('picking.customer') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('picking.fg') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('picking.model') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('picking.partNo') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('picking.qty') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('picking.uom') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('picking.stock') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="item in pickingList.items" :key="item.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3 whitespace-nowrap text-ink-primary">{{ formatDate(pickingList.picking_date) }}</td>
                            <td class="px-4 py-3 tabular-nums font-semibold text-ink-secondary">{{ item.sequence }}</td>
                            <td class="px-4 py-3 text-ink-primary">
                                {{ item.customer?.customer_name ?? '—' }}
                                <div class="text-xs text-ink-secondary">{{ item.customer?.customer_code ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 text-ink-primary">{{ item.part?.part_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-secondary">{{ item.part?.model ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-ink-primary">{{ item.part?.part_number ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold text-ink-primary">{{ item.qty }}</td>
                            <td class="px-4 py-3 text-xs uppercase text-ink-secondary">{{ item.qty_unit ?? item.part?.uom?.code ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <StatusBadge :tone="statusTone(item.stock_status)">{{ statusLabel(item.stock_status) }}</StatusBadge>
                            </td>
                        </tr>
                        <tr v-if="pickingList.items.length === 0">
                            <td colspan="9" class="px-4 py-10 text-center text-sm text-ink-secondary">{{ t('picking.noRows') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl border border-borderline bg-surface">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-borderline px-4 py-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-secondary">{{ t('picking.deliveriesSection') }}</h2>
                    <p class="mt-1 text-xs text-ink-secondary">{{ t('picking.deliveriesHint') }}</p>
                </div>
                <button
                    v-if="hasDeliveries"
                    type="button"
                    disabled
                    class="rounded-md border border-borderline px-3 py-1.5 text-xs font-semibold text-ink-secondary opacity-60"
                >
                    {{ t('picking.generateLocked') }}
                </button>
            </div>

            <ul v-if="hasDeliveries" class="divide-y divide-borderline">
                <li v-for="delivery in pickingList.deliveries" :key="delivery.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                    <div>
                        <span class="font-mono text-sm font-semibold text-ink-primary">{{ delivery.delivery_no }}</span>
                        <span class="ml-2 text-sm text-ink-secondary">{{ delivery.customer?.customer_name ?? '—' }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatusBadge tone="neutral" uppercase>{{ t(`outgoing.status_${delivery.status}`) }}</StatusBadge>
                        <ActionButton :href="route('deliveries.show', delivery.id)" :label="t('picking.openDelivery')" variant="view" />
                    </div>
                </li>
            </ul>
            <p v-else class="px-4 py-8 text-center text-sm text-ink-secondary">{{ t('picking.noDeliveries') }}</p>
        </div>
    </AppLayout>
</template>
