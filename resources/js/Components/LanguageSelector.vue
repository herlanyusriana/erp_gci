<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { isSupportedLocale, setLocale } from '@/i18n';

const { t, locale } = useI18n();
const saving = ref(false);
const failed = ref(false);

async function changeLanguage(event: Event) {
    const select = event.target as HTMLSelectElement;
    const selected = select.value;
    if (!isSupportedLocale(selected) || selected === locale.value) return;
    saving.value = true;
    failed.value = false;
    try {
        const { data } = await axios.post(route('locale.update'), { locale: selected });
        setLocale(data.locale);
        router.replace({
            props: (props) => ({ ...props, locale: data.locale }),
            preserveState: true,
            preserveScroll: true,
        });
    } catch {
        select.value = locale.value;
        failed.value = true;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="relative max-w-full shrink-0">
        <label class="flex items-center gap-2 text-sm text-ink-secondary">
            <span class="sr-only">{{ t('common.language') }}</span>
            <select
                :value="locale"
                :disabled="saving"
                :aria-busy="saving"
                class="min-h-11 max-w-full rounded-md border-borderline bg-surface py-2 pl-3 pr-8 text-sm text-ink-primary focus:border-primary focus:ring-primary disabled:opacity-60"
                @change="changeLanguage"
            >
                <option value="id" lang="id">Indonesia</option>
                <option value="ko" lang="ko">한국어</option>
                <option value="en" lang="en">English</option>
            </select>
        </label>
        <span v-if="saving" class="sr-only" role="status">{{ t('common.changingLanguage') }}</span>
        <p v-if="failed" class="mt-1 max-w-48 text-xs text-danger" role="alert">{{ t('common.languageFailed') }}</p>
    </div>
</template>
