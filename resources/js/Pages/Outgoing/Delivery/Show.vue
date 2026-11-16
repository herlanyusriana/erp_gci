<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { formatDate } from '@/utils/date';

const { t, locale } = useI18n();

interface Item {
    id: number;
    tag: string | null;
    qty: number;
    qty_unit: string | null;
    origin_location_code: string | null;
    price: number | null;
    total_price: number | null;
    part?: { id: number; part_number: string; part_name: string } | null;
}

interface Delivery {
    id: number;
    delivery_no: string;
    delivery_date: string;
    status: string;
    invoice_no: string | null;
    qc_status: string;
    qc_notes: string | null;
    qc_checked_at: string | null;
    truck_no: string | null;
    driver_name: string | null;
    notes: string | null;
    picked_at: string | null;
    loaded_at: string | null;
    released_at: string | null;
    customer?: { customer_code: string; customer_name: string; address: string | null } | null;
    items: Item[];
    scans?: Array<{ id: number; scan_type: string; code: string; scanned_at: string | null; scanner?: { name: string } | null }>;
}

interface LoadingItem {
    id: number;
    part_stock_id: number;
    tag: string;
    part_no: string | null;
    part_name: string | null;
    qty: number;
    uom: string | null;
    scanned: boolean;
}

interface LoadingContext {
    trolleys: string[];
    items: LoadingItem[];
    scanned_tags: number;
    total_tags: number;
    loading_complete: boolean;
}

interface TrolleyOption {
    code: string;
    kind: string | null;
    kind_label: string | null;
    location: string | null;
    capacity: number | null;
}

const props = defineProps<{
    delivery: Delivery;
    prepLocation: string | null;
    loading: LoadingContext;
    trolleys: TrolleyOption[];
    trolleyMasterEmpty: boolean;
}>();

const fmt = (n: number | null | undefined) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { maximumFractionDigits: 4 });

const money = (n: number | null | undefined) =>
    n == null ? '—' : Number(n).toLocaleString(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const tone = () =>
    props.delivery.status === 'released'
        ? 'success'
        : props.delivery.status === 'loaded'
          ? 'info'
          : props.delivery.status === 'qc_passed'
            ? 'info'
            : props.delivery.status === 'picked'
              ? 'warning'
              : 'neutral';

const STEPS = ['draft', 'picked', 'qc_passed', 'loaded', 'released'] as const;

const currentStep = computed(() => STEPS.indexOf(props.delivery.status as (typeof STEPS)[number]));
const isDone = (step: string) => currentStep.value >= STEPS.indexOf(step as (typeof STEPS)[number]);

const qcNotes = ref('');
const showReject = ref(false);
const scanCode = ref('');
const scanError = ref('');
const scanBusy = ref(false);
const selectedTrolley = ref('');

const trolleyOptions = computed(() =>
    props.trolleys.map((trolley) => ({
        value: trolley.code,
        label: trolley.code,
        sublabel: [trolley.kind_label, trolley.location].filter(Boolean).join(' · '),
    })),
);

const scanProgress = computed(() => {
    const total = props.loading?.total_tags ?? 0;
    const done = props.loading?.scanned_tags ?? 0;

    return { done, total, percent: total > 0 ? Math.round((done / total) * 100) : 0 };
});

function submitScan() {
    const code = scanCode.value.trim();
    if (code === '' || scanBusy.value) {
        return;
    }

    scanBusy.value = true;
    scanError.value = '';

    router.post(
        route('deliveries.scan', props.delivery.id),
        { code },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                scanCode.value = '';
            },
            onError: (errors) => {
                scanError.value = (errors as Record<string, string>).code ?? t('outgoing.scanFailed');
            },
            onFinish: () => {
                scanBusy.value = false;
            },
        },
    );
}

function scanSelectedTrolley() {
    if (selectedTrolley.value === '' || scanBusy.value) {
        return;
    }

    scanBusy.value = true;
    scanError.value = '';

    router.post(
        route('deliveries.scan', props.delivery.id),
        { code: selectedTrolley.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                selectedTrolley.value = '';
            },
            onError: (errors) => {
                scanError.value = (errors as Record<string, string>).code ?? t('outgoing.scanFailed');
            },
            onFinish: () => {
                scanBusy.value = false;
            },
        },
    );
}

function removeScan(scanId: number) {
    router.delete(route('deliveries.scans.destroy', { delivery: props.delivery.id, scan: scanId }), {
        preserveScroll: true,
    });
}

function pick() {
    if (confirm(t('outgoing.pickConfirm', { no: props.delivery.delivery_no }))) {
        router.post(route('deliveries.pick', props.delivery.id), {}, { preserveScroll: true });
    }
}

function returnToOrigin() {
    if (confirm(t('outgoing.returnConfirm'))) {
        router.post(route('deliveries.return', props.delivery.id), {}, { preserveScroll: true });
    }
}

function passQc() {
    router.post(route('deliveries.qc.pass', props.delivery.id), { qc_notes: qcNotes.value || null }, { preserveScroll: true });
}

function rejectQc() {
    if (!qcNotes.value.trim()) {
        return;
    }
    router.post(route('deliveries.qc.reject', props.delivery.id), { qc_notes: qcNotes.value }, {
        preserveScroll: true,
        onSuccess: () => {
            showReject.value = false;
            qcNotes.value = '';
        },
    });
}

function releaseLabel() {
    if (confirm(t('outgoing.releaseLabelConfirm'))) {
        router.post(route('deliveries.release-label', props.delivery.id), {}, { preserveScroll: true });
    }
}

function releaseDocuments() {
    if (confirm(t('outgoing.releaseDocsConfirm'))) {
        router.post(route('deliveries.release-documents', props.delivery.id), {}, { preserveScroll: true });
    }
}

const totalAmount = computed(() => props.delivery.items.reduce((sum, item) => sum + (item.total_price ?? 0), 0));
</script>

<template>
    <Head :title="delivery.delivery_no" />
    <AppLayout>
        <BackButton :href="route('deliveries.index')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ delivery.delivery_no }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-ink-secondary">
                    <span>{{ delivery.customer?.customer_name ?? '—' }}</span>
                    <span class="text-xs">({{ delivery.customer?.customer_code ?? '—' }})</span>
                    <span>{{ formatDate(delivery.delivery_date) }}</span>
                    <StatusBadge :tone="tone()" uppercase>{{ t(`outgoing.status_${delivery.status}`) }}</StatusBadge>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    v-if="delivery.status !== 'draft' && delivery.status !== 'released'"
                    :href="route('deliveries.label', delivery.id)"
                    target="_blank"
                    rel="noopener"
                    class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                >
                    {{ t('outgoing.printLabel') }}
                </Link>
                <template v-if="delivery.status === 'released'">
                    <Link
                        :href="route('deliveries.print', delivery.id)"
                        target="_blank"
                        rel="noopener"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        {{ t('outgoing.printSj') }}
                    </Link>
                    <Link
                        :href="route('deliveries.invoice', delivery.id)"
                        target="_blank"
                        rel="noopener"
                        class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        {{ t('outgoing.printInvoice') }}
                    </Link>
                    <Link
                        :href="route('deliveries.packing-list', delivery.id)"
                        target="_blank"
                        rel="noopener"
                        class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        {{ t('outgoing.printPackingList') }}
                    </Link>
                </template>
                <Link
                    v-if="delivery.status === 'draft'"
                    :href="route('deliveries.edit', delivery.id)"
                    class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                >
                    {{ t('outgoing.edit') }}
                </Link>
            </div>
        </div>

        <!-- Tahapan pengiriman -->
        <ol class="mb-6 grid gap-2 sm:grid-cols-5">
            <li
                v-for="(step, index) in STEPS"
                :key="step"
                class="rounded-xl border px-3 py-3 transition"
                :class="isDone(step) ? 'border-primary/40 bg-primary-light/50' : 'border-borderline bg-surface'"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                        :class="isDone(step) ? 'bg-primary text-white' : 'bg-background text-ink-secondary'"
                    >{{ index + 1 }}</span>
                    <span class="text-xs font-semibold" :class="isDone(step) ? 'text-primary' : 'text-ink-secondary'">
                        {{ t(`outgoing.step_${step}`) }}
                    </span>
                </div>
                <p class="mt-1 text-[11px] leading-snug text-ink-secondary">{{ t(`outgoing.stepHint_${step}`) }}</p>
            </li>
        </ol>

        <!-- Panel aksi sesuai tahap -->
        <div class="mb-6 rounded-xl border border-borderline bg-surface p-5">
            <!-- Tahap 1: DO -->
            <template v-if="delivery.status === 'draft'">
                <h2 class="text-sm font-bold text-ink-primary">{{ t('outgoing.step_draft') }}</h2>
                <p class="mt-1 text-xs text-ink-secondary">{{ t('outgoing.actionDraftHint') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        @click="pick"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        {{ t('outgoing.pick') }}
                    </button>
                    <span v-if="prepLocation" class="self-center text-xs text-ink-secondary">
                        {{ t('outgoing.prepLocation') }}: <span class="font-mono font-semibold text-ink-primary">{{ prepLocation }}</span>
                    </span>
                </div>
                <p v-if="delivery.qc_status === 'rejected'" class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-xs font-medium text-danger">
                    {{ t('outgoing.qcRejectedNote') }}: {{ delivery.qc_notes ?? '—' }}
                </p>
            </template>

            <!-- Tahap 2: sudah di prep, tunggu QC -->
            <template v-else-if="delivery.status === 'picked'">
                <h2 class="text-sm font-bold text-ink-primary">{{ t('outgoing.qcTitle') }}</h2>
                <p class="mt-1 text-xs text-ink-secondary">{{ t('outgoing.qcHint') }}</p>
                <div class="mt-3 max-w-xl">
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.qcNotes') }}</label>
                    <textarea
                        v-model="qcNotes"
                        rows="2"
                        :aria-label="t('outgoing.qcNotes')"
                        :placeholder="t('outgoing.qcNotesHint')"
                        class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                    />
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        @click="passQc"
                        class="rounded-md bg-success px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-success focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        {{ t('outgoing.qcPass') }}
                    </button>
                    <button
                        type="button"
                        @click="showReject = !showReject"
                        class="rounded-md border border-danger px-4 py-2 text-sm font-semibold text-danger transition hover:bg-danger/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        {{ t('outgoing.qcReject') }}
                    </button>
                    <button
                        type="button"
                        @click="returnToOrigin"
                        class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        {{ t('outgoing.returnToOrigin') }}
                    </button>
                </div>
                <div v-if="showReject" class="mt-3 rounded-lg border border-danger/40 bg-danger/5 p-3">
                    <p class="text-xs font-semibold text-danger">{{ t('outgoing.qcRejectHint') }}</p>
                    <button
                        type="button"
                        @click="rejectQc"
                        :disabled="!qcNotes.trim()"
                        class="mt-2 rounded-md bg-danger px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        {{ t('outgoing.qcRejectConfirm') }}
                    </button>
                </div>
            </template>

            <!-- Tahap 4: QC lolos, scan troli + label FG, lalu rilis label -->
            <template v-else-if="delivery.status === 'qc_passed'">
                <h2 class="text-sm font-bold text-ink-primary">{{ t('outgoing.loadingTitle') }}</h2>
                <p class="mt-1 text-xs text-ink-secondary">{{ t('outgoing.loadingHint') }}</p>

                <!-- Progres -->
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-borderline bg-background/60 px-3 py-2">
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.trolleyScanned') }}</div>
                        <div class="mt-0.5 text-lg font-bold tabular-nums text-ink-primary">{{ loading.trolleys.length }}</div>
                    </div>
                    <div class="rounded-lg border border-borderline bg-background/60 px-3 py-2">
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.tagScanned') }}</div>
                        <div class="mt-0.5 text-lg font-bold tabular-nums text-ink-primary">
                            {{ scanProgress.done }}<span class="text-sm font-normal text-ink-secondary"> / {{ scanProgress.total }}</span>
                        </div>
                    </div>
                    <div class="rounded-lg border border-borderline bg-background/60 px-3 py-2">
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.loadingState') }}</div>
                        <div class="mt-0.5 text-sm font-bold" :class="loading.loading_complete ? 'text-success' : 'text-warning-ink'">
                            {{ loading.loading_complete ? t('outgoing.loadingComplete') : t('outgoing.loadingPending') }}
                        </div>
                    </div>
                </div>

                <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-background">
                    <div class="h-full rounded-full bg-primary" :style="{ width: scanProgress.percent + '%' }"></div>
                </div>

                <!-- Scan manual dari web -->
                <form class="mt-4 flex flex-wrap items-end gap-2" @submit.prevent="submitScan">
                    <div class="min-w-64 flex-1">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.scanCode') }}</label>
                        <input
                            v-model="scanCode"
                            type="text"
                            :aria-label="t('outgoing.scanCode')"
                            :placeholder="t('outgoing.scanPlaceholder')"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 font-mono text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <p v-if="scanError" class="mt-1 text-xs font-medium text-danger">{{ scanError }}</p>
                    </div>
                    <button
                        type="submit"
                        :disabled="scanBusy || scanCode.trim() === ''"
                        class="rounded-md border border-primary px-4 py-2 text-sm font-semibold text-primary transition hover:bg-primary-light disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        {{ scanBusy ? t('outgoing.scanning') : t('outgoing.scan') }}
                    </button>
                </form>
                <p class="mt-1 text-[11px] text-ink-secondary">{{ t('outgoing.scanWebHint') }}</p>

                <!-- Pilih troli dari master aplikasi Trolley Scan -->
                <div class="mt-4 border-t border-borderline pt-4">
                    <p v-if="trolleyMasterEmpty" class="mb-2 rounded-lg bg-warning/10 px-3 py-2 text-xs font-medium text-warning-ink">
                        {{ t('outgoing.trolleyMasterEmpty') }}
                    </p>
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="min-w-56 flex-1">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.pickTrolley') }}</label>
                            <SearchableSelect
                                v-model="selectedTrolley"
                                :options="trolleyOptions"
                                value-key="value"
                                label-key="label"
                                sublabel-key="sublabel"
                                :placeholder="t('outgoing.chooseTrolley')"
                                :aria-label="t('outgoing.pickTrolley')"
                                :disabled="!trolleys.length"
                            />
                        </div>
                        <button
                            type="button"
                            @click="scanSelectedTrolley"
                            :disabled="selectedTrolley === '' || scanBusy"
                            class="rounded-md border border-primary px-4 py-2 text-sm font-semibold text-primary transition hover:bg-primary-light disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                        >
                            {{ t('outgoing.useTrolley') }}
                        </button>
                    </div>
                    <p class="mt-1 text-[11px] text-ink-secondary">{{ t('outgoing.trolleyMasterHint') }}</p>
                </div>

                <!-- Daftar scan -->
                <div v-if="delivery.scans?.length" class="mt-4 border-t border-borderline pt-3">
                    <div class="mb-2 text-xs font-semibold text-ink-secondary">{{ t('outgoing.scanHistory') }}</div>
                    <ul class="flex flex-wrap gap-1.5">
                        <li
                            v-for="scan in delivery.scans"
                            :key="scan.id"
                            class="inline-flex items-center gap-1.5 rounded-md border border-borderline bg-background px-2 py-1 text-xs"
                        >
                            <span class="font-mono text-ink-primary">{{ scan.code }}</span>
                            <span class="text-[10px] uppercase text-ink-secondary">{{ scan.scan_type }}</span>
                            <button
                                type="button"
                                @click="removeScan(scan.id)"
                                class="text-danger transition hover:opacity-70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                :aria-label="t('outgoing.removeScan')"
                            >✕</button>
                        </li>
                    </ul>
                </div>

                <div class="mt-4 flex flex-wrap gap-2 border-t border-borderline pt-4">
                    <button
                        type="button"
                        @click="releaseLabel"
                        :disabled="!loading.loading_complete"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        {{ t('outgoing.releaseLabel') }}
                    </button>
                    <p v-if="!loading.loading_complete" class="self-center text-xs text-ink-secondary">
                        {{ t('outgoing.loadingBlocked') }}
                    </p>
                </div>
            </template>

            <!-- Tahap 4: label rilis, loading -->
            <template v-else-if="delivery.status === 'loaded'">
                <h2 class="text-sm font-bold text-ink-primary">{{ t('outgoing.step_released') }}</h2>
                <p class="mt-1 text-xs text-ink-secondary">{{ t('outgoing.actionDocsHint') }}</p>
                <p v-if="!delivery.invoice_no" class="mt-2 rounded-lg bg-warning/10 px-3 py-2 text-xs font-medium text-warning-ink">
                    {{ t('outgoing.invoiceMissing') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        @click="releaseDocuments"
                        :disabled="!delivery.invoice_no"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        {{ t('outgoing.releaseDocs') }}
                    </button>
                    <Link
                        v-if="delivery.invoice_no === null || delivery.invoice_no === ''"
                        :href="route('deliveries.edit', delivery.id)"
                        class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    >
                        {{ t('outgoing.fillInvoice') }}
                    </Link>
                </div>
            </template>

            <!-- Tahap 5: selesai -->
            <template v-else>
                <h2 class="text-sm font-bold text-success">{{ t('outgoing.docsReleased') }}</h2>
                <p class="mt-1 text-xs text-ink-secondary">{{ t('outgoing.docsReleasedHint') }}</p>
            </template>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.customer') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ delivery.customer?.customer_name ?? '—' }}</div>
                <div class="text-xs text-ink-secondary">{{ delivery.customer?.address ?? '' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.invoiceNo') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ delivery.invoice_no ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.truckNo') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ delivery.truck_no ?? '—' }}</div>
                <div class="text-xs text-ink-secondary">{{ delivery.driver_name ?? '' }}</div>
            </div>
            <div class="rounded-xl border border-borderline bg-surface p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.qcStatus') }}</div>
                <div class="mt-1 font-semibold text-ink-primary">{{ t(`outgoing.qc_${delivery.qc_status}`) }}</div>
                <div class="text-xs text-ink-secondary">{{ delivery.qc_checked_at ? formatDate(delivery.qc_checked_at) : '' }}</div>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.part') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.tag') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('outgoing.qty') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('outgoing.deliveryPrice') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('outgoing.amount') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.origin') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="item in delivery.items" :key="item.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3">
                                <div class="font-medium text-ink-primary">{{ item.part?.part_number ?? '—' }}</div>
                                <div class="text-xs text-ink-secondary">{{ item.part?.part_name ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 font-mono text-ink-primary">{{ item.tag ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold text-ink-primary">
                                {{ fmt(item.qty) }} <span class="text-xs font-normal text-ink-secondary">{{ item.qty_unit ?? '' }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ money(item.price) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-primary">{{ money(item.total_price) }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-ink-secondary">{{ item.origin_location_code ?? '—' }}</td>
                        </tr>
                        <tr v-if="delivery.items.length === 0">
                            <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('outgoing.deliveryNoItems') }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="delivery.items.length">
                        <tr class="border-t-2 border-borderline">
                            <td colspan="4" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('outgoing.totalAmount') }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-bold text-ink-primary">{{ money(totalAmount) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <p v-if="delivery.notes" class="mt-4 text-sm text-ink-secondary">{{ t('outgoing.notes') }}: {{ delivery.notes }}</p>
    </AppLayout>
</template>