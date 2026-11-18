<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { hasAnyPermission } from '@/utils/permissions';

const { t } = useI18n();

interface PartPriceRow {
    id: number;
    part_number: string;
    part_name: string;
    model: string | null;
    uom: string | null;
    price: number | null;
    currency: string;
    is_active: boolean;
}

interface CustomerOption {
    id: number;
    customer_code: string;
    customer_name: string;
}

const props = defineProps<{
    parts: PartPriceRow[];
    customers: CustomerOption[];
    filters: { customer_id: number | null; search: string | null };
    currencies: string[];
    filledCount: number;
}>();

const canEdit = computed(() => hasAnyPermission(['customer_price.create', 'customer_price.update']));

const customerId = ref<number | ''>(props.filters.customer_id ?? '');
const search = ref(props.filters.search ?? '');

interface PriceRow {
    part_id: number;
    price: number | null;
    currency: string;
    is_active: boolean;
}

function toPriceRow(part: PartPriceRow): PriceRow {
    return {
        part_id: part.id,
        price: part.price,
        currency: part.currency,
        is_active: part.is_active,
    };
}

const lastSeededCustomer = ref<number>(Number(props.filters.customer_id ?? 0));

const form = useForm({
    customer_id: props.filters.customer_id ?? 0,
    prices: props.parts.map(toPriceRow),
});

watch(
    () => props.parts,
    (parts) => {
        const currentCustomer = Number(props.filters.customer_id ?? 0);

        if (currentCustomer !== lastSeededCustomer.value) {
            lastSeededCustomer.value = currentCustomer;
            form.prices = parts.map(toPriceRow);

            return;
        }

        const existing = new Map<number, PriceRow>(form.prices.map((row): [number, PriceRow] => [row.part_id, row]));
        const merged = parts.map((part) => existing.get(part.id) ?? toPriceRow(part));
        const visibleIds = new Set(parts.map((part) => part.id));
        const hidden = form.prices.filter((row) => !visibleIds.has(row.part_id));

        form.prices = [...merged, ...hidden];
    },
);

const visibleRows = computed(() => {
    const rowsByPart = new Map<number, PriceRow>(form.prices.map((row): [number, PriceRow] => [row.part_id, row]));

    return props.parts.flatMap((part) => {
        const row = rowsByPart.get(part.id);

        return row ? [{ part, row }] : [];
    });
});

const customerOptions = computed(() =>
    props.customers.map((customer) => ({
        value: customer.id,
        label: customer.customer_name,
        sublabel: customer.customer_code,
    })),
);

let timer: ReturnType<typeof setTimeout> | undefined;
function reload() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('customer-prices.index'),
            {
                customer_id: customerId.value || undefined,
                search: search.value || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 250);
}

const filledOnPage = computed(() => form.prices.filter((row) => row.price !== null && row.price !== undefined).length);

function submit() {
    if (!canEdit.value || !customerId.value) {
        return;
    }

    form.customer_id = Number(customerId.value);
    form.post(route('customer-prices.store'), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('customerPrice.title')" />
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('customerPrice.title') }}</h1>
            <p class="mt-1 text-sm text-ink-secondary">{{ t('customerPrice.subtitle') }}</p>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-borderline bg-surface p-3">
            <div class="w-full sm:w-72">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('customerPrice.customer') }}</label>
                <SearchableSelect
                    v-model="customerId"
                    :options="customerOptions"
                    :placeholder="t('customerPrice.chooseCustomer')"
                    :aria-label="t('customerPrice.customer')"
                    @change="reload"
                />
            </div>
            <div class="w-full sm:w-72">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('customerPrice.search') }}</label>
                <input
                    v-model="search"
                    type="search"
                    :aria-label="t('customerPrice.search')"
                    class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                    @input="reload"
                />
            </div>
            <div class="ml-auto text-xs text-ink-secondary">
                {{ t('customerPrice.filled', { count: props.filledCount }) }}
            </div>
        </div>

        <div class="mb-4 rounded-lg bg-background px-4 py-3 text-xs text-ink-secondary">
            {{ t('customerPrice.priceHint') }}
        </div>

        <div v-if="!customerId" class="rounded-xl border border-dashed border-borderline px-4 py-12 text-center text-sm text-ink-secondary">
            {{ t('customerPrice.emptyCustomer') }}
        </div>

        <form v-else @submit.prevent="submit">
            <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-borderline text-sm">
                        <thead class="bg-background">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                                <th scope="col" class="px-4 py-3">{{ t('customerPrice.partNo') }}</th>
                                <th scope="col" class="px-4 py-3">{{ t('customerPrice.fgName') }}</th>
                                <th scope="col" class="px-4 py-3">{{ t('customerPrice.model') }}</th>
                                <th scope="col" class="px-4 py-3">{{ t('customerPrice.uom') }}</th>
                                <th scope="col" class="w-40 px-4 py-3 text-right">{{ t('customerPrice.price') }}</th>
                                <th scope="col" class="w-32 px-4 py-3">{{ t('customerPrice.currency') }}</th>
                                <th scope="col" class="w-20 px-4 py-3 text-center">{{ t('customerPrice.active') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-borderline">
                            <tr v-for="{ part, row } in visibleRows" :key="row.part_id" class="hover:bg-primary-light/40">
                                <td class="px-4 py-2 font-mono text-xs text-ink-primary">{{ part.part_number }}</td>
                                <td class="px-4 py-2 text-ink-primary">{{ part.part_name }}</td>
                                <td class="px-4 py-2 text-ink-secondary">{{ part.model ?? '—' }}</td>
                                <td class="px-4 py-2 text-xs uppercase text-ink-secondary">{{ part.uom ?? '—' }}</td>
                                <td class="px-4 py-2">
                                    <input
                                        v-model.number="row.price"
                                        type="number"
                                        min="0"
                                        step="0.0001"
                                        :disabled="!canEdit"
                                        :aria-label="`${t('customerPrice.price')} ${part.part_number}`"
                                        class="w-full rounded-lg border-borderline bg-background px-2 py-1.5 text-right text-sm tabular-nums text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-60"
                                    />
                                </td>
                                <td class="px-4 py-2">
                                    <select
                                        v-model="row.currency"
                                        :disabled="!canEdit"
                                        :aria-label="`${t('customerPrice.currency')} ${part.part_number}`"
                                        class="w-full rounded-lg border-borderline bg-background px-2 py-1.5 text-sm text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-60"
                                    >
                                        <option v-for="currency in currencies" :key="currency" :value="currency">{{ currency }}</option>
                                    </select>
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <input
                                        v-model="row.is_active"
                                        type="checkbox"
                                        :disabled="!canEdit"
                                        :aria-label="`${t('customerPrice.active')} ${part.part_number}`"
                                        class="h-4 w-4 rounded border-borderline text-primary focus:ring-primary disabled:opacity-50"
                                    />
                                </td>
                            </tr>
                            <tr v-if="parts.length === 0">
                                <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('customerPrice.empty') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-ink-secondary">{{ t('customerPrice.hint') }}</p>
                <div class="flex items-center gap-3">
                    <span v-if="!canEdit" class="text-xs font-medium text-warning-ink">{{ t('customerPrice.noPermission') }}</span>
                    <button
                        type="submit"
                        :disabled="!canEdit || form.processing"
                        class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface disabled:opacity-60"
                    >
                        {{ form.processing ? t('customerPrice.saving') : t('customerPrice.save') }}
                        <span class="text-xs font-normal opacity-80">({{ filledOnPage }})</span>
                    </button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
