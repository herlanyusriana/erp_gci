<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
        size?: 'sm' | 'md';
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
        size: 'sm',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: any];
    change: [value: any, option: any];
    clear: [];
}>();

// Id unik per instance: dipakai aria-controls/aria-activedescendant.
let instanceSeq = 0;
const instanceId = `ss-${++instanceSeq}-${Math.random().toString(36).slice(2, 7)}`;
const listboxId = `${instanceId}-listbox`;
const optionId = (index: number) => `${instanceId}-opt-${index}`;

const containerRef = ref<HTMLElement | null>(null);
const triggerRef = ref<HTMLButtonElement | null>(null);
const popupRef = ref<HTMLElement | null>(null);
const listRef = ref<HTMLElement | null>(null);
const searchRef = ref<HTMLInputElement | null>(null);

const isOpen = ref(false);
const searchQuery = ref('');
const highlightedIndex = ref(-1);
const popupStyle = ref<Record<string, string>>({});
const portalTarget = ref<string | HTMLElement>('body');

const normalizedOptions = computed<SelectOptionItem[]>(() =>
    (props.options ?? []).map((opt) => {
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
    }),
);

const selectedOption = computed(() => {
    if (props.modelValue === null || props.modelValue === undefined || props.modelValue === '') {
        return null;
    }

    return normalizedOptions.value.find((o) => String(o.value) === String(props.modelValue)) ?? null;
});

/** Cocokkan pencarian ke label + sublabel, bukan seluruh isi objek. */
const filteredOptions = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();

    if (q === '') {
        return normalizedOptions.value.slice(0, props.maxItems);
    }

    return normalizedOptions.value
        .filter((o) => o.label.toLowerCase().includes(q) || (o.sublabel ?? '').toLowerCase().includes(q))
        .slice(0, props.maxItems);
});

const totalOptions = computed(() => normalizedOptions.value.length);
const truncated = computed(() => filteredOptions.value.length < totalOptions.value);

function updatePosition() {
    const el = triggerRef.value;
    if (el === null) {
        return;
    }

    const rect = el.getBoundingClientRect();
    const margin = 8;
    const width = Math.min(Math.max(rect.width, 288), window.innerWidth - margin * 2);
    const left = Math.min(Math.max(rect.left, margin), window.innerWidth - margin - width);
    const below = window.innerHeight - rect.bottom - margin;
    const above = rect.top - margin;
    const openUp = below < 320 && above > below;

    popupStyle.value = {
        position: 'fixed',
        left: `${left}px`,
        width: `${width}px`,
        ...(openUp
            ? { bottom: `${window.innerHeight - rect.top + 4}px`, maxHeight: `${Math.max(Math.min(above, 352), 160)}px` }
            : { top: `${rect.bottom + 4}px`, maxHeight: `${Math.max(Math.min(below, 352), 160)}px` }),
    };
}

function openDropdown() {
    if (props.disabled || isOpen.value) {
        return;
    }

    // Popup harus ikut lapisan atas <dialog> agar tidak tertutup backdrop.
    const dialog = containerRef.value?.closest('dialog');
    portalTarget.value = dialog ?? 'body';

    // Posisi dihitung sebelum popup dirender supaya tidak berkedip di dasar
    // halaman selama satu frame.
    searchQuery.value = '';
    highlightedIndex.value = -1;
    updatePosition();
    isOpen.value = true;

    nextTick(() => searchRef.value?.focus());
}

function closeDropdown(restoreFocus = true) {
    // Fokus dikembalikan secara sinkron: pemanggil (mis. form yang memindahkan
    // fokus ke input qty setelah memilih) menjadwalkan fokusnya di `nextTick`,
    // sehingga fokus panggilan ini tidak menimpanya.
    if (restoreFocus) {
        triggerRef.value?.focus();
    }

    isOpen.value = false;
    searchQuery.value = '';
    highlightedIndex.value = -1;
}

function toggle() {
    if (isOpen.value) {
        closeDropdown();
        return;
    }

    openDropdown();
}

function selectOption(option: SelectOptionItem) {
    if (option.disabled) {
        return;
    }

    emit('update:modelValue', option.value);
    emit('change', option.value, option);
    closeDropdown();
}

function clearSelection(e?: Event) {
    e?.stopPropagation();
    emit('update:modelValue', '');
    emit('change', '', null);
    emit('clear');
    closeDropdown();
}

function scrollToHighlighted() {
    nextTick(() => {
        const active = listRef.value?.querySelector<HTMLElement>('[data-highlighted="true"]');
        active?.scrollIntoView({ block: 'nearest' });
    });
}

function moveHighlight(step: number) {
    const items = filteredOptions.value;
    if (items.length === 0) {
        return;
    }

    highlightedIndex.value = (highlightedIndex.value + step + items.length) % items.length;
    scrollToHighlighted();
}

function onTriggerKeyDown(e: KeyboardEvent) {
    if (props.disabled) {
        return;
    }

    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
        e.preventDefault();
        openDropdown();
    }
}

function onSearchKeyDown(e: KeyboardEvent) {
    const items = filteredOptions.value;

    switch (e.key) {
        case 'ArrowDown':
            e.preventDefault();
            moveHighlight(1);
            break;
        case 'ArrowUp':
            e.preventDefault();
            moveHighlight(-1);
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
            closeDropdown(false);
            break;
    }
}

function handlePointerDown(e: PointerEvent) {
    if (!isOpen.value) {
        return;
    }

    const target = e.target as Node;

    // Popup dirender lewat teleport, jadi containerRef saja tidak cukup.
    if (containerRef.value?.contains(target) || popupRef.value?.contains(target)) {
        return;
    }

    closeDropdown(false);
}

function onViewportChange() {
    if (isOpen.value) {
        updatePosition();
    }
}

watch(
    () => props.modelValue,
    () => {
        if (isOpen.value) {
            highlightedIndex.value = -1;
        }
    },
);

watch(
    () => props.disabled,
    (disabled) => {
        if (disabled && isOpen.value) {
            closeDropdown(false);
        }
    },
);

// Dipakai form yang perlu memindahkan fokus ke baris tertentu (mis. setelah
// validasi gagal): gulir dulu, baru fokus.
defineExpose({
    focus: () => {
        triggerRef.value?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        triggerRef.value?.focus();
    },
    /** Buka daftar sekaligus fokus ke kolom pencarian (dipakai saat baris baru dibuat). */
    open: () => {
        triggerRef.value?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        openDropdown();
    },
});

onMounted(() => {
    document.addEventListener('pointerdown', handlePointerDown);
    window.addEventListener('resize', onViewportChange);
    window.addEventListener('scroll', onViewportChange, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', handlePointerDown);
    window.removeEventListener('resize', onViewportChange);
    window.removeEventListener('scroll', onViewportChange, true);
});
</script>

<template>
    <div ref="containerRef" class="relative w-full">
        <div class="relative flex items-center">
            <button
                ref="triggerRef"
                type="button"
                role="combobox"
                aria-haspopup="listbox"
                :aria-expanded="isOpen"
                :aria-controls="listboxId"
                :aria-label="ariaLabel"
                :aria-invalid="error ? 'true' : undefined"
                :disabled="disabled"
                :class="[
                    'flex w-full items-center gap-2 rounded-lg border bg-surface pl-3 text-left transition',
                    size === 'md' ? 'h-11' : 'min-h-[38px] py-1.5',
                    allowClear && selectedOption ? 'pr-[3.25rem]' : 'pr-11',
                    error
                        ? 'border-danger focus-visible:border-danger focus-visible:ring-danger'
                        : 'border-borderline hover:border-primary hover:bg-primary-light/40 focus-visible:border-primary focus-visible:ring-primary',
                    'focus-visible:outline-none focus-visible:ring-2',
                    'disabled:cursor-not-allowed disabled:border-borderline disabled:bg-background disabled:text-ink-secondary disabled:hover:bg-background',
                ]"
                @click="toggle"
                @keydown="onTriggerKeyDown"
            >
                <slot name="leading" />

                <span v-if="selectedOption" class="flex min-w-0 flex-1 flex-col leading-tight">
                    <span class="truncate text-sm font-medium text-ink-primary">{{ selectedOption.label }}</span>
                    <span v-if="selectedOption.sublabel" class="truncate font-mono text-[11px] text-ink-secondary">
                        {{ selectedOption.sublabel }}
                    </span>
                </span>
                <span v-else class="min-w-0 flex-1 truncate text-sm text-ink-secondary">
                    {{ placeholder || t('common.selectPlaceholder') }}
                </span>

                <svg
                    class="h-4 w-4 shrink-0 text-ink-secondary transition-transform"
                    :class="isOpen ? 'rotate-180' : ''"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" />
                </svg>
            </button>

            <button
                v-if="allowClear && selectedOption && !disabled"
                type="button"
                tabindex="-1"
                :aria-label="t('common.clearSelection')"
                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-ink-secondary transition hover:bg-background hover:text-ink-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                @click="clearSelection"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </button>
        </div>

        <Teleport :to="portalTarget">
            <Transition
                enter-active-class="transition ease-out duration-100"
                enter-from-class="opacity-0 translate-y-1"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-75"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 translate-y-1"
            >
                <div
                    v-if="isOpen"
                    ref="popupRef"
                    :style="popupStyle"
                    class="z-[65] flex flex-col overflow-hidden rounded-lg border border-borderline bg-surface shadow-lg ring-1 ring-ink-primary/5"
                >
                    <div class="border-b border-borderline p-2">
                        <input
                            ref="searchRef"
                            v-model="searchQuery"
                            type="text"
                            role="combobox"
                            aria-autocomplete="list"
                            :aria-controls="listboxId"
                            :aria-expanded="true"
                            :aria-activedescendant="highlightedIndex >= 0 ? optionId(highlightedIndex) : undefined"
                            :aria-label="ariaLabel || placeholder || t('common.searchInList')"
                            autocomplete="off"
                            :placeholder="t('common.searchInList')"
                            class="w-full rounded-md border-borderline bg-background px-2.5 py-1.5 text-sm text-ink-primary placeholder:text-ink-secondary focus:border-primary focus:ring-primary"
                            @input="highlightedIndex = 0"
                            @keydown="onSearchKeyDown"
                        />
                    </div>

                    <ul
                        ref="listRef"
                        :id="listboxId"
                        role="listbox"
                        :aria-label="ariaLabel || placeholder"
                        class="min-h-0 flex-1 overflow-y-auto py-1"
                    >
                        <li
                            v-for="(option, idx) in filteredOptions"
                            :id="optionId(idx)"
                            :key="option.value"
                            role="option"
                            :data-highlighted="idx === highlightedIndex"
                            :aria-selected="String(option.value) === String(modelValue)"
                            :aria-disabled="option.disabled ? 'true' : undefined"
                            :class="[
                                'flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm transition',
                                option.disabled ? 'cursor-not-allowed text-ink-secondary' : '',
                                idx === highlightedIndex ? 'bg-primary-light' : 'hover:bg-background',
                            ]"
                            @pointerdown.prevent="selectOption(option)"
                            @mouseenter="highlightedIndex = idx"
                        >
                            <slot
                                name="option"
                                :option="option"
                                :selected="String(option.value) === String(modelValue)"
                                :active="idx === highlightedIndex"
                            >
                                <div class="flex min-w-0 flex-col">
                                    <span
                                        class="truncate font-medium"
                                        :class="String(option.value) === String(modelValue) ? 'text-primary' : 'text-ink-primary'"
                                    >
                                        {{ option.label }}
                                    </span>
                                    <span
                                        v-if="option.sublabel"
                                        class="truncate text-xs"
                                        :class="idx === highlightedIndex ? 'text-primary' : 'text-ink-secondary'"
                                    >
                                        {{ option.sublabel }}
                                    </span>
                                </div>

                                <span
                                    v-if="option.badge"
                                    class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold tabular-nums"
                                    :class="{
                                        'bg-success/10 text-success-ink': option.badgeTone === 'success',
                                        'bg-warning/10 text-warning-ink': option.badgeTone === 'warning',
                                        'bg-danger/10 text-danger': option.badgeTone === 'danger',
                                        'bg-background text-ink-secondary': !option.badgeTone || option.badgeTone === 'neutral',
                                    }"
                                >
                                    {{ option.badge }}
                                </span>
                                <span
                                    v-else-if="String(option.value) === String(modelValue)"
                                    class="shrink-0 text-sm font-bold text-primary"
                                    aria-hidden="true"
                                >
                                    ✓
                                </span>
                            </slot>
                        </li>

                        <li v-if="filteredOptions.length === 0" class="px-3 py-3 text-center text-xs text-ink-secondary">
                            {{ t('common.noResults') }}
                        </li>
                        <li
                            v-else-if="truncated && searchQuery.trim() === ''"
                            class="px-3 py-2 text-center text-[11px] text-ink-secondary"
                        >
                            {{ t('common.typeToSearch', { count: totalOptions }) }}
                        </li>
                    </ul>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
