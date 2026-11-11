<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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

interface WipStockRow {
    invoices: Array<{ invoice: string; supplier: string | null }>;
    part_id: number;
    part_number: string | null;
    part_name: string | null;
    model: string | null;
    size: string | null;
    uom: string | null;
    qty: number;
    tag_count: number;
    oldest_received_at: string | null;
}

const props = defineProps<{
    machines: Machine[];
    selectedMachineId: number | null;
    date: string;
    rows: WipRow[];
    stockRows: WipStockRow[];
    timezone: string;
}>();

const tab = ref<'stock' | 'movement'>('stock');

const machineId = ref(props.selectedMachineId ?? '');
const date = ref(props.date);

function applyFilters() {
    router.get(route('production-receipts.index'), {
        machine_id: machineId.value || undefined,
        date: date.value || undefined,
    }, { preserveState: true, replace: true });
}

watch([machineId, date], applyFilters);

const total = computed(() => props.rows.length);

/**
 * Kartu stok dipisah sesuai isi WIP Inventory:
 * barang setengah jadi (output mesin sebelumnya) dan raw material murni.
 */
const movementGroups = computed(() => {
    const byType = (code: string) => props.rows.filter((r) => (r.part_type ?? '').toUpperCase() === code);
    const known = new Set(['WIP', 'MATERIAL']);

    return [
        {
            key: 'WIP',
            label: t('production.wipGroupWip'),
            hint: t('production.wipGroupWipHint'),
            rows: byType('WIP'),
        },
        {
            key: 'MATERIAL',
            label: t('production.wipGroupMaterial'),
            hint: t('production.wipGroupMaterialHint'),
            rows: byType('MATERIAL'),
        },
        {
            key: 'OTHER',
            label: t('production.wipGroupOther'),
            hint: t('production.wipGroupOtherHint'),
            rows: props.rows.filter((r) => !known.has((r.part_type ?? '').toUpperCase())),
        },
    ].filter((group) => group.rows.length > 0);
});

const fmt = (n: number | null | undefined) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 4 });

/** Umur WIP dihitung dari tag tertua yang masih tersisa. */
function age(iso: string | null): string {
    if (!iso) return '—';
    const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000);
    if (Number.isNaN(days) || days < 0) return '—';
    if (days === 0) return '≤1d';
    return `${days}d`;
}
</script>

<template>
    <AppLayout>
        <Head :title="t('production.wipInventory')" />
        <BackButton :href="route('production-data')" class="mb-4" />

        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.wipInventory') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ tab === 'stock' ? t('production.wipStockDescription') : t('production.wipInventoryDescription') }}
                </p>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-borderline bg-surface px-4 py-2 text-sm">
                <span class="font-semibold tabular-nums text-ink-primary">{{ tab === 'stock' ? stockRows.length : total }}</span>
                <span class="text-ink-secondary">{{ tab === 'stock' ? t('production.wipTotalParts') : t('production.wipRows') }}</span>
            </div>
        </div>

        <!-- Tab: stok WIP fisik vs pergerakan material -->
        <div class="mb-4 inline-flex rounded-xl border border-borderline bg-surface p-1">
            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                :class="tab === 'stock' ? 'bg-primary text-white' : 'text-ink-secondary hover:bg-primary-light'"
                @click="tab = 'stock'"
            >{{ t('production.wipStockTab') }}</button>
            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                :class="tab === 'movement' ? 'bg-primary text-white' : 'text-ink-secondary hover:bg-primary-light'"
                @click="tab = 'movement'"
            >{{ t('production.wipMovementTab') }}</button>
        </div>

        <!-- TAB 1: stok WIP fisik (sama dengan Papan WIP di APK) -->
        <div v-if="tab === 'stock'" class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('production.wipPart') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.materialInfo') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('production.invoiceSupplier') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.wipQty') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.wipTagCount') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('production.wipOldest') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="r in stockRows" :key="r.part_id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 align-top">
                            <div class="font-semibold text-ink-primary">{{ r.part_number ?? '—' }}</div>
                            <div class="text-xs text-ink-secondary">{{ r.part_name ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3 align-top">
                            <div class="flex flex-wrap gap-1 text-[11px] text-ink-secondary">
                                <span v-if="r.model" class="rounded bg-background px-1.5 py-0.5">{{ r.model }}</span>
                                <span v-if="r.size" class="rounded bg-background px-1.5 py-0.5">{{ r.size }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 align-top">
                            <div v-if="r.invoices.length === 0" class="text-ink-secondary">—</div>
                            <template v-else>
                                <div v-for="inv in r.invoices" :key="inv.invoice" class="leading-tight">
                                    <div class="font-medium text-ink-primary">{{ inv.invoice }}</div>
                                    <div class="text-xs text-ink-secondary">{{ inv.supplier ?? '—' }}</div>
                                </div>
                            </template>
                        </td>
                        <td class="px-4 py-3 text-right align-top tabular-nums text-lg font-bold text-success">
                            {{ fmt(r.qty) }} <span class="text-xs font-normal text-ink-secondary">{{ r.uom ?? '' }}</span>
                        </td>
                        <td class="px-4 py-3 text-right align-top tabular-nums text-ink-primary">{{ r.tag_count }}</td>
                        <td class="px-4 py-3 text-right align-top tabular-nums text-ink-secondary">{{ age(r.oldest_received_at) }}</td>
                    </tr>
                    <tr v-if="stockRows.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('production.wipNoStock') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- TAB 2: pergerakan material per mesin -->
        <template v-else>
            <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-borderline bg-surface p-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.receiptDate') }}</label>
                    <input v-model="date" type="date" :aria-label="t('production.receiptDate')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
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

            <div v-if="rows.length === 0" class="rounded-xl border border-borderline bg-surface px-4 py-12 text-center text-sm text-ink-secondary">
                {{ t('production.noReceipt') }}
            </div>

            <div v-else class="space-y-6">
                <section v-for="group in movementGroups" :key="group.key">
                    <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <h2 class="text-sm font-bold text-ink-primary">{{ group.label }}</h2>
                            <span class="rounded-full bg-background px-2 py-0.5 text-xs font-semibold text-ink-secondary">
                                {{ group.rows.length }} {{ t('production.wipRows') }}
                            </span>
                        </div>
                        <p class="text-xs text-ink-secondary">{{ group.hint }}</p>
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
                                <tr v-for="r in group.rows" :key="`${group.key}-${r.machine_id}-${r.part_id}-${r.uom}`" class="hover:bg-primary-light/40">
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-semibold text-ink-primary">{{ r.machine_name }}</div>
                                        <div class="text-xs text-ink-secondary">{{ r.machine_code ?? '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-medium text-ink-primary">{{ r.part_number ?? '—' }}</div>
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
                                                :href="route('production-receipts.detail', { machine: r.machine_id, part: r.part_id, date })"
                                                :label="t('production.detail')"
                                                variant="view"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </template>
    </AppLayout>
</template>
