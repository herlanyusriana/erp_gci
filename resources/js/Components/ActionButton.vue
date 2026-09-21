<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = withDefaults(
    defineProps<{
        href?: string;
        label: string;
        variant?: 'edit' | 'delete' | 'view' | 'power' | 'print';
        type?: 'button' | 'submit';
        disabled?: boolean;
    }>(),
    {
        variant: 'edit',
        type: 'button',
        disabled: false,
    },
);

const emit = defineEmits<{ (e: 'click'): void }>();

const tone = computed(() => {
    switch (props.variant) {
        case 'edit':
            return 'text-primary hover:bg-primary-light';
        case 'delete':
            return 'text-danger hover:bg-danger/10';
        case 'view':
            return 'text-info hover:bg-info/10';
        case 'power':
            return 'text-warning hover:bg-warning/10';
        case 'print':
            return 'text-primary hover:bg-primary-light';
        default:
            return 'text-ink-secondary hover:bg-background';
    }
});

// Heroicons v2 outline paths (24x24, stroke 1.8)
const paths = computed<string[]>(() => {
    switch (props.variant) {
        case 'edit':
            return [
                'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10',
            ];
        case 'delete':
            return [
                'M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0',
            ];
        case 'view':
            return [
                'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z',
                'M15 12a3 3 0 11-6 0 3 3 0 016 0z',
            ];
        case 'power':
            return ['M5.636 5.636a9 9 0 1012.728 0', 'M12 3v9'];
        case 'print':
            return [
                'M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z',
            ];
        default:
            return [];
    }
});

const base =
    'inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-lg transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/60 disabled:cursor-not-allowed disabled:opacity-40';
</script>

<template>
    <Link
        v-if="href"
        :href="href"
        :title="label"
        :aria-label="label"
        :class="[base, tone]"
    >
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            class="h-[18px] w-[18px]"
        >
            <path
                v-for="(d, i) in paths"
                :key="i"
                stroke-linecap="round"
                stroke-linejoin="round"
                :d="d"
            />
        </svg>
    </Link>

    <button
        v-else
        :type="type"
        :disabled="disabled"
        :title="label"
        :aria-label="label"
        :class="[base, tone]"
        @click="emit('click')"
    >
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            class="h-[18px] w-[18px]"
        >
            <path
                v-for="(d, i) in paths"
                :key="i"
                stroke-linecap="round"
                stroke-linejoin="round"
                :d="d"
            />
        </svg>
    </button>
</template>