<script setup lang="ts">
withDefaults(
    defineProps<{
        type?: 'button' | 'submit' | 'reset';
    }>(),
    {
        type: 'button',
    },
);
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-md border border-borderline bg-surface px-4 py-2 text-xs font-semibold uppercase tracking-widest text-ink-primary shadow-sm transition duration-150 ease-in-out hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:opacity-40"
    >
        <slot />
    </button>
</template>
