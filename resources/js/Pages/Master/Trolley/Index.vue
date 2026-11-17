<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { hasAnyPermission } from '@/utils/permissions';

const { t } = useI18n();

interface Trolley {
    id: number;
    external_id: number | null;
    code: string;
    type: string;
    kind: string | null;
    capacity: number | null;
    location: string | null;
    notes: string | null;
    is_active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

const props = defineProps<{
    trolleys: Paginated<Trolley>;
    filters: { search?: string; kind?: string; type?: string; status?: string };
    types: string[];
    kinds: Array<{ value: string; label: string }>;
    total: number;
    activeTotal: number;
}>();

const canCreate = computed(() => hasAnyPermission(['trolley.create']));
const canUpdate = computed(() => hasAnyPermission(['trolley.update']));
const canDelete = computed(() => hasAnyPermission(['trolley.delete']));

const search = ref(props.filters.search ?? '');
const kind = ref(props.filters.kind ?? '');
const type = ref(props.filters.type ?? '');
const status = ref(props.filters.status ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;
function applyFilters() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('trolleys.index'),
            {
                search: search.value || undefined,
                kind: kind.value || undefined,
                type: type.value || undefined,
                status: status.value || undefined,
            },
            { preserveState: true, replace: true },
        );
    }, 250);
}

const form = useForm({
    code: '',
    type: 'external',
    kind: '' as string,
    capacity: '' as number | '',
    location: '',
    notes: '',
    is_active: true,
});

const showForm = ref(false);
const editing = ref<Trolley | null>(null);

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.type = 'external';
    form.is_active = true;
    showForm.value = true;
}

function openEdit(trolley: Trolley) {
    editing.value = trolley;
    form.clearErrors();
    form.code = trolley.code;
    form.type = trolley.type || 'external';
    form.kind = trolley.kind ?? '';
    form.capacity = trolley.capacity ?? '';
    form.location = trolley.location ?? '';
    form.notes = trolley.notes ?? '';
    form.is_active = trolley.is_active;
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    editing.value = null;
}

function submit() {
    if (editing.value) {
        form.put(route('trolleys.update', editing.value.id), { onSuccess: () => closeForm() });
        return;
    }

    form.post(route('trolleys.store'), { onSuccess: () => closeForm() });
}

function remove(trolley: Trolley) {
    if (!confirm(t('trolley.deleteConfirm', { code: trolley.code }))) {
        return;
    }

    router.delete(route('trolleys.destroy', trolley.id));
}

const kindLabel = (value: string | null) =>
    value ? (props.kinds.find((k) => k.value === value)?.label ?? value) : '—';

// --- Impor CSV --------------------------------------------------------------

const showImport = ref(false);
const importForm = useForm<{ file: File | null }>({ file: null });

function openImport() {
    importForm.reset();
    importForm.clearErrors();
    showImport.value = true;
}

function submitImport() {
    importForm.post(route('trolleys.import'), {
        forceFormData: true,
        onSuccess: () => {
            showImport.value = false;
            importForm.reset();
        },
    });
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('trolley.title') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('trolley.subtitle') }}</p>
                <p class="mt-1 text-xs text-ink-secondary">
                    {{ t('trolley.total', { count: total }) }} · {{ t('trolley.activeTotal', { count: activeTotal }) }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    v-if="canCreate"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border border-borderline bg-surface px-3 py-2 text-sm font-semibold text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    @click="openImport"
                >
                    {{ t('trolley.import') }}
                </button>
                <button
                    v-if="canCreate"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                    @click="openCreate"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    {{ t('trolley.add') }}
                </button>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-borderline bg-surface p-3">
            <div class="w-full sm:w-64">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('trolley.search') }}</label>
                <input
                    v-model="search"
                    type="search"
                    :aria-label="t('trolley.search')"
                    class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                    @input="applyFilters"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('trolley.type') }}</label>
                <select v-model="type" :aria-label="t('trolley.type')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" @change="applyFilters">
                    <option value="">{{ t('trolley.allTypes') }}</option>
                    <option v-for="value in types" :key="value" :value="value">{{ value }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('trolley.kind') }}</label>
                <select v-model="kind" :aria-label="t('trolley.kind')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" @change="applyFilters">
                    <option value="">{{ t('trolley.allKinds') }}</option>
                    <option v-for="option in kinds" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('trolley.status') }}</label>
                <select v-model="status" :aria-label="t('trolley.status')" class="rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" @change="applyFilters">
                    <option value="">{{ t('trolley.allStatuses') }}</option>
                    <option value="active">{{ t('trolley.active') }}</option>
                    <option value="inactive">{{ t('trolley.inactive') }}</option>
                </select>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('trolley.code') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('trolley.type') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('trolley.kind') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('trolley.capacity') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('trolley.location') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('trolley.status') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('trolley.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="trolley in trolleys.data" :key="trolley.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3 font-mono font-semibold text-ink-primary">{{ trolley.code }}</td>
                            <td class="px-4 py-3 text-ink-secondary">{{ trolley.type ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-primary">{{ kindLabel(trolley.kind) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-secondary">{{ trolley.capacity ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-secondary">{{ trolley.location ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <StatusBadge :tone="trolley.is_active ? 'success' : 'warning'">
                                    {{ trolley.is_active ? t('trolley.active') : t('trolley.inactive') }}
                                </StatusBadge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <ActionButton v-if="canUpdate" :label="t('trolley.edit')" variant="edit" @click="openEdit(trolley)" />
                                    <ActionButton v-if="canDelete" :label="t('trolley.delete')" variant="delete" @click="remove(trolley)" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="trolleys.data.length === 0">
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('trolley.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Pagination :links="trolleys.links" />

        <p class="mt-3 text-xs text-ink-secondary">{{ t('trolley.qrHint') }}</p>

        <!-- Form tambah/ubah -->
        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">
                    {{ editing ? t('trolley.editTitle', { code: editing.code }) : t('trolley.addTitle') }}
                </h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="trolley-code">{{ t('trolley.code') }}</label>
                        <input
                            id="trolley-code"
                            v-model="form.code"
                            type="text"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 font-mono text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <p class="mt-1 text-xs text-ink-secondary">{{ t('trolley.codeHint') }}</p>
                        <InputError :message="form.errors.code" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="trolley-type">{{ t('trolley.type') }}</label>
                        <select
                            id="trolley-type"
                            v-model="form.type"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        >
                            <option v-for="value in types" :key="value" :value="value">{{ value }}</option>
                        </select>
                        <p class="mt-1 text-xs text-ink-secondary">{{ t('trolley.typeHint') }}</p>
                        <InputError :message="form.errors.type" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="trolley-kind">{{ t('trolley.kind') }}</label>
                        <select
                            id="trolley-kind"
                            v-model="form.kind"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        >
                            <option value="">—</option>
                            <option v-for="option in kinds" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <p class="mt-1 text-xs text-ink-secondary">{{ t('trolley.kindHint') }}</p>
                        <InputError :message="form.errors.kind" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="trolley-capacity">{{ t('trolley.capacity') }}</label>
                        <input
                            id="trolley-capacity"
                            v-model="form.capacity"
                            type="number"
                            min="0"
                            step="1"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-right text-sm tabular-nums text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.capacity" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="trolley-location">{{ t('trolley.location') }}</label>
                        <input
                            id="trolley-location"
                            v-model="form.location"
                            type="text"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.location" class="mt-1" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="trolley-notes">{{ t('trolley.notes') }}</label>
                        <input
                            id="trolley-notes"
                            v-model="form.notes"
                            type="text"
                            class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <InputError :message="form.errors.notes" class="mt-1" />
                    </div>
                </div>

                <label class="mt-4 flex items-start gap-2 text-sm text-ink-primary">
                    <input v-model="form.is_active" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-borderline text-primary focus:ring-primary" />
                    <span>
                        {{ t('trolley.active') }}
                        <span class="block text-xs text-ink-secondary">{{ t('trolley.inactiveHint') }}</span>
                    </span>
                </label>
                <InputError :message="form.errors.is_active" class="mt-1" />

                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        @click="closeForm"
                    >
                        {{ t('trolley.cancel') }}
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface disabled:opacity-60"
                    >
                        {{ form.processing ? t('trolley.saving') : t('trolley.save') }}
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Impor CSV -->
        <Modal :show="showImport" max-width="md" @close="showImport = false">
            <form class="p-6" @submit.prevent="submitImport">
                <h2 class="mb-2 text-base font-semibold text-ink-primary">{{ t('trolley.importTitle') }}</h2>
                <p class="mb-4 text-sm text-ink-secondary">{{ t('trolley.importHint') }}</p>

                <label class="text-xs font-semibold text-ink-secondary" for="trolley-file">{{ t('trolley.importFile') }}</label>
                <input
                    id="trolley-file"
                    type="file"
                    accept=".csv,text/csv"
                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary file:mr-3 file:rounded-md file:border-0 file:bg-primary-light file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-primary focus:border-primary focus:ring-primary"
                    @input="importForm.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                />
                <InputError :message="importForm.errors.file" class="mt-1" />

                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        @click="showImport = false"
                    >
                        {{ t('trolley.cancel') }}
                    </button>
                    <button
                        type="submit"
                        :disabled="importForm.processing || !importForm.file"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface disabled:opacity-60"
                    >
                        {{ t('trolley.importSubmit') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
