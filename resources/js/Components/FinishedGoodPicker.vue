<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Part } from '@/types';

type FgOption = Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'>;

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        options: FgOption[];
        /** Tampilkan kolom model pada label & saran. */
        showModel?: boolean;
        placeholder?: string;
    }>(),
    {
        showModel: true,
        placeholder: undefined,
    },
);

const selectedId = defineModel<string>({ required: true });

const query = ref('');
const open = ref(false);

const selected = computed(() => props.options.find((p) => String(p.id) === String(selectedId.value)) ?? null);

const modelText = (p: FgOption) => (props.showModel ? ` · ${p.model || '—'}` : '');

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.options.slice(0, 25);
    return props.options
        .filter((p) => [p.part_number, p.part_name, props.showModel ? p.model : null]
            .filter(Boolean)
            .some((v) => String(v).toLowerCase().includes(q)))
        .slice(0, 25);
});

function select(part: FgOption) {
    selectedId.value = String(part.id);
    query.value = `${part.part_number} · ${part.part_name}${modelText(part)}`;
    open.value = false;
}

function clear() {
    selectedId.value = '';
    query.value = '';
    open.value = true;
}

function onInput() {
    open.value = true;
    selectedId.value = '';
}

function syncFromSelected() {
    if (selected.value) {
        query.value = `${selected.value.part_number} · ${selected.value.part_name}${modelText(selected.value)}`;
    }
}

defineExpose({ syncFromSelected, clear });
</script>

<template>
    <div class="relative">
        <input
            v-model="query"
            type="text"
            autocomplete="off"
            :placeholder="placeholder ?? t('production.searchFg')"
            class="w-full rounded-lg border-borderline bg-background px-3 py-2 pr-20 text-sm text-ink-primary focus:border-primary focus:ring-primary"
            @focus="open = true"
            @input="onInput"
        />
        <button
            v-if="selected"
            type="button"
            class="absolute inset-y-0 right-2 my-auto h-7 rounded px-2 text-xs text-ink-secondary hover:bg-background"
            @click="clear"
        >
            {{ t('production.change') }}
        </button>
        <div v-if="open" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-borderline bg-surface py-1 shadow-lg">
            <button
                v-for="p in filtered"
                :key="p.id"
                type="button"
                class="block w-full px-3 py-2 text-left text-sm hover:bg-primary-light"
                @mousedown.prevent="select(p)"
            >
                <span class="font-medium text-ink-primary">{{ p.part_number }}</span>
                <span class="text-ink-secondary"> · {{ p.part_name }}{{ modelText(p) }}</span>
            </button>
            <div v-if="filtered.length === 0" class="px-3 py-3 text-sm text-ink-secondary">{{ t('production.fgNotFound') }}</div>
        </div>
    </div>
</template>
