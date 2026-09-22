<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { WorkOrderItem, WorkOrderMachine } from '@/types';

const { t } = useI18n();

interface StockTag {
    tag: string | null;
    qty: number;
    uom: string | null;
    received_at: string | null;
    invoice: string | null;
    supplier: string | null;
}

interface MaterialOption {
    id: number;
    part_number: string;
    part_name: string;
    kind: 'mainMaterial' | 'substitute';
    stock: number;
    tags: StockTag[];
}

interface AllocationRow {
    part_id: number | '';
    qty: number | '';
    showTags?: boolean;
}

const props = defineProps<{
    workOrder: { id: number; wo_no: string };
    item: WorkOrderItem;
    machines: WorkOrderMachine[];
    materialOptions: MaterialOption[];
    allocations: Array<{ part_id: number; qty: number }>;
}>();

const required = computed(() => Number(props.item.qty_required ?? 0));
const unit = computed(() => (props.item.uom_rm ?? '').toUpperCase());

const seedRows = (): AllocationRow[] => {
    if (props.allocations?.length) {
        return props.allocations.map((a) => ({ part_id: a.part_id, qty: a.qty }));
    }
    // Item lama tanpa alokasi: fallback ke main material sebesar kebutuhan.
    const fallback = props.item.selected_part_id ?? props.item.child_part_id ?? '';
    return [{ part_id: fallback, qty: required.value > 0 ? required.value : '' }];
};

const form = useForm({
    allocations: seedRows() as AllocationRow[],
    machine_id: String(props.item.machine_id || ''),
});

const optionById = (id: number | '') => props.materialOptions.find((o) => o.id === Number(id)) ?? null;

const allocatedTotal = computed(() => form.allocations.reduce((sum, r) => sum + (r.qty === '' ? 0 : Number(r.qty)), 0));
const remaining = computed(() => Number((required.value - allocatedTotal.value).toFixed(4)));
const overAllocated = computed(() => allocatedTotal.value > required.value + 1e-9);

function stockFor(row: AllocationRow) {
    return optionById(row.part_id)?.stock ?? 0;
}

function rowExceedsStock(row: AllocationRow) {
    const qty = row.qty === '' ? 0 : Number(row.qty);
    return qty > stockFor(row) + 1e-9;
}

function addRow() {
    const used = new Set(form.allocations.map((r) => Number(r.part_id)));
    const next = optionsWithStock.value.find((o) => !used.has(o.id))
        ?? props.materialOptions.find((o) => !used.has(o.id));
    form.allocations.push({ part_id: next?.id ?? '', qty: '' });
}

function removeRow(index: number) {
    if (form.allocations.length > 1) form.allocations.splice(index, 1);
}

function fillRemaining(row: AllocationRow) {
    if (remaining.value > 0) row.qty = remaining.value;
}

function toggleTags(row: AllocationRow) {
    row.showTags = !row.showTags;
}

/** Semua tag dari seluruh material yang diizinkan (bukan hanya part terpilih). */
const stockTagRows = computed(() => {
    const rows: Array<{ option: MaterialOption; tag: StockTag }> = [];
    for (const option of props.materialOptions) {
        for (const tag of option.tags ?? []) {
            rows.push({ option, tag });
        }
    }

    return rows.sort((a, b) =>
        a.option.part_number.localeCompare(b.option.part_number)
        || String(a.tag.received_at ?? '').localeCompare(String(b.tag.received_at ?? '')),
    );
});

const partsWithStock = computed(() => props.materialOptions.filter((o) => (o.tags ?? []).length > 0));
const substitutesWithoutStock = computed(() => props.materialOptions.filter((o) => o.kind === 'substitute' && (o.tags ?? []).length === 0));

const isMainPart = (row: AllocationRow) => optionById(row.part_id)?.kind === 'mainMaterial';

/** Opsi material dikelompokkan berdasarkan inventory: ada stok dulu (terbesar), lalu tanpa stok. */
const optionsWithStock = computed(() => props.materialOptions
    .filter((o) => Number(o.stock ?? 0) > 0)
    .slice()
    .sort((a, b) => Number(b.stock) - Number(a.stock)));

const optionsWithoutStock = computed(() => props.materialOptions
    .filter((o) => Number(o.stock ?? 0) <= 0)
    .slice()
    .sort((a, b) => a.part_number.localeCompare(b.part_number)));

const optionLabel = (o: MaterialOption) =>
    `${o.part_number} · ${o.part_name} — ${t(`production.${o.kind}`)} · ${Number(o.stock ?? 0).toFixed(4)} ${unit.value}`;

function formatReceivedAt(value: string | null) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString();
}

function submit() {
    form.transform((data) => ({
        ...data,
        allocations: data.allocations
            .filter((r) => r.part_id !== '' && r.qty !== '')
            .map((r) => ({ part_id: Number(r.part_id), qty: Number(r.qty) })),
    }));
    form.patch(route('work-orders.items.update', [props.workOrder.id, props.item.id]));
}
</script>

<template>
    <AppLayout>
        <Head :title="t('production.editRouting')" />
        <div class="mx-auto max-w-5xl">
            <BackButton :href="route('work-orders.show', workOrder.id)" class="mb-4" />

            <div class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.editRouting') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ workOrder.wo_no }} · {{ item.process?.process_name ?? item.child_part_name ?? '—' }}
                </p>
            </div>

            <form @submit.prevent="submit" class="space-y-6 rounded-xl border border-borderline bg-surface p-6">
                <!-- Ringkasan kebutuhan -->
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-borderline bg-background p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.requiredQty') }}</div>
                        <div class="mt-1 text-lg font-semibold tabular-nums text-ink-primary">{{ required }} <span class="text-sm text-ink-secondary">{{ unit }}</span></div>
                    </div>
                    <div class="rounded-lg border border-borderline bg-background p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.allocatedQty') }}</div>
                        <div class="mt-1 text-lg font-semibold tabular-nums" :class="overAllocated ? 'text-danger' : 'text-ink-primary'">{{ allocatedTotal.toFixed(4) }}</div>
                    </div>
                    <div class="rounded-lg border border-borderline bg-background p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.remainingQty') }}</div>
                        <div class="mt-1 text-lg font-semibold tabular-nums" :class="remaining > 0 ? 'text-warning' : 'text-success'">{{ remaining.toFixed(4) }}</div>
                    </div>
                </div>

                <!-- Alokasi material -->
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <label class="block text-sm font-medium text-ink-primary">{{ t('production.materialAllocation') }}</label>
                        <button type="button" @click="addRow" class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-xs font-medium text-primary transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            {{ t('production.addAllocation') }}
                        </button>
                    </div>

                    <div class="space-y-3">
                        <div v-for="(row, index) in form.allocations" :key="index" class="rounded-lg border border-borderline p-3">
                            <div class="grid gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-7">
                                    <label class="text-xs font-semibold text-ink-secondary">{{ t('production.material') }}</label>
                                    <div class="mt-1 flex gap-2">
                                        <select :aria-label="t('production.material')" v-model="row.part_id" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                                            <option value="">{{ t('production.searchMaterial') }}</option>
                                            <optgroup v-if="optionsWithStock.length" :label="t('production.withStock')">
                                                <option v-for="o in optionsWithStock" :key="o.id" :value="o.id">{{ optionLabel(o) }}</option>
                                            </optgroup>
                                            <optgroup v-if="optionsWithoutStock.length" :label="t('production.withoutStock')">
                                                <option v-for="o in optionsWithoutStock" :key="o.id" :value="o.id">{{ optionLabel(o) }}</option>
                                            </optgroup>
                                        </select>
                                        <button
                                            type="button"
                                            :disabled="row.part_id === ''"
                                            :aria-expanded="row.showTags === true"
                                            :title="t('production.showTags')"
                                            class="shrink-0 rounded-lg border border-borderline px-3 py-2 text-ink-secondary transition hover:bg-background disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                                            @click="toggleTags(row)"
                                        >
                                            <svg class="h-4 w-4 transition-transform" :class="row.showTags ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" /></svg>
                                        </button>
                                    </div>
                                </div>
                                <div class="sm:col-span-3">
                                    <label class="text-xs font-semibold text-ink-secondary">{{ t('production.allocQty') }}</label>
                                    <input :aria-label="t('production.allocQty')" v-model="row.qty" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                                </div>
                                <div class="flex items-end gap-2 sm:col-span-2">
                                    <button type="button" @click="fillRemaining(row)" :disabled="remaining <= 0" class="flex-1 rounded-md border border-borderline px-2 py-2 text-xs font-medium text-ink-secondary transition hover:bg-background disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                                        {{ t('production.fillRemaining') }}
                                    </button>
                                    <button type="button" @click="removeRow(index)" :disabled="form.allocations.length <= 1" class="rounded-md border border-borderline px-3 py-2 text-sm text-danger transition hover:bg-danger/10 disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface" :aria-label="t('production.delete')">✕</button>
                                </div>
                            </div>

                            <!-- Stok part terpilih -->
                            <div v-if="row.part_id !== ''" class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <template v-if="isMainPart(row)">
                                    <StatusBadge tone="neutral">{{ t('production.bomReference') }}</StatusBadge>
                                    <span class="text-ink-secondary">{{ t('production.bomReferenceHint') }}</span>
                                </template>
                                <template v-else>
                                    <span class="text-ink-secondary">{{ t('production.stockAvailable') }}:</span>
                                    <StatusBadge :tone="stockFor(row) > 0 ? 'success' : 'neutral'">
                                        {{ stockFor(row).toFixed(4) }} {{ unit }}
                                    </StatusBadge>
                                    <StatusBadge v-if="rowExceedsStock(row)" tone="danger">{{ t('production.exceedsStock') }}</StatusBadge>
                                </template>
                            </div>

                            <!-- Tag stok semua material item -->
                            <div v-if="row.showTags && row.part_id !== ''" class="mt-3 overflow-hidden rounded-lg border border-borderline">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-borderline bg-background px-3 py-2">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.stockTagsTitle') }}</span>
                                    <span class="text-xs text-ink-secondary">{{ t('production.stockTagsSummary', { withStock: partsWithStock.length, total: materialOptions.length }) }}</span>
                                </div>

                                <div v-if="stockTagRows.length" class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-borderline text-xs">
                                        <thead class="bg-surface">
                                            <tr class="text-left font-semibold uppercase tracking-wide text-ink-secondary">
                                                <th scope="col" class="px-3 py-2">{{ t('production.material') }}</th>
                                                <th scope="col" class="px-3 py-2">{{ t('production.tag') }}</th>
                                                <th scope="col" class="px-3 py-2 text-right">{{ t('production.qty') }}</th>
                                                <th scope="col" class="px-3 py-2">{{ t('production.invoice') }}</th>
                                                <th scope="col" class="px-3 py-2">{{ t('production.supplier') }}</th>
                                                <th scope="col" class="px-3 py-2">{{ t('production.receivedAt') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-borderline">
                                            <tr v-for="(r, tagIndex) in stockTagRows" :key="tagIndex" :class="Number(r.option.id) === Number(row.part_id) ? 'bg-primary-light/40' : ''">
                                                <td class="px-3 py-2 font-medium text-ink-primary">{{ r.option.part_number }}</td>
                                                <td class="px-3 py-2 text-ink-primary">{{ r.tag.tag ?? '—' }}</td>
                                                <td class="px-3 py-2 text-right tabular-nums text-ink-primary">{{ r.tag.qty }} {{ r.tag.uom ?? '' }}</td>
                                                <td class="px-3 py-2 text-ink-primary">{{ r.tag.invoice ?? '—' }}</td>
                                                <td class="px-3 py-2 text-ink-primary">{{ r.tag.supplier ?? '—' }}</td>
                                                <td class="px-3 py-2 text-ink-secondary">{{ formatReceivedAt(r.tag.received_at) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <p v-else class="px-3 py-4 text-center text-xs text-ink-secondary">{{ t('production.noTagsAnyPart') }}</p>

                                <p v-if="isMainPart(row)" class="border-t border-borderline px-3 py-2 text-xs text-ink-secondary">{{ t('production.mainPartNoStockHint') }}</p>

                                <div v-if="substitutesWithoutStock.length" class="border-t border-borderline px-3 py-2">
                                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('production.substitutesWithoutStock') }}</div>
                                    <div class="flex flex-wrap gap-1.5">
                                        <span v-for="o in substitutesWithoutStock" :key="o.id" class="rounded-md border border-borderline bg-background px-2 py-0.5 text-[11px] text-ink-secondary">{{ o.part_number }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p v-if="overAllocated" class="mt-2 text-xs font-medium text-danger">{{ t('production.overAllocated') }}</p>
                    <p class="mt-1 text-xs text-ink-secondary">{{ t('production.allocationHelp') }}</p>
                    <div v-if="form.errors.allocations" class="mt-1 text-xs text-danger">{{ form.errors.allocations }}</div>
                </div>

                <!-- Machine -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.machine') }}</label>
                    <select :aria-label="t('production.machine')" v-model="form.machine_id" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option value="">{{ t('production.selectMachine') }}</option>
                        <option v-for="m in machines" :key="m.id" :value="m.id">{{ m.machine_code }} · {{ m.machine_name }}</option>
                    </select>
                    <div v-if="form.errors.machine_id" class="mt-1 text-xs text-danger">{{ form.errors.machine_id }}</div>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-borderline pt-4">
                    <a :href="route('work-orders.show', workOrder.id)" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('production.cancel') }}</a>
                    <button type="submit" :disabled="form.processing || overAllocated" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                        {{ form.processing ? t('production.saving') : t('production.save') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
