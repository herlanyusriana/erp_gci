<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import type { TruckingCompany, Paginated } from '@/types';

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
function remove(t: TruckingCompany) {
    if (confirm(`Hapus trucking ${t.company_code}?`)) {
        router.delete(route('trucking-companies.destroy', t.id));
    }
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">Trucking Company Master</h1>
                <p class="mt-1 text-sm text-ink-secondary">Master perusahaan trucking — direferensikan lewat trucking_company_id di Arrival.</p>
            </div>
            <button
                @click="openCreate"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Tambah
            </button>
        </div>

        <!-- Search -->
        <input v-model="search" @input="doSearch" type="search" placeholder="Cari trucking…" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <!-- Table -->
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Contact</th>
                        <th class="px-4 py-3">Phone</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="t in truckings.data" :key="t.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ t.company_code }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ t.company_name }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ t.contact_person ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ t.phone ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span :class="t.is_active ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'" class="rounded-md px-2 py-0.5 text-xs font-semibold">{{ t.is_active ? 'ACTIVE' : 'INACTIVE' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton label="Edit" variant="edit" @click="openEdit(t)" />
                                <ActionButton label="Hapus" variant="delete" @click="remove(t)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="truckings.data.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">Tidak ada trucking company.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="truckings.links" />

        <!-- Create/Edit modal -->
        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? 'Edit Trucking Company' : 'Tambah Trucking Company' }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Code</label>
                        <input v-model="form.company_code" type="text" placeholder="TRK-001" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.company_code" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Name</label>
                        <input v-model="form.company_name" type="text" placeholder="Nama perusahaan" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.company_name" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Contact Person</label>
                        <input v-model="form.contact_person" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.contact_person" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Phone</label>
                        <input v-model="form.phone" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.phone" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Email</label>
                        <input v-model="form.email" type="email" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">Address</label>
                        <textarea v-model="form.address" rows="2" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"></textarea>
                        <InputError :message="form.errors.address" class="mt-1" />
                    </div>
                    <div class="flex items-center">
                        <label class="flex items-center gap-2 text-sm text-ink-primary">
                            <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-borderline text-primary focus:ring-primary" />
                            Aktif
                        </label>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background">Batal</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                        {{ form.processing ? 'Menyimpan…' : 'Simpan' }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
