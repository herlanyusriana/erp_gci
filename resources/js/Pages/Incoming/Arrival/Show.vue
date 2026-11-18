<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { formatDate, formatDateTime } from '@/utils/date';
import type { IncomingArrival, IncomingArrivalContainer } from '@/types';

const { t } = useI18n();

interface PendingRow {
    item: IncomingArrival['items'] extends (infer T)[] | undefined ? T : never;
    received: number;
    remaining: number;
}

const props = defineProps<{
    arrival: IncomingArrival;
    pending: PendingRow[];
    weightUnit: string;
    can: { inspect: boolean };
}>();

const showInspect = ref(false);
const inspecting = ref<IncomingArrivalContainer | null>(null);

const inspectForm = useForm({
    status: 'ok',
    seal_condition: '',
    container_condition: '',
    driver_name: '',
    notes: '',
});

function openInspect(container: IncomingArrivalContainer) {
    inspecting.value = container;
    const inspection = container.inspection;
    inspectForm.clearErrors();
    inspectForm.status = inspection?.status ?? 'ok';
    inspectForm.seal_condition = inspection?.seal_condition ?? '';
    inspectForm.container_condition = inspection?.container_condition ?? '';
    inspectForm.driver_name = inspection?.driver_name ?? '';
    inspectForm.notes = inspection?.notes ?? '';
    showInspect.value = true;
}

function closeInspect() {
    showInspect.value = false;
    inspecting.value = null;
}

function submitInspect() {
    if (!inspecting.value) {
        return;
    }

    inspectForm.post(route('incoming-containers.inspect', inspecting.value.id), {
        preserveScroll: true,
        onSuccess: () => closeInspect(),
    });
}

/**
 * Kolom "Kelompok / Ukuran" hanya boleh berisi kelompok material.
 * Data lama sempat menyimpan part number di kolom ini; bila isinya sama
 * dengan part number, tampilkan tanda hubung agar tidak menyesatkan.
 */
function groupLabel(item: PendingRow['item']): string {
    const group = (item.material_group ?? '').trim();
    const partNumber = (item.part?.part_number ?? '').trim();

    if (group === '') {
        return '—';
    }

    return partNumber !== '' && group.toUpperCase() === partNumber.toUpperCase() ? '—' : group;
}
</script>

<template>
    <Head :title="t('incoming.arrivalTitle', { number: arrival.arrival_no })" />
    <AppLayout>
        <BackButton :href="route('incoming-arrivals.index')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ arrival.arrival_no }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ arrival.supplier?.supplier_name ?? '—' }}
                    <span v-if="arrival.trucking">· {{ t('incoming.truckingValue', { name: arrival.trucking.company_name }) }}</span>
                    · {{ t('incoming.invoiceValue', { number: arrival.invoice_no ?? '—' }) }}
                    · <StatusBadge uppercase :status="arrival.status">{{ t('incoming.status_' + arrival.status) }}</StatusBadge>
                    <span v-if="arrival.transaction_no" class="ml-2 rounded-md bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">{{ t('incoming.salesOrder', { number: arrival.transaction_no }) }}</span>
                </p>
            </div>
            <div class="flex gap-2">
                <a :href="route('incoming-arrivals.invoice', arrival.id)" target="_blank" rel="noopener" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('incoming.printInvoice') }}</a>
                <a :href="route('incoming-arrivals.export', arrival.id)" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('incoming.exportExcel') }}</a>
                <a :href="route('incoming-arrivals.pdf', arrival.id)" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('incoming.invoicePdf') }}</a>
                <Link :href="route('incoming-arrivals.edit', arrival.id)" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('incoming.edit') }}</Link>
                <Link :href="route('receive.index')" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('incoming.receive') }}</Link>
            </div>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.vessel') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrival.vessel ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.etaGci') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ formatDate(arrival.eta_gci ?? arrival.eta) }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.billOfLading') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrival.bill_of_lading ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.portLoading') }}</div>
                <div class="mt-1 text-ink-primary">{{ arrival.port_of_loading ?? '—' }}</div>
            </div>
        </div>

        <!-- Items -->
        <div v-if="arrival.remarks" class="mb-6 rounded-xl border border-borderline bg-surface p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('incoming.remarks') }}</div>
            <div class="mt-1 whitespace-pre-line text-ink-primary">{{ arrival.remarks }}</div>
        </div>

        <h2 class="mb-3 text-base font-semibold text-ink-primary">{{ t('incoming.items') }}</h2>
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('incoming.part') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.model') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('incoming.groupSize') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('incoming.bundleQty') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.qtyGoods') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.netWeight') }} ({{ weightUnit }})</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.receivedUnit', { unit: weightUnit }) }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.remainingUnit', { unit: weightUnit }) }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="p in pending" :key="p.item.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3 font-medium text-ink-primary">
                                <span class="font-mono font-bold">{{ p.item.part?.part_number ?? '—' }}</span>
                                <div class="text-xs text-ink-secondary">{{ p.item.part?.part_name ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 text-ink-primary">{{ p.item.part?.model || '—' }}</td>
                            <td class="px-4 py-3 text-ink-primary">
                                {{ groupLabel(p.item) }} · {{ p.item.size ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-ink-primary">
                                <span v-if="p.item.qty_bundle" class="font-medium">{{ p.item.qty_bundle }} {{ p.item.unit_bundle ?? '' }}</span>
                                <span v-else class="text-ink-secondary">—</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-primary">
                                {{ p.item.qty_goods }} <span class="text-xs text-ink-secondary">{{ p.item.unit_goods ?? '' }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-primary">
                                {{ p.item.weight_nett != null ? p.item.weight_nett : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ p.received }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold" :class="p.remaining > 0 ? 'text-warning' : 'text-success'">{{ p.remaining }}</td>
                        </tr>
                        <tr v-if="!pending.length">
                            <td colspan="8" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noItems') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Containers -->
        <h2 class="mb-3 mt-8 text-base font-semibold text-ink-primary">{{ t('incoming.containers') }}</h2>
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
                        <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('incoming.containerNo') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('incoming.seal') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('incoming.size') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('incoming.inspection') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('incoming.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="c in arrival.containers" :key="c.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3 font-medium text-ink-primary">{{ c.container_no }}</td>
                            <td class="px-4 py-3 text-ink-primary">{{ c.seal_code ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-primary">{{ c.size ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <StatusBadge v-if="c.inspection" :tone="c.inspection.status === 'ok' ? 'success' : 'danger'" uppercase>{{ t('incoming.status_' + c.inspection.status) }}</StatusBadge>
                                <StatusBadge v-else tone="warning">{{ t('incoming.notInspected') }}</StatusBadge>
                                <div v-if="c.inspection?.inspected_at" class="mt-1 text-xs text-ink-secondary">{{ formatDateTime(c.inspection.inspected_at) }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end">
                                    <ActionButton v-if="can.inspect" :label="t('incoming.inspect')" variant="view" @click="openInspect(c)" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!arrival.containers?.length">
                            <td colspan="5" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noContainers') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Modal :show="showInspect" max-width="md" @close="closeInspect">
            <form @submit.prevent="submitInspect" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ t('incoming.inspectTitle', { number: inspecting?.container_no ?? '' }) }}</h2>
                <div class="grid gap-4">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.status') }}</label>
                        <select v-model="inspectForm.status" :aria-label="t('incoming.status')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="ok">{{ t('incoming.status_ok') }}</option>
                            <option value="damage">{{ t('incoming.status_damage') }}</option>
                        </select>
                        <InputError :message="inspectForm.errors.status" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.sealCondition') }}</label>
                        <select v-model="inspectForm.seal_condition" :aria-label="t('incoming.sealCondition')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('incoming.choose') }}</option>
                            <option value="ok">{{ t('incoming.sealCondition_ok') }}</option>
                            <option value="broken">{{ t('incoming.sealCondition_broken') }}</option>
                            <option value="missing">{{ t('incoming.sealCondition_missing') }}</option>
                        </select>
                        <InputError :message="inspectForm.errors.seal_condition" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.containerCondition') }}</label>
                        <input v-model="inspectForm.container_condition" type="text" :aria-label="t('incoming.containerCondition')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="inspectForm.errors.container_condition" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.driverName') }}</label>
                        <input v-model="inspectForm.driver_name" type="text" :aria-label="t('incoming.driverName')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="inspectForm.errors.driver_name" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.notes') }}</label>
                        <textarea v-model="inspectForm.notes" rows="3" :aria-label="t('incoming.notes')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"></textarea>
                        <InputError :message="inspectForm.errors.notes" class="mt-1" />
                    </div>
                </div>
                <div class="mt-6 flex items-center justify-end gap-3">
                    <button type="button" @click="closeInspect" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('incoming.cancel') }}</button>
                    <button type="submit" :disabled="inspectForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                        {{ inspectForm.processing ? t('incoming.saving') : t('incoming.save') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>