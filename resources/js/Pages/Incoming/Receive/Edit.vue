<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import type { IncomingReceive, IncomingArrivalItem } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    receive: IncomingReceive;
    arrivalItem: IncomingArrivalItem;
    weightBasis: boolean;
    isLocal: boolean;
    uomCodes: string[];
    packingUnits: string[];
    weightUnit: string;
}>();

const backHref = () => props.isLocal
    ? route('local-pos.show', props.arrivalItem.arrival_id)
    : route('incoming-arrivals.show', props.arrivalItem.arrival_id);

const weightUnit = () => props.receive.qty_unit?.toUpperCase() || props.weightUnit || 'KGM';

const form = useForm({
    receive_date: props.receive.ata_date?.slice(0, 10) ?? '',
    tag: props.receive.tag ?? '',
    truck_no: props.receive.truck_no ?? '',
    qty: props.receive.qty,
    bundle_qty: props.receive.bundle_qty ?? '',
    bundle_unit: props.receive.bundle_unit ?? props.packingUnits[0] ?? 'PALLET',
    net_weight: props.receive.net_weight ?? '',
    gross_weight: props.receive.gross_weight ?? '',
});

function submit() {
    if (props.weightBasis) {
        form.transform((data) => ({
            ...data,
            net_weight: data.net_weight === '' ? 0 : Number(data.net_weight),
            gross_weight: data.gross_weight === '' ? null : Number(data.gross_weight),
        }));
    } else {
        form.transform((data) => ({
            ...data,
            net_weight: null,
            gross_weight: null,
        }));
    }
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
            <a :href="route('receive.label', receive.id)" target="_blank" rel="noopener" class="rounded-md bg-primary px-4 py-2 text-xs font-semibold text-white transition hover:bg-primary-hover">{{ t('incoming.printLabel') }}</a>
        </p>

        <form @submit.prevent="submit" class="max-w-2xl space-y-4 rounded-xl border border-borderline bg-surface p-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.receiveDate') }}</label>
                    <input v-model="form.receive_date" type="date" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <InputError :message="form.errors.receive_date" class="mt-1" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.tag') }}</label>
                    <input v-model="form.tag" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <InputError :message="form.errors.tag" class="mt-1" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.truckNo') }}</label>
                    <input v-model="form.truck_no" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.qty') }}</label>
                    <input v-model="form.qty" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <InputError :message="form.errors.qty" class="mt-1" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.bundleQty') }}</label>
                    <input v-model="form.bundle_qty" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.bundleUnit') }}</label>
                    <select v-model="form.bundle_unit" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option v-for="code in packingUnits" :key="code" :value="code">{{ code }}</option>
                    </select>
                </div>
                <div v-if="weightBasis">
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.netUnit', { unit: weightUnit() }) }}</label>
                    <input v-model="form.net_weight" type="number" step="0.0001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <InputError :message="form.errors.net_weight" class="mt-1" />
                </div>
                <div v-if="weightBasis">
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('incoming.grossUnit', { unit: weightUnit() }) }}</label>
                    <input v-model="form.gross_weight" type="number" step="0.0001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <BackButton :href="route('incoming-arrivals.show', arrivalItem.arrival_id)">{{ t('incoming.cancel') }}</BackButton>
                <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-5 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                    {{ form.processing ? t('incoming.saving') : t('incoming.save') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>