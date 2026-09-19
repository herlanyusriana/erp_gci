export type Tone = 'info' | 'warning' | 'success' | 'danger' | 'neutral' | 'primary';

/** Kelas badge: latar lembut + teks tegas, seluruhnya dari token tema. */
export const toneClasses: Record<Tone, string> = {
    info: 'bg-info/10 text-info',
    warning: 'bg-warning/10 text-warning',
    success: 'bg-success/10 text-success',
    danger: 'bg-danger/10 text-danger',
    neutral: 'bg-ink-secondary/10 text-ink-secondary',
    primary: 'bg-primary-light text-primary',
};

/**
 * Peta tunggal status/sumber → tone. Sebelumnya logika ini disalin di
 * ~9 halaman (statusTone/statusBadge/sourceBadge); sekarang satu sumber.
 */
const tones: Record<string, Tone> = {
    // status transaksi
    active: 'success',
    inactive: 'neutral',
    draft: 'neutral',
    pending: 'warning',
    planned: 'info',
    confirmed: 'info',
    received: 'success',
    completed: 'success',
    in_progress: 'warning',
    cancelled: 'danger',
    canceled: 'danger',
    // sumber / kode khusus
    prod: 'primary',
    vendor: 'warning',
    subcon: 'info',
    free_issue: 'success',
};

/** Petakan status/kunci ke tone tema; kunci tak dikenal → netral. */
export function toneFor(key?: string | null): Tone {
    if (!key) {
        return 'neutral';
    }

    return tones[String(key).toLowerCase()] ?? 'neutral';
}
