<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import type { Bom, Machine, Part, Process } from '@/types';

defineProps<{
    bom: Bom | null;
    fgParts: Array<Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'>>;
    materials: Array<Pick<Part, 'id' | 'part_number' | 'part_name'>>;
    processes: Array<Pick<Process, 'id' | 'process_code' | 'process_name'>>;
    machines: Array<Pick<Machine, 'id' | 'machine_code' | 'machine_name'>>;
    uoms: string[];
}>();
</script>

<template>
    <AppLayout>
        <Head :title="'BOM — Form'" />
        <BackButton :href="route('boms.index')" class="mb-4" />
    </AppLayout>
</template>
