<script setup lang="ts">
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        placeholder?: string;
        ariaLabel?: string;
        ariaRequired?: boolean;
        uppercase?: boolean;
        dedupe?: boolean;
        removeLabel?: (value: string) => string;
    }>(),
    {
        placeholder: '',
        ariaLabel: '',
        ariaRequired: false,
        uppercase: false,
        dedupe: false,
        removeLabel: undefined,
    },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const draft = ref('');
const inputEl = ref<HTMLInputElement | null>(null);

function normalizeToken(raw: string): string {
    const collapsed = raw.trim().replace(/\s+/g, ' ');
    return props.uppercase ? collapsed.toUpperCase() : collapsed;
}

function parseList(raw: string): string[] {
    const parts = (raw ?? '').split(/[,;\r\n]+/);
    const result: string[] = [];
    const seen = new Set<string>();

    for (const part of parts) {
        const item = normalizeToken(part);
        if (!item) continue;
        if (props.dedupe) {
            const key = item.toUpperCase();
            if (seen.has(key)) continue;
            seen.add(key);
        }
        result.push(item);
    }

    return result;
}

const items = computed(() => parseList(props.modelValue));

function emitList(next: string[]) {
    emit('update:modelValue', next.join(', '));
}

function addTokens(raw: string) {
    const incoming = parseList(raw);
    if (!incoming.length) return;

    const next = [...items.value];
    const seen = new Set(next.map((v) => v.toUpperCase()));

    for (const token of incoming) {
        if (props.dedupe) {
            const key = token.toUpperCase();
            if (seen.has(key)) continue;
            seen.add(key);
        }
        next.push(token);
    }

    emitList(next);
}

function commitDraft() {
    if (!draft.value.trim()) {
        draft.value = '';
        return;
    }
    addTokens(draft.value);
    draft.value = '';
}

function removeAt(index: number) {
    const next = items.value.filter((_, i) => i !== index);
    emitList(next);
}

function onInput(event: Event) {
    const raw = (event.target as HTMLInputElement).value;
    const value = props.uppercase ? raw.toUpperCase() : raw;
    if (/[,;\r\n]/.test(value)) {
        const segments = value.split(/[,;\r\n]+/);
        const trailing = segments.pop() ?? '';
        addTokens(segments.join(','));
        draft.value = trailing;
        return;
    }
    draft.value = value;
}

function onPaste(event: ClipboardEvent) {
    const text = event.clipboardData?.getData('text') ?? '';
    if (/[,;\r\n]/.test(text)) {
        event.preventDefault();
        addTokens(`${draft.value},${text}`);
        draft.value = '';
    }
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter') {
        event.preventDefault();
        commitDraft();
        return;
    }
    if (event.key === 'Tab' && draft.value.trim()) {
        commitDraft();
        return;
    }
    if (event.key === 'Backspace' && draft.value === '' && items.value.length > 0) {
        removeAt(items.value.length - 1);
    }
}

function focusInput() {
    inputEl.value?.focus();
}

defineExpose({ commitDraft });
</script>

<template>
    <div
        class="mt-1 flex min-h-[40px] w-full flex-wrap items-center gap-1.5 rounded-lg border border-borderline bg-surface px-2.5 py-1.5 text-sm transition focus-within:border-primary focus-within:ring-1 focus-within:ring-primary"
        @click="focusInput"
    >
        <span
            v-for="(item, idx) in items"
            :key="`${item}-${idx}`"
            class="inline-flex max-w-full items-center gap-1 rounded-md bg-primary-light px-2 py-0.5 text-xs font-semibold text-primary"
        >
            <span class="truncate">{{ item }}</span>
            <button
                type="button"
                class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded text-primary/70 transition hover:bg-primary/15 hover:text-primary active:scale-95 motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary"
                :aria-label="removeLabel ? removeLabel(item) : `Remove ${item}`"
                @click.stop="removeAt(idx)"
            >
                <svg aria-hidden="true" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                    <path
                        d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"
                    />
                </svg>
            </button>
        </span>

        <input
            ref="inputEl"
            :value="draft"
            type="text"
            :placeholder="items.length === 0 ? placeholder : ''"
            :aria-label="ariaLabel"
            :aria-required="ariaRequired || undefined"
            class="min-w-[110px] flex-1 border-0 bg-transparent p-0.5 text-sm text-ink-primary placeholder:text-ink-secondary/70 focus:outline-none focus:ring-0"
            :class="uppercase ? 'font-semibold' : ''"
            @input="onInput"
            @paste="onPaste"
            @keydown="onKeydown"
            @blur="commitDraft"
        />

        <button
            v-if="draft.trim().length > 0"
            type="button"
            class="inline-flex h-6 shrink-0 items-center rounded border border-primary/30 bg-primary-light px-1.5 text-[11px] font-bold text-primary transition hover:bg-primary hover:text-white active:scale-95 motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary"
            @mousedown.prevent="commitDraft"
        >
            +
        </button>
    </div>
</template>
