<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import type { Process, Paginated } from '@/types';

const props = defineProps<{
    processes: Paginated<Process>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('processes.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
}

const form = useForm({ process_code: '', process_name: '', is_active: true });
const showForm = ref(false);
const editing = ref<Process | null>(null);
function openCreate() { editing.value = null; form.reset(); form.clearErrors(); showForm.value = true; }
function openEdit(p: Process) {
    editing.value = p;
    form.clearErrors();
    form.process_code = p.process_code;
    form.process_name = p.process_name;
    form.is_active = p.is_active;
    showForm.value = true;
}
function closeForm() { showForm.value = false; editing.value = null; }
function submit() {
    if (editing.value) form.put(route('processes.update', editing.value.id), { onSuccess: () => closeForm() });
    else form.post(route('processes.store'), { onSuccess: () => closeForm() });
}
function remove(p: Process) {
    if (confirm(`Hapus process ${p.process_code}?`)) router.delete(route('processes.destroy', p.id));
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">Process Master</h1>
                <p class="mt-1 text-sm text-ink-secondary">Proses produksi (Press, Assembly, …) — beda dengan nama WIP.</p>
            </div>
            <button
                @click="openCreate"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Tambah
            </button>
        </div>

        <input v-model="search" @input="doSearch" type="search" placeholder="Cari process…" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="p in processes.data" :key="p.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ p.process_code }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ p.process_name }}</td>
                        <td class="px-4 py-3">
                            <span :class="p.is_active ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'" class="rounded-md px-2 py-0.5 text-xs font-semibold">{{ p.is_active ? 'ACTIVE' : 'INACTIVE' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton label="Edit" variant="edit" @click="openEdit(p)" />
                                <ActionButton label="Hapus" variant="delete" @click="remove(p)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="processes.data.length === 0">
                        <td colspan="4" class="px-4 py-12 text-center text-sm text-ink-secondary">Tidak ada process.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="processes.links" />

        <Modal :show="showForm" max-width="md" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? 'Edit Process' : 'Tambah Process' }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Code</label>
                        <input v-model="form.process_code" type="text" placeholder="Process Code" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.process_code" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Name</label>
                        <input v-model="form.process_name" type="text" placeholder="Process Name" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.process_name" class="mt-1" />
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