<script setup lang="ts">
import { onMounted, ref } from 'vue';

const model = defineModel<string>({ required: true });

const input = ref<HTMLInputElement | null>(null);

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value?.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <input
        class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary shadow-sm transition focus:border-primary focus:ring-primary"
        v-model="model"
        ref="input"
    />
</template>
