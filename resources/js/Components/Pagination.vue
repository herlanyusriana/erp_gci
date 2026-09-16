<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    links: Array<{ url: string | null; label: string; active: boolean }>;
}>();
</script>

<template>
    <nav v-if="links.length > 3" class="mt-4 flex items-center justify-between">
        <div class="flex flex-1 justify-between sm:hidden">
            <Link
                v-for="(link, i) in links"
                :key="i"
                :href="link.url ?? '#'"
                :class="[
                    'relative inline-flex items-center rounded-md border px-4 py-2 text-sm font-medium',
                    link.active
                        ? 'border-primary bg-primary text-white'
                        : link.url
                          ? 'border-borderline bg-surface text-ink-primary hover:bg-primary-light'
                          : 'border-borderline bg-background text-ink-secondary opacity-60',
                ]"
                :aria-disabled="!link.url"
            >
                <span v-html="link.label.replace(/&laquo;|&raquo;|&nbsp;/g, (m) => ({'&laquo;':'«','&raquo;':'»','&nbsp;':' '}[m] ?? m))" />
            </Link>
        </div>
        <div class="hidden flex-1 items-center justify-between sm:flex">
            <div class="text-sm text-ink-secondary">
                Halaman
                <span class="font-medium text-ink-primary">{{
                    links.find((l) => l.active)?.label ?? '1'
                }}</span>
            </div>
            <div class="flex gap-1">
                <Link
                    v-for="(link, i) in links"
                    :key="i"
                    :href="link.url ?? '#'"
                    :class="[
                        'inline-flex min-w-[2.25rem] items-center justify-center rounded-md border px-3 py-2 text-sm font-medium transition',
                        link.active
                            ? 'border-primary bg-primary text-white'
                            : link.url
                              ? 'border-borderline bg-surface text-ink-primary hover:bg-primary-light'
                              : 'border-borderline bg-background text-ink-secondary opacity-60',
                    ]"
                    :aria-disabled="!link.url"
                >
                    <span
                        v-if="link.label.includes('&laquo;') || link.label.includes('&raquo;')"
                        v-html="link.label.replace(/&laquo;|&raquo;/g, (m) => (m === '&laquo;' ? '«' : '»'))"
                    />
                    <span v-else>{{ link.label }}</span>
                </Link>
            </div>
        </div>
    </nav>
</template>