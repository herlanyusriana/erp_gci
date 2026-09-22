<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import type { TruckingCompany, Paginated } from '@/types';
import StatusBadge from '@/Components/StatusBadge.vue';

const { t } = useI18n();

const props = defineProps<{
    truckings: Paginated<TruckingCompany>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;

function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('trucking-companies.index'), { search: search.value || undefined }, { preserveState: true, replace: true });
    }, 300);
}

const blank = (): Omit<TruckingCompany, 'id' | 'created_at'> => ({
    company_code: '',
    company_name: '',
    address: '',
    phone: '',
    email: '',
    contact_person: '',
    is_active: true,
});

const form = useForm(blank());

const showForm = ref(false);
const editing = ref<TruckingCompany | null>(null);

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}
function openEdit(t: TruckingCompany) {
    editing.value = t;
    form.clearErrors();
    form.company_code = t.company_code;
    form.company_name = t.company_name;
    form.address = t.address ?? '';
    form.phone = t.phone ?? '';
    form.email = t.email ?? '';
    form.contact_person = t.contact_person ?? '';
    form.is_active = t.is_active;
    showForm.value = true;
}
function closeForm() {
    showForm.value = false;
    editing.value = null;
}
function submit() {
    if (editing.value) {
        form.put(route('trucking-companies.update', editing.value.id), { onSuccess: () => closeForm() });
    } else {
        form.post(route('trucking-companies.store'), { onSuccess: () => closeForm() });
    }
}
function remove(company: TruckingCompany) {
    if (confirm(t('master.deleteTrucking', { name: company.company_code }))) {
        router.delete(route('trucking-companies.destroy', company.id));
    }
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.truckingTitle') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('master.truckingSubtitle') }}</p>
            </div>
            <button
                @click="openCreate"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('master.add') }}
            </button>
        </div>

        <!-- Search -->
        <input v-model="search" @input="doSearch" type="search" :placeholder="t('master.truckingSearch')" :aria-label="t('master.truckingSearch')" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <!-- Table -->
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('master.code') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.name') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.contact') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.phone') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('master.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="company in truckings.data" :key="company.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ company.company_code }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ company.company_name }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ company.contact_person ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ company.phone ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge :tone="company.is_active ? 'success' : 'warning'">{{ company.is_active ? t('master.active') : t('master.inactive') }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :label="t('master.edit')" variant="edit" @click="openEdit(company)" />
                                <ActionButton :label="t('master.delete')" variant="delete" @click="remove(company)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="truckings.data.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.truckingEmpty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="truckings.links" />

        <!-- Create/Edit modal -->
        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? t('master.editTrucking') : t('master.addTrucking') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.code') }}</label>
                        <input :aria-label="t('master.code')" v-model="form.company_code" type="text" placeholder="TRK-001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.company_code" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.name') }}</label>
                        <input :aria-label="t('master.name')" v-model="form.company_name" type="text" :placeholder="t('master.companyName')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.company_name" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.contactPerson') }}</label>
                        <input :aria-label="t('master.contactPerson')" v-model="form.contact_person" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.contact_person" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.phone') }}</label>
                        <input :aria-label="t('master.phone')" v-model="form.phone" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.phone" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.email') }}</label>
                        <input :aria-label="t('master.email')" v-model="form.email" type="email" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.address') }}</label>
                        <textarea :aria-label="t('master.address')" v-model="form.address" rows="2" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"></textarea>
                        <InputError :message="form.errors.address" class="mt-1" />
                    </div>
                    <div class="flex items-center">
                        <label class="flex items-center gap-2 text-sm text-ink-primary">
                            <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-borderline text-primary focus:ring-primary" />
                            {{ t('master.active') }}
                        </label>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background">{{ t('master.cancel') }}</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                        {{ form.processing ? t('master.saving') : t('master.save') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
