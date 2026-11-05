import { usePage } from '@inertiajs/vue3';

/**
 * Helper hak akses frontend untuk menyembunyikan tile launcher.
 *
 * Hanya bersifat tampilan: otorisasi sebenarnya tetap di middleware/controller
 * backend. Semua role hasil `viewAll` di seeder memegang `*.view`, jadi gerbang
 * administratif TIDAK boleh memakai `role.view`/`user.view` — pakai izin mutasi
 * (`role.update`, `user.update`, `config.update`) atau role admin eksplisit.
 */
export const ADMIN_ROLES = ['super-admin', 'it-admin'] as const;

function authProps(): { roles: string[]; permissions: string[] } {
    const auth = usePage().props.auth as { roles?: string[]; permissions?: string[] } | undefined;

    return {
        roles: auth?.roles ?? [],
        permissions: auth?.permissions ?? [],
    };
}

/** True bila user memegang salah satu role yang diminta. */
export function hasAnyRole(required: readonly string[]): boolean {
    if (required.length === 0) {
        return true;
    }

    const { roles } = authProps();

    return required.some((role) => roles.includes(role));
}

/** True bila user memegang salah satu permission yang diminta. */
export function hasAnyPermission(required: readonly string[] | string): boolean {
    const list = typeof required === 'string' ? [required] : required;

    if (list.length === 0) {
        return true;
    }

    const { permissions } = authProps();

    return list.some((perm) => permissions.includes(perm));
}

/** True bila user adalah admin sistem (role admin atau izin mutasi admin). */
export function isSystemAdmin(): boolean {
    return hasAnyRole(ADMIN_ROLES) || hasAnyPermission(['role.update', 'user.update', 'config.update']);
}