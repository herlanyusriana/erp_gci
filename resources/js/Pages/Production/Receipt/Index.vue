<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import type { Machine } from '@/types';

const { t, locale } = useI18n();

interface WipRow {
    machine_id: number;
    machine_name: string;
    machine_code: string | null;
    part_id: number;
    part_number: string | null;
    part_name: string | null;
    model: string | null;
    size: string | null;
    part_type: string | null;
    invoice: string | null;
    supplier: string | null;
    uom: string | null;
    beginning_qty: number;
    received_qty: number;
    issued_qty: number;
    ending_qty: number;
}

const props = defineProps<{
    machines: Machine[];
    selectedMachineId: number | null;
    tab: string;
    tabs: string[];
    from: string;
    to: string;
    rows: WipRow[];
    timezone: string;
}>();

const tab = ref(props.tab);
const from = ref(props.from);
const to = ref(props.to);
const machineId = ref(props.selectedMachineId ?? '');

function applyFilters() {
    router.get(route('production-receipts.index'), {
        tab: tab.value,
        from: from.value || undefined,
        to: to.value || undefined,
        machine_id: machineId.value || undefined,
    }, { preserveState: true, replace: true });
}

watch([tab, from, to, machineId], applyFilters);

const fmt = (n: number | null | undefined) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 4 });

const tabLabel = (value: string) =>
    value === 'material' ? t('production.wipTabMaterial') : t('production.wipTabWipFg');
</script>

<template>
    <AppLayout>
        <Head :title="t('production.wipInventory')" />
        <BackButton :href="route('production-data')" class="mb-4" />

        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.wipInventory') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('production.wipInventoryDescription') }}</p>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-borderline bg-surface px-4 py-2 text-sm">
                <span class="font-semibold tabular-nums text-ink-primary">{{ rows.length }}</span>
                <span class="text-ink-secondary">{{ t('production.wipRows') }}</span>
            </div>
        </div>

        <!-- Tab kategori -->
        <div class="mb-4 inline-flex rounded-xl border border-borderline bg-surface p-1">
            <button
                v-for="value in tabs"
                :key="value"
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                :class="tab === value ? 'bg-primary text-white' : 'text-ink-secondary hover:bg-primary-light'"
                @click="tab = value"
            >{{ tabLabel(value) }}</button>
        </div>

        <!-- Filter periode & mesin -->
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-borderline bg-surface p-3">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.periodFrom') }}</label>
                <input v-model="from" type="date" :aria-label="t('production.periodFrom')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.periodTo') }}</label>
                <input v-model="to" type="date" :aria-label="t('production.periodTo')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.machine') }}</label>
                <select v-model="machineId" :aria-label="t('production.selectMachine')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-64">
                    <option value="">{{ t('production.allMachines') }}</option>
                    <option v-for="m in machines" :key="m.id" :value="m.id">{{ m.machine_code }} · {{ m.machine_name }}</option>
                </select>
            </div>
            <p class="text-xs text-ink-secondary sm:ml-auto">{{ timezone }}</p>
        </div>

        <div class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('production.machineName') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.materialInfo') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.invoiceSupplier') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.beginningStock') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.receivedQty') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.issuedQty') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.endingStock') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="r in rows" :key="`${r.machine_id}-${r.part_id}-${r.uom}`" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 align-top">
                            <div class="font-semibold text-ink-primary">{{ r.machine_name }}</div>
                            <div class="text-xs text-ink-secondary">{{ r.machine_code ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3 align-top">
                            <div class="flex items-center gap-1.5 text-ink-primary">
                                <span class="font-medium">{{ r.part_number ?? '—' }}</span>
                                <span
                                    v-if="r.part_type"
                                    class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase"
                                    :class="r.part_type === 'FG' ? 'bg-success/15 text-success' : 'bg-primary/10 text-primary'"
                                >
                                    {{ r.part_type === 'FG' ? t('production.finishedGood') : t('production.wipPart') }}
                                </span>
                            </div>
                            <div class="text-xs text-ink-secondary">{{ r.part_name ?? '' }}</div>
                            <div class="mt-0.5 flex flex-wrap gap-1 text-[11px] text-ink-secondary">
                                <span v-if="r.model" class="rounded bg-background px-1.5 py-0.5">{{ r.model }}</span>
                                <span v-if="r.size" class="rounded bg-background px-1.5 py-0.5">{{ r.size }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 align-top">
                            <div class="text-ink-primary">{{ r.invoice ?? '—' }}</div>
                            <div class="text-xs text-ink-secondary">{{ r.supplier ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-right align-top tabular-nums text-ink-primary">{{ fmt(r.beginning_qty) }}</td>
                        <td class="px-4 py-3 text-right align-top tabular-nums text-success">{{ fmt(r.received_qty) }}</td>
                        <td class="px-4 py-3 text-right align-top tabular-nums text-warning">{{ fmt(r.issued_qty) }}</td>
                        <td class="px-4 py-3 text-right align-top tabular-nums font-semibold" :class="r.ending_qty < 0 ? 'text-danger' : 'text-ink-primary'">
                            {{ fmt(r.ending_qty) }} <span class="text-xs font-normal text-ink-secondary">{{ r.uom ?? '' }}</span>
                        </td>
                        <td class="px-4 py-3 text-right align-top">
                            <div class="flex justify-end">
                                <ActionButton
                                    :href="route('production-receipts.detail', { machine: r.machine_id, part: r.part_id, from, to })"
                                    :label="t('production.detail')"
                                    variant="view"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td colspan="8" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('production.wipNoRows') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>