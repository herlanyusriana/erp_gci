<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import type { IncomingReceive, IncomingArrivalItem } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    receive: IncomingReceive;
    arrivalItem: IncomingArrivalItem;
    weightBasis: boolean;
    isLocal: boolean;
    uomCodes: string[];
    packingUnits: string[];
    packingTypes: string[];
    weightUnit: string;
    locations: Array<{ code: string; name: string }>;
}>();

const backHref = () => route('receive.show', props.arrivalItem.arrival_id);

const packingTypeOptions = () =>
    props.packingTypes?.length ? props.packingTypes : props.packingUnits;

const form = useForm({
    receive_date: props.receive.ata_date?.slice(0, 10) ?? '',
    tag: props.receive.tag ?? '',
    truck_no: props.receive.truck_no ?? '',
    location_code: props.receive.location_code ?? '',
    bundle_count: (props.receive as any).bundle_count ?? props.receive.bundle_qty ?? 1,
    bundle_qty: (props.receive as any).bundle_count ?? props.receive.bundle_qty ?? 1,
    bundle_unit: props.receive.bundle_unit ?? props.packingUnits[0] ?? 'PALLET',
    packing_count: (props.receive as any).packing_count ?? 1,
    packing_type: (props.receive as any).packing_type ?? 'BOX',
    packing_qty: (props.receive as any).packing_qty ?? '',
    qty: props.receive.qty,
    net_weight: props.receive.net_weight ?? '',
    gross_weight: props.receive.gross_weight ?? '',
});

function calculateTotal() {
    const bCount = Number(form.bundle_count) || 0;
    const pCount = Number(form.packing_count) || 0;
    const pQty = Number(form.packing_qty) || 0;

    if (bCount > 0 && pCount > 0 && pQty > 0) {
        const total = Math.round(bCount * pCount * pQty * 10000) / 10000;
        form.qty = total;
        if (props.weightBasis && !form.net_weight) {
            form.net_weight = total;
        }
    }
}

function submit() {
    form.bundle_qty = form.bundle_count;
    form.put(route('receive.update', props.receive.id));
}
</script>

<template>
    <Head :title="t('incoming.editReceive', { number: receive.id })" />
    <AppLayout>
        <BackButton :href="backHref()" class="mb-4" />

        <h1 class="mb-4 text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.editReceive', { number: receive.id }) }}</h1>
        <p class="mb-6 flex flex-wrap items-center justify-between gap-3 text-sm text-ink-secondary">
            <span>{{ arrivalItem.part?.part_number ?? '—' }} · {{ receive.tag }}</span>
            <a :href="route('receive.label', receive.id)" target="_blank" rel="noopener" class="rounded-md bg-primary px-4 py-2 text-xs font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('incoming.printLabel') }}</a>
        </p>

        <form @submit.prevent="submit" class="max-w-2xl space-y-4 rounded-xl border border-borderline bg-surface p-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.receiveDate') }}</label>
                    <input :aria-label="t('incoming.receiveDate')" v-model="form.receive_date" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <InputError :message="form.errors.receive_date" class="mt-1" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.tag') }}</label>
                    <input :aria-label="t('incoming.tag')" v-model="form.tag" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary font-mono focus:border-primary focus:ring-primary" />
                    <InputError :message="form.errors.tag" class="mt-1" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.truckNo') }}</label>
                    <input :aria-label="t('incoming.truckNo')" v-model="form.truck_no" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.location') }}</label>
                    <div class="mt-1">
                        <SearchableSelect
                            v-model="form.location_code"
                            :options="locations"
                            value-key="code"
                            label-key="code"
                            sublabel-key="name"
                            :placeholder="t('incoming.locationNone')"
                            :aria-label="t('incoming.location')"
                            :error="form.errors.location_code"
                        />
                    </div>
                    <InputError :message="form.errors.location_code" class="mt-1" />
                </div>

                <!-- Kemasan Bertingkat -->
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.bundleQty') }}</label>
                    <input v-model="form.bundle_count" @input="calculateTotal" type="number" step="1" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary font-semibold focus:border-primary focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.bundleUnit') }}</label>
                    <select v-model="form.bundle_unit" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option v-for="code in packingUnits" :key="code" :value="code">{{ code }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.packingPerBundle') }}</label>
                    <input v-model="form.packing_count" @input="calculateTotal" type="number" step="1" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.packingType') }}</label>
                    <select v-model="form.packing_type" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option v-for="pType in packingTypeOptions()" :key="pType" :value="pType">{{ pType }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.qtyPerPacking') }}</label>
                    <input v-model="form.packing_qty" @input="calculateTotal" type="number" step="any" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary font-semibold focus:border-primary focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.qty') }} *</label>
                    <input :aria-label="t('incoming.qty')" v-model="form.qty" type="number" step="any" min="0.0001" class="mt-1 w-full rounded-lg border-primary bg-surface px-3 py-2 text-sm font-bold text-primary focus:border-primary focus:ring-primary" />
                    <InputError :message="form.errors.qty" class="mt-1" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.netWeight') }} ({{ weightUnit }})</label>
                    <input v-model="form.net_weight" type="number" step="any" min="0" :placeholder="weightUnit" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <InputError :message="form.errors.net_weight" class="mt-1" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.grossWeight') }} ({{ weightUnit }})</label>
                    <input v-model="form.gross_weight" type="number" step="any" min="0" :placeholder="weightUnit" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <BackButton :href="backHref()">{{ t('incoming.cancel') }}</BackButton>
                <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-5 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                    {{ form.processing ? t('incoming.saving') : t('incoming.save') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>