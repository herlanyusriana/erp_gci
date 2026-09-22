<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    href: string;
}>();
</script>

<template>
    <Link
        :href="href"
        class="block w-full px-4 py-2 text-start text-sm leading-5 text-ink-primary transition duration-150 ease-in-out hover:bg-primary-light hover:text-primary focus-visible:bg-primary-light focus-visible:text-primary focus-visible:outline-none"
    >
        <slot />
    </Link>
</template>
