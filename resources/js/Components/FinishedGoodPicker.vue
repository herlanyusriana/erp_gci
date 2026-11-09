<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import type { Part } from '@/types';

type FgOption = Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'>;

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        options: FgOption[];
        showModel?: boolean;
        placeholder?: string;
    }>(),
    {
        showModel: true,
        placeholder: undefined,
    },
);

const selectedId = defineModel<string>({ required: true });

const formattedOptions = computed(() =>
    props.options.map((p) => ({
        value: String(p.id),
        label: p.part_number,
        sublabel: props.showModel && p.model ? `${p.part_name} · ${p.model}` : p.part_name,
    })),
);

function clear() {
    selectedId.value = '';
}

function syncFromSelected() {
    // Handled reactively by SearchableSelect
}

defineExpose({ syncFromSelected, clear });
</script>

<template>
    <SearchableSelect
        v-model="selectedId"
        :options="formattedOptions"
        value-key="value"
        label-key="label"
        sublabel-key="sublabel"
        :placeholder="placeholder ?? t('production.searchFg')"
        :aria-label="placeholder ?? t('production.searchFg')"
    />
</template>
