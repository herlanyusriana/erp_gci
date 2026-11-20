import type { ArrivalType } from '@/types';

export interface ArrivalLike {
    arrival_type?: ArrivalType | string | null;
    is_local?: boolean | null;
    supplier?: { supplier_name?: string | null } | null;
    subcontractor?: { subcontractor_name?: string | null } | null;
    customer?: { customer_name?: string | null } | null;
}

export function resolveArrivalType(arrival: ArrivalLike | null | undefined): ArrivalType {
    const raw = arrival?.arrival_type;
    if (raw === 'import' || raw === 'local' || raw === 'osp_gci' || raw === 'osp_customer') {
        return raw;
    }

    return arrival?.is_local ? 'local' : 'import';
}

export function isOspType(type: ArrivalType): boolean {
    return type === 'osp_gci' || type === 'osp_customer';
}

export function arrivalTypeLabelKey(type: ArrivalType): string {
    return `incoming.arrivalType_${type}`;
}

export function arrivalTypeLabel(
    arrival: ArrivalLike | ArrivalType | null | undefined,
    t: (key: string) => string,
): string {
    const type: ArrivalType =
        typeof arrival === 'string' && (arrival === 'import' || arrival === 'local' || arrival === 'osp_gci' || arrival === 'osp_customer')
            ? arrival
            : resolveArrivalType(typeof arrival === 'object' ? arrival : null);

    switch (type) {
        case 'local':
            return t('incoming.arrivalType_local');
        case 'osp_gci':
            return t('incoming.arrivalType_osp_gci');
        case 'osp_customer':
            return t('incoming.arrivalType_osp_customer');
        default:
            return t('incoming.arrivalType_import');
    }
}

export function arrivalTypeBadgeClass(type: ArrivalType): string {
    switch (type) {
        case 'osp_gci':
            return 'bg-warning/15 text-warning-ink border border-warning/30';
        case 'osp_customer':
            return 'bg-success/15 text-success-ink border border-success/30';
        case 'local':
            return 'bg-info/15 text-info border border-info/30';
        default:
            return 'bg-primary-light text-primary border border-primary/20';
    }
}

export function arrivalSenderName(arrival: ArrivalLike | null | undefined): string {
    if (!arrival) {
        return '—';
    }

    const type = resolveArrivalType(arrival);
    if (type === 'osp_gci') {
        return arrival.subcontractor?.subcontractor_name || '—';
    }
    if (type === 'osp_customer') {
        return arrival.customer?.customer_name || '—';
    }

    return arrival.supplier?.supplier_name ?? arrival.subcontractor?.subcontractor_name ?? arrival.customer?.customer_name ?? '—';
}
