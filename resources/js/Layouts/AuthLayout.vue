<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Link, usePage } from '@inertiajs/vue3';
import LanguageSelector from '@/Components/LanguageSelector.vue';

const { t } = useI18n();
const page = usePage();
</script>

<template>
    <div class="flex min-h-screen bg-background">
        <!-- Brand panel (desktop only) -->
        <aside class="relative hidden w-1/2 overflow-hidden bg-primary lg:flex lg:flex-col lg:justify-between xl:w-[55%]">
            <!-- dekorasi halus -->
            <div class="pointer-events-none absolute inset-0 opacity-[0.07]" aria-hidden="true">
                <svg class="h-full w-full" viewBox="0 0 400 400" preserveAspectRatio="xMidYMid slice">
                    <defs>
                        <pattern id="auth-grid" width="40" height="40" patternUnits="userSpaceOnUse">
                            <path d="M40 0H0V40" fill="none" stroke="white" stroke-width="1" />
                        </pattern>
                    </defs>
                    <rect width="400" height="400" fill="url(#auth-grid)" />
                </svg>
            </div>
            <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-white/5 blur-3xl" aria-hidden="true" />
            <div class="pointer-events-none absolute -bottom-32 -left-16 h-80 w-80 rounded-full bg-white/5 blur-3xl" aria-hidden="true" />

            <div class="relative z-10 p-10 xl:p-14">
                <img src="/images/logo-white.png" alt="Geum Cheon" class="h-9 w-auto" />
            </div>

            <div class="relative z-10 px-10 pb-14 xl:px-14">
                <h1 class="max-w-md text-3xl font-bold leading-tight text-white xl:text-4xl">
                    {{ t('auth.brandHeadline') }}
                </h1>
                <p class="mt-4 max-w-md text-sm leading-relaxed text-white/70">
                    {{ t('auth.brandSubheadline') }}
                </p>

                <ul class="mt-8 space-y-3">
                    <li v-for="key in ['modules', 'incoming', 'production']" :key="key" class="flex items-start gap-3 text-sm text-white/80">
                        <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10">
                            <svg class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        </span>
                        {{ t(`auth.feature_${key}`) }}
                    </li>
                </ul>
            </div>

            <div class="relative z-10 border-t border-white/10 px-10 py-5 xl:px-14">
                <p class="text-xs text-white/50">
                    {{ t('auth.copyright', { year: new Date().getFullYear() }) }}
                </p>
            </div>
        </aside>

        <!-- Form panel -->
        <main class="flex w-full flex-col lg:w-1/2 xl:w-[45%]">
            <!-- mobile brand header -->
            <div class="flex items-center justify-between border-b border-borderline bg-surface px-5 py-4 lg:hidden">
                <img src="/images/logo.png" alt="Geum Cheon" class="h-7 w-auto" />
                <LanguageSelector />
            </div>

            <div class="flex flex-1 items-center justify-center px-5 py-10 sm:px-8">
                <div class="w-full max-w-sm">
                    <!-- desktop language selector -->
                    <div class="mb-8 hidden justify-end lg:flex">
                        <LanguageSelector />
                    </div>

                    <slot />
                </div>
            </div>

            <div class="border-t border-borderline px-5 py-4 text-center lg:hidden">
                <p class="text-xs text-ink-secondary">
                    {{ t('auth.copyright', { year: new Date().getFullYear() }) }}
                </p>
            </div>
        </main>
    </div>
</template>
