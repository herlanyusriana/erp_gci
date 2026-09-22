<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref, computed, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import type { PartPrice, Part, Supplier, Paginated, PageProps } from '@/types';

const { t, locale } = useI18n();

const page = usePage<PageProps>();

const props = defineProps<{
    prices: Paginated<PartPrice>;
    filters: { search?: string; supplier_id?: string };
    suppliers: Array<Pick<Supplier, 'id' | 'supplier_code' | 'supplier_name'>>;
    parts: Array<Pick<Part, 'id' | 'part_number' | 'part_name'>>;
}>();

const search = ref(props.filters.search ?? '');
const supplierId = ref(props.filters.supplier_id ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;
watch([search, supplierId], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('prices.index'), {
            search: search.value || undefined,
            supplier_id: supplierId.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
});

const perms = computed(() => {
    const list: string[] = page.props.auth.permissions ?? [];
    const has = (p: string) => list.some((x) => [p, 'master.' + p].includes(x));
    return {
        create: has('purchase_order.create') || has('purchase_order.update'),
        update: has('purchase_order.update'),
        delete: has('purchase_order.delete') || has('purchase_order.update'),
    };
});

const today = () => new Date().toISOString().slice(0, 10);

const showForm = ref(false);
const editing = ref<PartPrice | null>(null);
const form = useForm({
    supplier_id: '',
    part_id: '',
    price: '' as number | '',
    currency: 'IDR',
    valid_from: today(),
    is_active: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.currency = 'IDR';
    form.valid_from = today();
    showForm.value = true;
}
function openEdit(p: PartPrice) {
    editing.value = p;
    form.clearErrors();
    form.supplier_id = p.supplier_id != null ? String(p.supplier_id) : '';
    form.part_id = p.part_id != null ? String(p.part_id) : '';
    form.price = p.price ?? '';
    form.currency = p.currency ?? 'IDR';
    form.valid_from = p.valid_from ? String(p.valid_from).slice(0, 10) : today();
    form.is_active = p.is_active;
    showForm.value = true;
}
function closeForm() {
    showForm.value = false;
    editing.value = null;
}
function submit() {
    if (editing.value) form.put(route('prices.update', editing.value.id), { onSuccess: closeForm });
    else form.post(route('prices.store'), { onSuccess: closeForm });
}
function remove(p: PartPrice) {
    if (confirm(t('master.deletePrice'))) router.delete(route('prices.destroy', p.id));
}

const fmtMoney = (n: number | null | undefined, currency: string | null | undefined) =>
    n == null ? '—' : `${Number(n).toLocaleString(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency ?? ''}`.trim();

const fmtDate = (value: string | null | undefined) => {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(locale.value, { day: '2-digit', month: 'short', year: 'numeric' });
};
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.priceMaster') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('master.priceHelp') }}</p>
            </div>
            <button v-if="perms.create" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('master.add') }}
            </button>
        </div>

        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <input v-model="search" type="search" :placeholder="t('master.priceSearch')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />
            <select v-model="supplierId" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-64">
                <option value="">{{ t('master.allSuppliers') }}</option>
                <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">{{ s.supplier_name }}</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">{{ t('master.supplier') }}</th>
                        <th class="px-4 py-3">{{ t('master.part') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('master.price') }}</th>
                        <th class="px-4 py-3">{{ t('master.validFrom') }}</th>
                        <th class="px-4 py-3">{{ t('master.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('master.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="p in prices.data" :key="p.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3">
                            <div class="font-medium text-ink-primary">{{ p.supplier?.supplier_name ?? '—' }}</div>
                            <div class="text-xs text-ink-secondary">{{ p.supplier?.supplier_code ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-ink-primary">{{ p.part?.part_number ?? '—' }}</div>
                            <div class="text-xs text-ink-secondary">{{ p.part?.part_name ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-ink-primary">{{ fmtMoney(p.price, p.currency) }}</td>
                        <td class="px-4 py-3 text-ink-secondary">{{ fmtDate(p.valid_from) }}</td>
                        <td class="px-4 py-3">
                            <span :class="p.is_active ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'" class="rounded-md px-2 py-0.5 text-xs font-semibold">{{ p.is_active ? t('master.active') : t('master.inactive') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton v-if="perms.update" :label="t('master.edit')" variant="edit" @click="openEdit(p)" />
                                <ActionButton v-if="perms.delete" :label="t('master.delete')" variant="delete" @click="remove(p)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="prices.data.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.priceEmpty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="prices.links" />

        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? t('master.editPrice') : t('master.addPrice') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.supplier') }}</label>
                        <select v-model="form.supplier_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">—</option>
                            <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">{{ s.supplier_name }}</option>
                        </select>
                        <InputError :message="form.errors.supplier_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.part') }}</label>
                        <select v-model="form.part_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('master.selectPart') }}</option>
                            <option v-for="p in parts" :key="p.id" :value="String(p.id)">{{ p.part_number }} · {{ p.part_name }}</option>
                        </select>
                        <InputError :message="form.errors.part_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.price') }}</label>
                        <input v-model="form.price" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.price" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.currency') }}</label>
                        <input v-model="form.currency" type="text" maxlength="10" placeholder="IDR" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.currency" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.validFrom') }}</label>
                        <input v-model="form.valid_from" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.valid_from" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.status') }}</label>
                        <select v-model="form.is_active" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option :value="true">{{ t('master.active') }}</option>
                            <option :value="false">{{ t('master.inactive') }}</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background">{{ t('master.cancel') }}</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">{{ form.processing ? t('master.saving') : t('master.save') }}</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
