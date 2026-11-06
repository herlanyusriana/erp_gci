import { i18n } from '@/i18n';

/**
 * Format string tanggal (ISO string, YYYY-MM-DD, dsb.) ke format tanggal manusiawi
 * mengikuti locale aktif (mis. "30 Jul 2026" di ID, "Jul 30, 2026" di EN, "2026. 7. 30." di KO).
 */
export function formatDate(value: string | null | undefined, options?: Intl.DateTimeFormatOptions): string {
    if (!value) return '—';

    // Bersihkan format ISO seperti "2026-07-30T00:00:00.000000Z" menjadi bagian tanggal murni
    const raw = String(value).trim();
    const datePart = raw.includes('T') ? raw.split('T')[0] : (raw.includes(' ') ? raw.split(' ')[0] : raw);
    const parts = datePart.split('-');

    if (parts.length === 3) {
        const y = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10);
        const d = parseInt(parts[2], 10);
        if (!isNaN(y) && !isNaN(m) && !isNaN(d)) {
            // Gunakan UTC Date agar tidak bergeser tanggal karena timezone lokal browser
            const dateObj = new Date(Date.UTC(y, m - 1, d));
            const locale = (i18n.global.locale as any).value || 'id';
            return dateObj.toLocaleDateString(locale, {
                timeZone: 'UTC',
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                ...options,
            });
        }
    }

    const fallbackDate = new Date(raw);
    if (isNaN(fallbackDate.getTime())) return '—';

    const locale = (i18n.global.locale as any).value || 'id';
    return fallbackDate.toLocaleDateString(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        ...options,
    });
}

/**
 * Format tanggal beserta jam & menit (tanpa detik yang berisik), mis. "06 Nov 2026, 14:35".
 */
export function formatDateTime(value: string | null | undefined): string {
    if (!value) return '—';
    const raw = String(value).trim();
    const dateObj = new Date(raw);
    if (isNaN(dateObj.getTime())) return '—';

    const locale = (i18n.global.locale as any).value || 'id';
    const dateStr = dateObj.toLocaleDateString(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

    const timeStr = dateObj.toLocaleTimeString(locale, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    return `${dateStr}, ${timeStr}`;
}

/**
 * Format string jam/periode (misal membuang detik "09:30:00 - 10:30:00" -> "09:30 – 10:30").
 */
export function formatPeriod(value: string | null | undefined): string {
    if (!value) return '—';
    const clean = String(value).trim();
    if (clean.includes('-')) {
        const parts = clean.split('-');
        const start = parts[0].trim().slice(0, 5);
        const end = parts[1].trim().slice(0, 5);
        return `${start} – ${end}`;
    }
    return clean.slice(0, 5);
}
