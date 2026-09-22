<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import type { Part, PartType, Uom } from '@/types';
import { computed } from 'vue';

const { t } = useI18n();

const props = defineProps<{
    part: Part | null;
    partTypes: PartType[];
    uoms: Uom[];
    defaultUomId?: number | null;
    defaultType?: string;
}>();

const isEdit = props.part !== null;

const form = useForm({
    part_number: props.part?.part_number ?? '',
    part_name: props.part?.part_name ?? '',
    hs_code: props.part?.hs_code ?? '',
    part_type_id: props.part?.part_type?.id ?? (props.defaultType
        ? (props.partTypes.find((t) => t.code === props.defaultType)?.id ?? ('' as number | ''))
        : ('' as number | '')),
    model: props.part?.model ?? '',
    uom_id: props.part?.uom?.id ?? props.defaultUomId ?? ('' as number | ''),
    size: props.part?.size ?? '',
    nett_weight: props.part?.nett_weight ?? ('' as number | ''),
    is_active: props.part?.is_active ?? true,
    remarks: props.part?.remarks ?? '',
});

// Resolve the currently selected part-type code (FG / MATERIAL / WIP)
const selectedType = computed<PartType | null>(
    () => props.partTypes.find((t) => t.id === form.part_type_id) ?? null,
);

const typeCode = computed(() => selectedType.value?.code ?? '');

function pickType(t: PartType) {
    form.part_type_id = t.id;
    // Clear fields that don't belong to the new type for a clean start.
    form.size = '';
    form.nett_weight = '';
}

function submit() {
    if (isEdit && props.part) {
        form.put(route('parts.update', props.part.id));
    } else {
        form.post(route('parts.store'));
    }
}

function partTypeLabel(partType: PartType | null): string {
    switch (partType?.code) {
        case 'FG': return t('master.finishedGoods');
        case 'MATERIAL': return t('master.material');
        case 'WIP': return t('master.wip');
        default: return partType?.name ?? '';
    }
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('parts.index')" class="mb-4" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">
                {{ isEdit ? t('master.editPart') : t('master.newPart') }}
            </h1>
            <p class="mt-1 text-sm text-ink-secondary">{{ t('master.partFormSubtitle') }}</p>
        </div>

        <form @submit.prevent="submit" class="max-w-2xl space-y-6 rounded-xl border border-borderline bg-surface p-6">
            <!-- Part Type selector (segmented) -->
            <section class="space-y-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-secondary">{{ t('master.partType') }}</h2>
                <div v-if="!isEdit" class="grid grid-cols-3 gap-2">
                    <button
                        v-for="partType in partTypes"
                        :key="partType.id"
                        type="button"
                        :class="typeCode === partType.code
                            ? 'border-primary bg-primary text-white'
                            : 'border-borderline bg-surface text-ink-secondary hover:border-primary hover:text-primary'"
                        class="rounded-lg border px-3 py-2 text-sm font-semibold transition"
                        @click="pickType(partType)"
                    >
                        {{ partTypeLabel(partType) }}
                    </button>
                </div>
                <select :aria-label="t('master.selectType')" v-else v-model="form.part_type_id" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                    <option value="">{{ t('master.selectType') }}</option>
                    <option v-for="partType in partTypes" :key="partType.id" :value="partType.id">{{ partType.code }} — {{ partTypeLabel(partType) }}</option>
                </select>
                <InputError :message="form.errors.part_type_id" class="mt-1" />
            </section>

            <!-- General Information -->
            <section class="space-y-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-secondary">{{ t('master.generalInformation') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel for="part_number" :value="t('master.partNumber')" />
                        <input id="part_number" v-model="form.part_number" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.part_number" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="part_name" :value="t('master.partName')" />
                        <input id="part_name" v-model="form.part_name" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.part_name" class="mt-1" />
                    </div>
                    <div v-if="typeCode !== 'WIP'">
                        <InputLabel for="hs_code" :value="t('master.hsCode')" />
                        <input id="hs_code" v-model="form.hs_code" type="text" :placeholder="t('master.hsExample')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.hs_code" class="mt-1" />
                    </div>
                    <div v-else>
                        <InputLabel for="hs_code" :value="t('master.hsCode')" />
                        <input id="hs_code" v-model="form.hs_code" type="text" disabled :placeholder="t('master.wipNotApplicable')" class="mt-1 w-full cursor-not-allowed rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-secondary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <InputLabel for="model" :value="t('master.model')" />
                        <input id="model" v-model="form.model" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.model" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="uom_id" :value="t('master.uom')" />
                        <select id="uom_id" v-model="form.uom_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">{{ t('master.selectUom') }}</option>
                            <option v-for="u in uoms" :key="u.id" :value="u.id">{{ u.code }}</option>
                        </select>
                        <InputError :message="form.errors.uom_id" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="is_active" :value="t('master.status')" />
                        <select id="is_active" v-model="form.is_active" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option :value="true">{{ t('master.active') }}</option>
                            <option :value="false">{{ t('master.inactive') }}</option>
                        </select>
                    </div>
                </div>
            </section>

            <!-- Type-specific specification -->
            <section class="space-y-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-secondary">{{ t('master.specification', { name: partTypeLabel(selectedType) }) }}</h2>

                <!-- FG: Nett Weight -->
                <div v-if="typeCode === 'FG'" class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel for="nett_weight" :value="t('master.netWeight')" />
                        <input id="nett_weight" v-model="form.nett_weight" type="number" step="any" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.nett_weight" class="mt-1" />
                    </div>
                </div>

                <!-- MATERIAL: Size -->
                <div v-else-if="typeCode === 'MATERIAL'" class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel for="size" :value="t('master.size')" />
                        <input id="size" v-model="form.size" type="text" placeholder="0.25 X 640 X 1480" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.size" class="mt-1" />
                    </div>
                </div>

                <!-- WIP: No extra fields -->
                <p v-else-if="typeCode === 'WIP'" class="text-sm text-ink-secondary">{{ t('master.wipNoSpecifications') }}</p>

                <p v-else class="text-sm text-ink-secondary">{{ t('master.selectPartTypeFirst') }}</p>
            </section>

            <section class="space-y-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-secondary">{{ t('master.remarks') }}</h2>
                <textarea v-model="form.remarks" :aria-label="t('master.remarks')" rows="3" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                <InputError :message="form.errors.remarks" class="mt-1" />
            </section>

            <!-- Action bar -->
            <div class="flex items-center justify-end gap-3 border-t border-borderline pt-4">
                <Link :href="route('parts.index')" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background">{{ t('master.cancel') }}</Link>
                <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                    {{ form.processing ? t('master.saving') : t('master.save') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>