<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDate } from '@/utils/date';

const { t, locale } = useI18n();

interface Item {
    id: number;
    tag: string | null;
    qty: number;
    qty_unit: string | null;
    origin_location_code: string | null;
    part?: { id: number; part_number: string; part_name: string } | null;
}

interface Delivery {
    id: number;
    delivery_no: string;
    delivery_date: string;
    status: string;
    truck_no: string | null;
    driver_name: string | null;
    notes: string | null;
    shipped_at: string | null;
    customer?: { customer_code: string; customer_name: string; address: string | null } | null;
    items: Item[];
}

const props = defineProps<{
    delivery: Delivery;
    prepLocation: string | null;
}>();

const fmt = (n: number | null | undefined) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 4 });

const tone = () => (props.delivery.status === 'shipped' ? 'success' : props.delivery.status === 'picked' ? 'warning' : 'neutral');

function pick() {
    if (confirm(t('outgoing.pickConfirm', { no: props.delivery.delivery_no }))) {
        router.post(route('deliveries.pick', props.delivery.id), {}, { preserveScroll: true });
    }
}

function returnToOrigin() {
    if (confirm(t('outgoing.returnConfirm'))) {
        router.post(route('deliveries.return', props.delivery.id), {}, { preserveScroll: true });
    }
}

function ship() {
    if (confirm(t('outgoing.shipConfirm', { no: props.delivery.delivery_no }))) {
        router.post(route('deliveries.ship', props.delivery.id), {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="delivery.delivery_no" />
    <AppLayout>
        <BackButton :href="route('deliveries.index')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ delivery.delivery_no }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ delivery.customer?.customer_name ?? '—' }}
                    <span class="text-xs">({{ delivery.customer?.customer_code ?? '—' }})</span>
                    · {{ formatDate(delivery.delivery_date) }}
                    <StatusBadge :tone="tone()" uppercase class="ml-2">{{ t(`outgoing.status_${delivery.status}`) }}</StatusBadge>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    :href="route('deliveries.print', delivery.id)"
                    target="_blank"
                    rel="noopener"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                >
                    {{ t('outgoing.printSj') }}
                </Link>
                <Link
                    v-if="delivery.status !== 'shipped'"
                    :href="route('deliveries.edit', delivery.id)"
                    class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background"
                >
                    {{ t('outgoing.edit') }}
                </Link>
                <button
                    v-if="delivery.status === 'draft'"
                    type="button"
                    @click="pick"
                    class="rounded-md border border-primary px-4 py-2 text-sm font-semibold text-primary transition hover:bg-primary-light"
                >
                    {{ t('outgoing.pick') }}
                </button>
                <button
                    v-if="delivery.status === 'picked'"
                    type="button"
                    @click="returnToOrigin"
                    class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background"
                >
                    {{ t('outgoing.returnToOrigin') }}
                </button>
                <button
                    v-if="delivery.status !== 'shipped'"
                    type="button"
                    @click="ship"
                    class="rounded-md border border-success px-4 py-2 text-sm font-semibold text-success transition hover:bg-success/10"
                >
                    {{ t('outgoing.markShipped') }}
                </button>
            </div>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.customer') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ delivery.customer?.customer_name ?? '—' }}</div>
                <div class="text-xs text-ink-secondary">{{ delivery.customer?.address ?? '' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.truckNo') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ delivery.truck_no ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.driverName') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ delivery.driver_name ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.prepLocation') }}</div>
                <div class="mt-1 font-mono font-semibold text-ink-primary">{{ prepLocation ?? '—' }}</div>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.part') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.tag') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('outgoing.qty') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.origin') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="item in delivery.items" :key="item.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3">
                                <div class="font-medium text-ink-primary">{{ item.part?.part_number ?? '—' }}</div>
                                <div class="text-xs text-ink-secondary">{{ item.part?.part_name ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 font-mono text-ink-primary">{{ item.tag ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold text-ink-primary">
                                {{ fmt(item.qty) }} <span class="text-xs font-normal text-ink-secondary">{{ item.qty_unit ?? '' }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-ink-secondary">{{ item.origin_location_code ?? '—' }}</td>
                        </tr>
                        <tr v-if="delivery.items.length === 0">
                            <td colspan="4" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('outgoing.noItems') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p v-if="delivery.notes" class="mt-4 text-sm text-ink-secondary">{{ t('outgoing.notes') }}: {{ delivery.notes }}</p>
    </AppLayout>
</template>