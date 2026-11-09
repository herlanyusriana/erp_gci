<script setup lang="ts">
import SearchableSelect from '@/Components/SearchableSelect.vue';

export interface PartOption {
    id: number;
    part_number: string | null;
    part_name: string | null;
}

const props = withDefaults(
    defineProps<{
        modelValue: number | string | '';
        options: PartOption[];
        placeholder?: string;
        disabled?: boolean;
        listId?: string;
        ariaLabel?: string;
    }>(),
    { placeholder: '', disabled: false, listId: '', ariaLabel: undefined },
);

const emit = defineEmits<{ 'update:modelValue': [value: number | '']; change: [] }>();

function onUpdate(val: any) {
    const parsed = val === '' || val === null || val === undefined ? '' : Number(val);
    emit('update:modelValue', parsed);
    emit('change');
}
</script>

<template>
    <div class="mt-1">
        <SearchableSelect
            :model-value="modelValue"
            :options="options"
            value-key="id"
            label-key="part_number"
            sublabel-key="part_name"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-label="ariaLabel"
            @update:model-value="onUpdate"
        />
    </div>
</template>
