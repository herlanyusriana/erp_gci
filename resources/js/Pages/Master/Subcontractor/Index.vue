<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { Paginated, Subcontractor } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    subcontractors: Paginated<Subcontractor>;
    filters: { search?: string };
    bcFacilities: string[];
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('subcontractors.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
}

const form = useForm({
    subcontractor_code: '',
    subcontractor_name: '',
    address: '',
    phone: '',
    email: '',
    contact_person: '',
    npwp: '',
    bc_facility: '' as string,
    process_notes: '',
    is_active: true,
});
const showForm = ref(false);
const editing = ref<Subcontractor | null>(null);

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}

function openEdit(s: Subcontractor) {
    editing.value = s;
    form.clearErrors();
    form.subcontractor_code = s.subcontractor_code;
    form.subcontractor_name = s.subcontractor_name;
    form.address = s.address ?? '';
    form.phone = s.phone ?? '';
    form.email = s.email ?? '';
    form.contact_person = s.contact_person ?? '';
    form.npwp = s.npwp ?? '';
    form.bc_facility = s.bc_facility ?? '';
    form.process_notes = s.process_notes ?? '';
    form.is_active = s.is_active;
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    editing.value = null;
}

function submit() {
    if (editing.value) {
        form.put(route('subcontractors.update', editing.value.id), { onSuccess: () => closeForm() });
    } else {
        form.post(route('subcontractors.store'), { onSuccess: () => closeForm() });
    }
}

function remove(s: Subcontractor) {
    if (confirm(t('master.deleteSubcontractorConfirm', { name: s.subcontractor_name }))) {
        router.delete(route('subcontractors.destroy', s.id));
    }
}
</script>

<template>
    <Head :title="t('master.subcontractorTitle')" />
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.subcontractorTitle') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('master.subcontractorSubtitle') }}</p>
            </div>
            <button
                @click="openCreate"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('master.add') }}
            </button>
        </div>

        <input
            v-model="search"
            @input="doSearch"
            type="search"
            :placeholder="t('master.subcontractorSearch')"
            :aria-label="t('master.subcontractorSearch')"
            class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80"
        />

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('master.subcontractorCode') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.subcontractorName') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.subcontractorContact') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.subcontractorAddress') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.status') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('master.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="s in subcontractors.data" :key="s.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3 font-mono font-medium text-ink-primary">{{ s.subcontractor_code }}</td>
                            <td class="px-4 py-3 text-ink-primary">
                                {{ s.subcontractor_name }}
                                <div class="text-xs text-ink-secondary">
                                    <span v-if="s.npwp" class="font-mono">{{ s.npwp }}</span>
                                    <span v-if="s.bc_facility" class="ml-2 rounded bg-primary-light px-1.5 py-0.5 text-[10px] font-bold uppercase text-primary">{{ s.bc_facility }}</span>
                                </div>
                                <div v-if="s.process_notes" class="mt-0.5 text-xs text-ink-secondary line-clamp-1">{{ s.process_notes }}</div>
                            </td>
                            <td class="px-4 py-3 text-ink-secondary">
                                <div v-if="s.contact_person" class="text-xs font-semibold text-ink-primary">{{ s.contact_person }}</div>
                                <div>{{ s.phone ?? '—' }}</div>
                                <div class="text-xs">{{ s.email ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 text-ink-secondary">{{ s.address ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <StatusBadge :tone="s.is_active ? 'success' : 'warning'">{{ s.is_active ? t('master.active') : t('master.inactive') }}</StatusBadge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <ActionButton :label="t('master.edit')" variant="edit" @click="openEdit(s)" />
                                    <ActionButton :label="t('master.delete')" variant="delete" @click="remove(s)" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="subcontractors.data.length === 0">
                            <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.subcontractorEmpty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Pagination :links="subcontractors.links" />

        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">
                    {{ editing ? t('master.editSubcontractor') : t('master.addSubcontractor') }}
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="sub-code">{{ t('master.subcontractorCode') }}</label>
                        <input id="sub-code" v-model="form.subcontractor_code" type="text" :aria-label="t('master.subcontractorCode')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-mono text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.subcontractor_code" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="sub-name">{{ t('master.subcontractorName') }}</label>
                        <input id="sub-name" v-model="form.subcontractor_name" type="text" :aria-label="t('master.subcontractorName')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.subcontractor_name" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="sub-npwp">{{ t('master.subcontractorNpwp') }}</label>
                        <input id="sub-npwp" v-model="form.npwp" type="text" inputmode="numeric" :aria-label="t('master.subcontractorNpwp')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 font-mono text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.npwp" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="sub-bc-facility">{{ t('master.subcontractorBcFacility') }}</label>
                        <select id="sub-bc-facility" v-model="form.bc_facility" :aria-label="t('master.subcontractorBcFacility')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">—</option>
                            <option v-for="value in bcFacilities" :key="value" :value="value">{{ value }}</option>
                        </select>
                        <InputError :message="form.errors.bc_facility" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary" for="sub-address">{{ t('master.subcontractorAddress') }}</label>
                        <input id="sub-address" v-model="form.address" type="text" :aria-label="t('master.subcontractorAddress')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.address" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="sub-pic">{{ t('master.subcontractorContactPerson') }}</label>
                        <input id="sub-pic" v-model="form.contact_person" type="text" :aria-label="t('master.subcontractorContactPerson')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.contact_person" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="sub-phone">{{ t('master.subcontractorPhone') }}</label>
                        <input id="sub-phone" v-model="form.phone" type="text" :aria-label="t('master.subcontractorPhone')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.phone" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="sub-email">{{ t('master.subcontractorEmail') }}</label>
                        <input id="sub-email" v-model="form.email" type="email" :aria-label="t('master.subcontractorEmail')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary" for="sub-status">{{ t('master.status') }}</label>
                        <select id="sub-status" v-model="form.is_active" :aria-label="t('master.status')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option :value="true">{{ t('master.active') }}</option>
                            <option :value="false">{{ t('master.inactive') }}</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary" for="sub-process-notes">{{ t('master.subcontractorProcessNotes') }}</label>
                        <textarea id="sub-process-notes" v-model="form.process_notes" rows="2" :aria-label="t('master.subcontractorProcessNotes')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"></textarea>
                        <InputError :message="form.errors.process_notes" class="mt-1" />
                    </div>
                </div>
                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('master.cancel') }}</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                        {{ form.processing ? t('master.saving') : t('master.save') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
