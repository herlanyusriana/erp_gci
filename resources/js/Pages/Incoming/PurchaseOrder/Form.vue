<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import type { PurchaseOrder, Supplier, Part } from '@/types';

const { t } = useI18n();

interface ItemRow {
    part_id: number | '';
    qty: number | '';
    unit: string;
    price: number | '';
    notes: string;
}

interface SupplierPart {
    supplier_id: number;
    part_id: number;
    part_number: string | null;
    part_name: string | null;
    uom: string | null;
}

interface ActivePrice {
    supplier_id: number;
    part_id: number;
    price: number;
    currency: string;
}

const props = defineProps<{
    purchaseOrder: PurchaseOrder | null;
    suppliers: Array<Pick<Supplier, 'id' | 'supplier_code' | 'supplier_name'>>;
    parts: Array<Pick<Part, 'id' | 'part_number' | 'part_name'>>;
    uomCodes: string[];
    supplierParts: SupplierPart[];
    activePrices: ActivePrice[];
}>();

const blankItem = (): ItemRow => ({ part_id: '', qty: '', unit: '', price: '', notes: '' });

const rows = ref<ItemRow[]>(
    props.purchaseOrder?.items?.map((i) => ({
        part_id: i.part_id ?? '',
        qty: i.qty,
        unit: i.unit ?? '',
        price: i.price ?? '',
        notes: i.notes ?? '',
    })) ?? [blankItem()],
);

const form = useForm({
    po_no: props.purchaseOrder?.po_no ?? '',
    supplier_id: props.purchaseOrder?.supplier_id ?? ('' as number | ''),
    po_date: props.purchaseOrder?.po_date ?? '',
    expected_date: props.purchaseOrder?.expected_date ?? '',
    notes: props.purchaseOrder?.notes ?? '',
    status: props.purchaseOrder?.status ?? 'draft',
    items: [] as any[],
});

/** Part yang boleh dipilih: hanya part supplier terpilih (strict). */
const partOptions = computed(() => {
    const supplierId = Number(form.supplier_id);
    const map = new Map<number, { id: number; part_number: string | null; part_name: string | null }>();

    if (supplierId) {
        for (const sp of props.supplierParts) {
            if (sp.supplier_id === supplierId && !map.has(sp.part_id)) {
                map.set(sp.part_id, { id: sp.part_id, part_number: sp.part_number, part_name: sp.part_name });
            }
        }
    }

    // Part yang sudah terpilih di baris (mis. data lama) tetap ditampilkan.
    for (const r of rows.value) {
        const id = Number(r.part_id);
        if (id && !map.has(id)) {
            const p = props.parts.find((x) => x.id === id);
            if (p) map.set(id, { id: p.id, part_number: p.part_number, part_name: p.part_name });
        }
    }

    return [...map.values()].sort((a, b) => String(a.part_number).localeCompare(String(b.part_number)));
});

/** Harga aktif (price master) untuk supplier + part baris ini. */
function priceFor(partId: number | '') {
    const supplierId = Number(form.supplier_id);
    const id = Number(partId);
    if (!supplierId || !id) return null;

    return props.activePrices.find((p) => p.supplier_id === supplierId && p.part_id === id) ?? null;
}

/** Ganti supplier → daftar part berubah, jadi baris dikosongkan. */
function onSupplierChange() {
    for (const r of rows.value) {
        r.part_id = '';
        r.price = '';
    }
}

/** Pilih part → isi satuan (bila kosong) & harga dari price master. */
function onPartChange(r: ItemRow) {
    const sp = props.supplierParts.find((s) => s.supplier_id === Number(form.supplier_id) && s.part_id === Number(r.part_id));
    if (sp?.uom && r.unit === '') r.unit = sp.uom;

    const price = priceFor(r.part_id);
    if (price) r.price = price.price;
}

function addRow() {
    rows.value.push(blankItem());
}
function removeRow(i: number) {
    if (rows.value.length > 1) rows.value.splice(i, 1);
}
function submit() {
    form.items = rows.value
        .filter((r) => r.part_id !== '')
        .map((r) => ({
            part_id: Number(r.part_id),
            qty: r.qty === '' ? 0 : Number(r.qty),
            unit: r.unit,
            price: r.price === '' ? null : Number(r.price),
            notes: r.notes,
        })) as any;

    if (props.purchaseOrder) {
        form.put(route('purchase-orders.update', props.purchaseOrder.id));
    } else {
        form.post(route('purchase-orders.store'));
    }
}
</script>

<template>
    <Head :title="purchaseOrder ? t('incoming.editPo', { number: purchaseOrder.po_no }) : t('incoming.newPo')" />
    <AppLayout>
        <BackButton :href="route('purchase-orders.index')" class="mb-4" />

        <h1 class="mb-6 text-2xl font-bold tracking-tight text-ink-primary">
            {{ purchaseOrder ? t('incoming.editPo', { number: purchaseOrder.po_no }) : t('incoming.newPo') }}
        </h1>

        <form @submit.prevent="submit" class="space-y-6">
            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.poNo') }}</label>
                        <input v-model="form.po_no" type="text" placeholder="PO-2026-001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.po_no" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.supplier') }}</label>
                        <select v-model="form.supplier_id" @change="onSupplierChange" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('incoming.chooseSupplier') }}</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.supplier_code }} · {{ s.supplier_name }}</option>
                        </select>
                        <InputError :message="form.errors.supplier_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.status') }}</label>
                        <select v-model="form.status" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="draft">{{ t('incoming.status_draft') }}</option>
                            <option value="confirmed">{{ t('incoming.status_confirmed') }}</option>
                            <option value="cancelled">{{ t('incoming.status_cancelled') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.poDate') }}</label>
                        <input v-model="form.po_date" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.expectedDate') }}</label>
                        <input v-model="form.expected_date" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div class="sm:col-span-2 lg:col-span-1">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.notes') }}</label>
                        <input v-model="form.notes" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-borderline bg-surface p-5">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-ink-primary">{{ t('incoming.items') }}</h2>
                    <button type="button" @click="addRow" class="inline-flex items-center gap-1.5 rounded-md border border-primary px-3 py-1.5 text-sm font-medium text-primary transition hover:bg-primary-light">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        {{ t('incoming.addRow') }}
                    </button>
                </div>
                <InputError :message="form.errors.items" class="mb-3" />

                <div v-for="(r, i) in rows" :key="i" class="grid gap-3 border-t border-borderline py-3 sm:grid-cols-12">
                    <div class="sm:col-span-4">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.part') }}</label>
                        <select v-model="r.part_id" :disabled="!form.supplier_id" @change="onPartChange(r)" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary disabled:bg-background disabled:text-ink-secondary">
                            <option value="">{{ t('incoming.choosePart') }}</option>
                            <option v-for="p in partOptions" :key="p.id" :value="p.id">{{ p.part_number }} · {{ p.part_name }}</option>
                        </select>
                        <p v-if="form.supplier_id && partOptions.length === 0" class="mt-1 text-xs text-warning">{{ t('incoming.supplierNoParts') }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.qty') }}</label>
                        <input v-model="r.qty" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.unit') }}</label>
                        <select v-model="r.unit" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">—</option>
                            <option v-for="code in uomCodes" :key="code" :value="code">{{ code }}</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.price') }}</label>
                        <input v-model="r.price" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <p v-if="priceFor(r.part_id)" class="mt-1 text-xs text-ink-secondary">{{ t('incoming.priceFromMaster', { currency: priceFor(r.part_id)?.currency }) }}</p>
                    </div>
                    <div class="flex items-end sm:col-span-2">
                        <button type="button" @click="removeRow(i)" :disabled="rows.length <= 1" class="rounded-lg border border-borderline px-3 py-2 text-sm text-danger transition hover:bg-danger/10 disabled:opacity-40">{{ t('incoming.delete') }}</button>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <BackButton :href="route('purchase-orders.index')">{{ t('incoming.cancel') }}</BackButton>
                <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-5 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                    {{ form.processing ? t('incoming.saving') : t('incoming.save') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
