<script setup lang="ts">
import { ref, watch } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import LanguageSelector from '@/Components/LanguageSelector.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const page = usePage();
const flashVisible = ref(false);

watch(
    () => (page.props as any).flash,
    (flash) => {
        if (flash?.success || flash?.error) {
            flashVisible.value = true;
            setTimeout(() => (flashVisible.value = false), 4000);
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="min-h-screen bg-background">
        <!-- Topbar (spec 0.7) -->
        <nav class="border-b border-borderline bg-surface">
            <div class="mx-auto flex min-h-16 max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-2 sm:px-6 lg:px-8">
                <div class="flex items-center gap-3">
                    <Link
                        :href="route('launcher')"
                        class="flex items-center gap-2 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    >
                        <img src="/images/logo-mark.png" alt="" class="h-8 w-auto" aria-hidden="true" />
                        <span class="text-base font-semibold text-ink-primary sm:text-lg">
                            {{ $page.props.appSettings.applicationName ?? 'Geum Cheon ERP' }}
                        </span>
                    </Link>
                </div>

                <div class="flex max-w-full flex-wrap items-center gap-2">
                    <LanguageSelector />
                    <Link
                        :href="route('launcher')"
                        class="hidden rounded-md px-3 py-2 text-sm font-medium text-ink-secondary transition hover:bg-primary-light hover:text-primary sm:block"
                    >
                        {{ t('common.launcher') }}
                    </Link>

                    <!-- User dropdown -->
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button
                                type="button"
                                class="inline-flex max-w-48 items-center rounded-md border border-transparent bg-surface px-3 py-2 text-sm font-medium leading-4 text-ink-primary transition hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            >
                                <span class="truncate">{{ $page.props.auth.user.name }}</span>
                                <svg class="-me-0.5 ms-2 h-4 w-4 text-ink-secondary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </template>
                        <template #content>
                            <DropdownLink :href="route('profile.edit')">{{ t('common.profile') }}</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">{{ t('common.logout') }}</DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </div>
        </nav>

        <!-- Flash toast -->
        <div v-if="flashVisible && (($page.props as any).flash?.success || ($page.props as any).flash?.error)" class="fixed left-4 right-4 top-24 z-50 sm:left-auto sm:right-6 sm:max-w-lg" role="status">
            <div
                :class="($page.props as any).flash?.success ? 'border-success/30 bg-success/10 text-success' : 'border-danger/30 bg-danger/10 text-danger'"
                class="rounded-lg border px-4 py-3 text-sm font-medium shadow-lg"
            >
                {{ ($page.props as any).flash?.success ?? ($page.props as any).flash?.error }}
            </div>
        </div>

        <!-- Page content -->
        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <slot />
        </main>
    </div>
</template>