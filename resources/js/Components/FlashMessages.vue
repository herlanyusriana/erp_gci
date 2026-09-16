<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    flash?: { message?: string };
}>();
</script>

<template>
    <!-- Not used directly; kept for explicit flash surface -->
</template>