import { createI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import type { SupportedLocale } from '@/types';

const catalogs = import.meta.glob<{ default: Record<SupportedLocale, Record<string, any>> }>('./catalogs/*.ts', { eager: true });
const messages: Record<SupportedLocale, Record<string, any>> = { id: {}, en: {}, ko: {} };
for (const [path, module] of Object.entries(catalogs)) {
    const namespace = path.split('/').pop()!.replace(/\.ts$/, '');
    for (const locale of ['id', 'en', 'ko'] as const) messages[locale][namespace] = module.default[locale];
}

export const i18n = createI18n({ legacy: false, locale: 'id', fallbackLocale: 'id', messages });
export const isSupportedLocale = (value: unknown): value is SupportedLocale => value === 'id' || value === 'en' || value === 'ko';

export function setLocale(value: unknown) {
    const locale = isSupportedLocale(value) ? value : 'id';
    i18n.global.locale.value = locale;
    document.documentElement.lang = locale;
}

export function synchronizeLocale() {
    router.on('navigate', (event) => setLocale(event.detail.page.props.locale));
}
