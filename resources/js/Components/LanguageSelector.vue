<script setup lang="ts">
import axios from 'axios';
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Dropdown from '@/Components/Dropdown.vue';
import { isSupportedLocale, setLocale } from '@/i18n';
import type { SupportedLocale } from '@/types';

const { t, locale } = useI18n();
const saving = ref(false);
const failed = ref(false);

const languages: Array<{ code: SupportedLocale; label: string }> = [
    { code: 'id', label: 'Indonesia' },
    { code: 'ko', label: '한국어' },
    { code: 'en', label: 'English' },
];

const current = computed(() => languages.find((l) => l.code === locale.value) ?? languages[0]);

async function changeLanguage(code: SupportedLocale) {
    if (!isSupportedLocale(code) || code === locale.value) return;
    saving.value = true;
    failed.value = false;
    try {
        const { data } = await axios.post(route('locale.update'), { locale: code });
        setLocale(data.locale);
        router.replace({
            props: (props) => ({ ...props, locale: data.locale }),
            preserveState: true,
            preserveScroll: true,
        });
    } catch {
        failed.value = true;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="max-w-full shrink-0">
        <Dropdown align="right" width="48">
            <template #trigger>
                <button
                    type="button"
                    :disabled="saving"
                    :aria-busy="saving"
                    :aria-label="t('common.language')"
                    class="inline-flex min-h-11 max-w-full items-center gap-2 rounded-md border border-borderline bg-surface px-2.5 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-60"
                >
                    <svg class="h-3.5 w-5 shrink-0 rounded-[2px] shadow-sm ring-1 ring-black/10" viewBox="0 0 20 14" aria-hidden="true">
                        <rect v-if="current.code === 'id'" width="20" height="14" fill="#fff" />
                        <path v-if="current.code === 'id'" d="M0 0h20v7H0z" fill="#E70011" />
                        <rect v-if="current.code === 'ko'" width="20" height="14" fill="#fff" />
                        <circle v-if="current.code === 'ko'" cx="10" cy="7" r="3.4" fill="#CD2E3A" />
                        <path v-if="current.code === 'ko'" d="M6.6 7a3.4 3.4 0 0 0 6.8 0z" fill="#0047A0" />
                        <rect v-if="current.code === 'en'" width="20" height="14" fill="#012169" />
                        <g v-if="current.code === 'en'">
                            <path d="M0 0l20 14M20 0L0 14" stroke="#fff" stroke-width="2.6" />
                            <path d="M10 0v14M0 7h20" stroke="#fff" stroke-width="4.6" />
                            <path d="M10 0v14M0 7h20" stroke="#C8102E" stroke-width="2.2" />
                            <path d="M0 0l20 14M20 0L0 14" stroke="#C8102E" stroke-width="1" />
                        </g>
                        <rect width="20" height="14" fill="none" stroke="rgba(0,0,0,0.12)" stroke-width="0.5" rx="1" />
                    </svg>
                    <span class="truncate">{{ current.label }}</span>
                    <svg class="h-4 w-4 shrink-0 text-ink-secondary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>
            </template>
            <template #content>
                <div role="menu" :aria-label="t('common.language')" class="py-1">
                    <button
                        v-for="l in languages"
                        :key="l.code"
                        type="button"
                        role="menuitemradio"
                        :aria-checked="l.code === locale"
                        class="flex w-full items-center gap-2.5 px-3 py-2 text-start text-sm text-ink-primary transition hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                        @click="changeLanguage(l.code)"
                    >
                        <svg class="h-3.5 w-5 shrink-0 rounded-[2px] shadow-sm" viewBox="0 0 20 14" aria-hidden="true">
                            <rect v-if="l.code === 'id'" width="20" height="14" fill="#fff" />
                            <path v-if="l.code === 'id'" d="M0 0h20v7H0z" fill="#E70011" />
                            <rect v-if="l.code === 'ko'" width="20" height="14" fill="#fff" />
                            <circle v-if="l.code === 'ko'" cx="10" cy="7" r="3.4" fill="#CD2E3A" />
                            <path v-if="l.code === 'ko'" d="M6.6 7a3.4 3.4 0 0 0 6.8 0z" fill="#0047A0" />
                            <rect v-if="l.code === 'en'" width="20" height="14" fill="#012169" />
                            <g v-if="l.code === 'en'">
                                <path d="M0 0l20 14M20 0L0 14" stroke="#fff" stroke-width="2.6" />
                                <path d="M10 0v14M0 7h20" stroke="#fff" stroke-width="4.6" />
                                <path d="M10 0v14M0 7h20" stroke="#C8102E" stroke-width="2.2" />
                                <path d="M0 0l20 14M20 0L0 14" stroke="#C8102E" stroke-width="1" />
                            </g>
                            <rect width="20" height="14" fill="none" stroke="rgba(0,0,0,0.12)" stroke-width="0.5" rx="1" />
                        </svg>
                        <span class="truncate">{{ l.label }}</span>
                        <svg v-if="l.code === locale" class="ms-auto h-4 w-4 shrink-0 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </button>
                </div>
            </template>
        </Dropdown>
        <span v-if="saving" class="sr-only" role="status">{{ t('common.changingLanguage') }}</span>
        <p v-if="failed" class="mt-1 max-w-48 text-xs text-danger" role="alert">{{ t('common.languageFailed') }}</p>
    </div>
</template>
