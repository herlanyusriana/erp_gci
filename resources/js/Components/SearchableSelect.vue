<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

export interface SelectOptionItem {
    value: string | number;
    label: string;
    sublabel?: string;
    badge?: string;
    badgeTone?: 'success' | 'warning' | 'danger' | 'neutral';
    disabled?: boolean;
    [key: string]: any;
}

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        modelValue: string | number | null | undefined;
        options: any[];
        valueKey?: string;
        labelKey?: string;
        sublabelKey?: string;
        placeholder?: string;
        disabled?: boolean;
        allowClear?: boolean;
        error?: string | boolean;
        ariaLabel?: string;
        maxItems?: number;
    }>(),
    {
        valueKey: 'id',
        labelKey: 'name',
        sublabelKey: '',
        placeholder: '',
        disabled: false,
        allowClear: true,
        error: undefined,
        ariaLabel: undefined,
        maxItems: 60,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: any];
    change: [value: any, option: any];
    clear: [];
}>();

const containerRef = ref<HTMLElement | null>(null);
const inputRef = ref<HTMLInputElement | null>(null);
const listboxRef = ref<HTMLElement | null>(null);

const isOpen = ref(false);
const searchQuery = ref('');
const highlightedIndex = ref(-1);

const normalizedOptions = computed<SelectOptionItem[]>(() => {
    return (props.options ?? []).map((opt) => {
        if (typeof opt === 'string' || typeof opt === 'number') {
            return { value: opt, label: String(opt) };
        }
        const val = opt.value !== undefined ? opt.value : opt[props.valueKey];
        const lbl = opt.label !== undefined ? opt.label : opt[props.labelKey];
        const sub = props.sublabelKey && opt[props.sublabelKey] ? opt[props.sublabelKey] : opt.sublabel;
        return {
            ...opt,
            value: val,
            label: String(lbl ?? ''),
            sublabel: sub ? String(sub) : undefined,
        };
    });
});

const selectedOption = computed(() => {
    if (props.modelValue === null || props.modelValue === undefined || props.modelValue === '') {
        return null;
    }
    return normalizedOptions.value.find((o) => String(o.value) === String(props.modelValue)) ?? null;
});

function formatDisplay(opt: SelectOptionItem | null): string {
    if (!opt) return '';
    return opt.sublabel ? `${opt.label} · ${opt.sublabel}` : opt.label;
}

const filteredOptions = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    // Jika input sama persis dengan format tampilan terpilih, tampilkan daftar awal agar user bisa ganti pilihan
    if (!q || (selectedOption.value && q === formatDisplay(selectedOption.value).toLowerCase())) {
        return normalizedOptions.value.slice(0, props.maxItems);
    }
    return normalizedOptions.value
        .filter((o) => {
            const matchLabel = o.label.toLowerCase().includes(q);
            const matchSub = o.sublabel ? o.sublabel.toLowerCase().includes(q) : false;
            return matchLabel || matchSub;
        })
        .slice(0, props.maxItems);
});

function syncSearchFromSelected() {
    searchQuery.value = formatDisplay(selectedOption.value);
}

watch(
    [() => props.modelValue, () => props.options],
    () => {
        if (!isOpen.value) {
            syncSearchFromSelected();
        }
    },
    { immediate: true },
);

function openDropdown() {
    if (props.disabled) return;
    isOpen.value = true;
    highlightedIndex.value = -1;
    nextTick(() => {
        if (inputRef.value) {
            inputRef.value.select();
        }
    });
}

function closeDropdown() {
    isOpen.value = false;
    highlightedIndex.value = -1;
    syncSearchFromSelected();
}

function selectOption(option: SelectOptionItem) {
    if (option.disabled) return;
    emit('update:modelValue', option.value);
    emit('change', option.value, option);
    isOpen.value = false;
    searchQuery.value = formatDisplay(option);
}

function clearSelection(e?: Event) {
    e?.stopPropagation();
    emit('update:modelValue', '');
    emit('change', '', null);
    emit('clear');
    searchQuery.value = '';
    isOpen.value = false;
}

function onInput() {
    if (!isOpen.value) {
        isOpen.value = true;
    }
    highlightedIndex.value = 0;
}

function onKeyDown(e: KeyboardEvent) {
    if (props.disabled) return;

    if (!isOpen.value) {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
            e.preventDefault();
            openDropdown();
            return;
        }
    }

    const items = filteredOptions.value;
    if (items.length === 0) {
        if (e.key === 'Escape') closeDropdown();
        return;
    }

    switch (e.key) {
        case 'ArrowDown':
            e.preventDefault();
            highlightedIndex.value = (highlightedIndex.value + 1) % items.length;
            scrollToHighlighted();
            break;
        case 'ArrowUp':
            e.preventDefault();
            highlightedIndex.value = (highlightedIndex.value - 1 + items.length) % items.length;
            scrollToHighlighted();
            break;
        case 'Enter':
            e.preventDefault();
            if (highlightedIndex.value >= 0 && highlightedIndex.value < items.length) {
                selectOption(items[highlightedIndex.value]);
            } else if (items.length === 1) {
                selectOption(items[0]);
            }
            break;
        case 'Escape':
            e.preventDefault();
            closeDropdown();
            break;
        case 'Tab':
            closeDropdown();
            break;
    }
}

function scrollToHighlighted() {
    nextTick(() => {
        if (!listboxRef.value) return;
        const activeEl = listboxRef.value.querySelector('[data-highlighted="true"]') as HTMLElement;
        if (activeEl) {
            activeEl.scrollIntoView({ block: 'nearest' });
        }
    });
}

function handleClickOutside(e: MouseEvent) {
    if (containerRef.value && !containerRef.value.contains(e.target as Node)) {
        if (isOpen.value) {
            closeDropdown();
        }
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('pointerdown', handleClickOutside);
});
</script>

<template>
    <div ref="containerRef" class="relative w-full">
        <div class="relative flex items-center">
            <input
                ref="inputRef"
                v-model="searchQuery"
                type="text"
                role="combobox"
                :aria-expanded="isOpen"
                :aria-label="ariaLabel"
                aria-autocomplete="list"
                autocomplete="off"
                :disabled="disabled"
                :placeholder="placeholder || '— Ketik untuk mencari / pilih —'"
                :class="[
                    'w-full rounded-lg border bg-surface px-3 py-2 text-sm text-ink-primary transition placeholder:text-ink-secondary/60 focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-background disabled:text-ink-secondary',
                    error
                        ? 'border-danger focus:border-danger focus:ring-danger/20'
                        : 'border-borderline focus:border-primary focus:ring-primary/20',
                    allowClear && selectedOption ? 'pr-16' : 'pr-9',
                ]"
                @focus="openDropdown"
                @input="onInput"
                @keydown="onKeyDown"
            />

            <div class="absolute right-2 flex items-center gap-1 text-ink-secondary">
                <button
                    v-if="allowClear && selectedOption && !disabled"
                    type="button"
                    tabindex="-1"
                    aria-label="Hapus pilihan"
                    class="rounded p-0.5 hover:bg-background hover:text-ink-primary focus:outline-none"
                    @click="clearSelection"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>
                <button
                    type="button"
                    tabindex="-1"
                    :disabled="disabled"
                    class="rounded p-0.5 transition-transform hover:text-ink-primary focus:outline-none"
                    :class="isOpen ? 'rotate-180' : ''"
                    @click="isOpen ? closeDropdown() : openDropdown()"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" />
                    </svg>
                </button>
            </div>
        </div>

        <Transition
            enter-active-class="transition ease-out duration-100"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="isOpen"
                ref="listboxRef"
                role="listbox"
                class="absolute z-50 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-borderline bg-surface py-1 shadow-lg ring-1 ring-black/5"
            >
                <div
                    v-for="(option, idx) in filteredOptions"
                    :key="option.value"
                    role="option"
                    :data-highlighted="idx === highlightedIndex"
                    :aria-selected="String(option.value) === String(modelValue)"
                    :class="[
                        'flex cursor-pointer items-center justify-between px-3 py-2 text-sm transition',
                        option.disabled ? 'cursor-not-allowed opacity-40' : '',
                        idx === highlightedIndex ? 'bg-primary-light text-primary font-medium' : 'text-ink-primary hover:bg-background',
                        String(option.value) === String(modelValue) ? 'font-semibold text-primary' : '',
                    ]"
                    @mousedown.prevent="selectOption(option)"
                    @mouseenter="highlightedIndex = idx"
                >
                    <slot name="option" :option="option" :selected="String(option.value) === String(modelValue)" :active="idx === highlightedIndex">
                        <div class="flex flex-col truncate">
                            <span class="truncate font-medium">{{ option.label }}</span>
                            <span v-if="option.sublabel" class="truncate text-xs text-ink-secondary">
                                {{ option.sublabel }}
                            </span>
                        </div>
                        <span v-if="String(option.value) === String(modelValue)" class="ml-2 shrink-0 text-primary font-bold">✓</span>
                    </slot>
                </div>

                <div v-if="filteredOptions.length === 0" class="px-3 py-3 text-center text-xs text-ink-secondary">
                    Tidak ada data yang cocok
                </div>
            </div>
        </Transition>
    </div>
</template>
