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
import type { Supplier, Paginated } from '@/types';
import StatusBadge from '@/Components/StatusBadge.vue';

const { t } = useI18n();

const props = defineProps<{
    suppliers: Paginated<Supplier>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;

function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('suppliers.index'), { search: search.value || undefined }, { preserveState: true, replace: true });
    }, 300);
}

const form = useForm({
    supplier_code: '',
    supplier_name: '',
    address: '',
    phone: '',
    email: '',
    contact_person: '',
    bank_account: '',
    signature: null as File | null,
    is_active: true,
});

const showForm = ref(false);
const editing = ref<Supplier | null>(null);

function openCreate() {
    editing.value = null;
    form.reset();
    form.signature = null;
    form.clearErrors();
    showForm.value = true;
}
function openEdit(s: Supplier) {
    editing.value = s;
    form.clearErrors();
    form.supplier_code = s.supplier_code;
    form.supplier_name = s.supplier_name;
    form.address = s.address ?? '';
    form.phone = s.phone ?? '';
    form.email = s.email ?? '';
    form.contact_person = s.contact_person ?? '';
    form.bank_account = s.bank_account ?? '';
    form.signature = null;
    form.is_active = s.is_active;
    showForm.value = true;
}
function closeForm() {
    showForm.value = false;
    editing.value = null;
}
function submit() {
    if (editing.value) {
        form.put(route('suppliers.update', editing.value.id), { onSuccess: () => closeForm() });
    } else {
        form.post(route('suppliers.store'), { onSuccess: () => closeForm() });
    }
}
function signaturePreviewSource() {
    if (form.signature) {
        return window.URL.createObjectURL(form.signature);
    }
    return editing.value?.signature_url ?? '';
}

function remove(s: Supplier) {
    if (confirm(t('master.deleteSupplier', { name: s.supplier_code }))) {
        router.delete(route('suppliers.destroy', s.id));
    }
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.supplierTitle') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('master.supplierSubtitle') }}</p>
            </div>
            <button
                @click="openCreate"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('master.add') }}
            </button>
        </div>

        <!-- Search -->
        <input v-model="search" @input="doSearch" type="search" :placeholder="t('master.supplierSearch')" :aria-label="t('master.supplierSearch')" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <!-- Table -->
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('master.code') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.name') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.address') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('master.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="s in suppliers.data" :key="s.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ s.supplier_code }}</td>
                        <td class="px-4 py-3 text-ink-primary">
                            <div>{{ s.supplier_name }}</div>
                            <div v-if="s.phone || s.contact_person" class="text-xs text-ink-secondary">
                                {{ [s.contact_person, s.phone].filter(Boolean).join(' · ') }}
                            </div>
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
                    <tr v-if="suppliers.data.length === 0">
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.supplierEmpty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="suppliers.links" />

        <!-- Create/Edit modal -->
        <Modal :show="showForm" max-width="md" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? t('master.editSupplier') : t('master.addSupplier') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.code') }}</label>
                        <input :aria-label="t('master.code')" v-model="form.supplier_code" type="text" placeholder="SUP-001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.supplier_code" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.name') }}</label>
                        <input :aria-label="t('master.name')" v-model="form.supplier_name" type="text" :placeholder="t('master.supplierName')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.supplier_name" class="mt-1" />
                    </div>
                </div>
                <div class="mt-4">
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('master.address') }}</label>
                    <input :aria-label="t('master.address')" v-model="form.address" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <InputError :message="form.errors.address" class="mt-1" />
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
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
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.bankAccount') }}</label>
                        <input :aria-label="t('master.bankAccount')" v-model="form.bank_account" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.bank_account" class="mt-1" />
                    </div>
                </div>
                <div class="mt-4">
                    <label class="text-xs font-semibold text-ink-secondary">{{ t('master.signatureImage') }}</label>
                    <input :aria-label="t('master.signatureImage')"
                        type="file"
                        accept="image/*"
                        @input="form.signature = ($event.target as HTMLInputElement).files?.[0] ?? null"
                        class="mt-1 w-full rounded-lg border-borderline bg-surface text-sm text-ink-primary file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-primary-hover"
                    />
                    <InputError :message="form.errors.signature" class="mt-1" />
                    <div v-if="editing?.signature_url || form.signature" class="mt-2 flex items-center gap-3 rounded-lg border border-borderline bg-background p-2">
                        <img
                            :src="signaturePreviewSource()"
                            :alt="t('master.supplierSignature')"
                            class="h-16 rounded-md border border-borderline bg-white object-contain"
                        />
                        <span class="text-xs text-ink-secondary">{{ t('master.signatureHelp') }}</span>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('master.cancel') }}</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                        {{ form.processing ? t('master.saving') : t('master.save') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>