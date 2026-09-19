<script setup lang="ts">
import { computed } from 'vue';
import { toneClasses, toneFor, type Tone } from '@/utils/tone';

const props = withDefaults(
    defineProps<{
        /** Tone eksplisit; bila kosong, dipetakan dari prop `status`. */
        tone?: Tone;
        /** Kunci status (mis. 'completed') — dipetakan otomatis ke tone. */
        status?: string | null;
        /** Tampilkan huruf besar dengan tracking lebar. */
        uppercase?: boolean;
    }>(),
    {
        tone: undefined,
        status: null,
        uppercase: false,
    },
);

const resolvedTone = computed(() => props.tone ?? toneFor(props.status));
</script>

<template>
    <span
        :class="[
            toneClasses[resolvedTone],
            uppercase ? 'uppercase tracking-wide' : '',
            'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold',
        ]"
    >
        <slot />
    </span>
</template>
