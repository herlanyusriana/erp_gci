<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import FinishedGoodPicker from '@/Components/FinishedGoodPicker.vue';
import type { Part } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    fgParts: Array<Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'>>;
}>();

const form = useForm({
    part_id: '',
    qty: '',
    planned_date: '',
    remarks: '',
});

const picker = ref<InstanceType<typeof FinishedGoodPicker> | null>(null);

function resetForm() {
    form.reset();
    picker.value?.clear();
}

function submit() {
    form.post(route('work-orders.store'), {
        onSuccess: resetForm,
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('production.createWo')" />
        <BackButton :href="route('work-orders.index')" class="mb-4" />

        <div class="mx-auto max-w-xl">
            <div class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('production.createWo') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('production.createDescription') }}</p>
            </div>

            <form @submit.prevent="submit" class="space-y-5 rounded-xl border border-borderline bg-surface p-6">
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.finishedGood') }}</label>
                    <FinishedGoodPicker ref="picker" v-model="form.part_id" :options="fgParts" />
                    <div v-if="form.errors.part_id" class="mt-1 text-xs text-danger">{{ form.errors.part_id }}</div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.qtyPcs') }}</label>
                    <input v-model="form.qty" type="number" step="any" min="0.0001" required class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <div v-if="form.errors.qty" class="mt-1 text-xs text-danger">{{ form.errors.qty }}</div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.plannedDate') }}</label>
                    <input v-model="form.planned_date" type="date" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <div v-if="form.errors.planned_date" class="mt-1 text-xs text-danger">{{ form.errors.planned_date }}</div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">{{ t('production.remarks') }}</label>
                    <textarea v-model="form.remarks" rows="3" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"></textarea>
                    <div v-if="form.errors.remarks" class="mt-1 text-xs text-danger">{{ form.errors.remarks }}</div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" @click="resetForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background">{{ t('production.reset') }}</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                        {{ form.processing ? t('production.saving') : t('production.saveWo') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
