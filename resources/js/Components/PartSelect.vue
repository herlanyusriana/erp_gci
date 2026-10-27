<script setup lang="ts">
import { computed, ref, watch } from 'vue';

export interface PartOption {
    id: number;
    part_number: string | null;
    part_name: string | null;
}

const props = withDefaults(
    defineProps<{
        modelValue: number | '';
        options: PartOption[];
        placeholder?: string;
        disabled?: boolean;
        listId: string;
        ariaLabel?: string;
    }>(),
    { placeholder: '', disabled: false, ariaLabel: undefined },
);

const emit = defineEmits<{ 'update:modelValue': [value: number | '']; change: [] }>();

/**
 * Part Number adalah business key (unik) di repo ini, jadi input teks bisa
 * dipetakan balik ke id tanpa menebak. Nama part ditampilkan sebagai label
 * datalist dan sebagai keterangan di bawah input.
 */
function labelFor(id: number | ''): string {
    if (id === '') return '';
    const option = props.options.find((o) => o.id === Number(id));
    return option?.part_number ?? '';
}

const text = ref(labelFor(props.modelValue));

watch(
    () => props.modelValue,
    (value) => {
        const next = labelFor(value);
        if (next !== text.value) text.value = next;
    },
);

const selected = computed(() => {
    if (props.modelValue === '') return null;
    return props.options.find((o) => o.id === Number(props.modelValue)) ?? null;
});

function commit() {
    const needle = text.value.trim().toUpperCase();
    if (needle === '') {
        text.value = '';
        emit('update:modelValue', '');
        emit('change');
        return;
    }

    const match = props.options.find((o) => (o.part_number ?? '').toUpperCase() === needle);
    if (!match) {
        text.value = labelFor(props.modelValue);
        return;
    }

    text.value = match.part_number ?? '';
    if (match.id !== Number(props.modelValue)) {
        emit('update:modelValue', match.id);
        emit('change');
    }
}

const fieldClass =
    'mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary disabled:bg-background disabled:text-ink-secondary';
</script>

<template>
    <div>
        <input
            v-model="text"
            type="text"
            :list="listId"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-label="ariaLabel"
            autocomplete="off"
            :class="fieldClass"
            @change="commit"
            @blur="commit"
        />
        <datalist :id="listId">
            <option v-for="o in options" :key="o.id" :value="o.part_number ?? ''" :label="o.part_name ?? ''" />
        </datalist>
        <p v-if="selected?.part_name" class="mt-1 text-xs text-ink-secondary">{{ selected.part_name }}</p>
    </div>
</template>
