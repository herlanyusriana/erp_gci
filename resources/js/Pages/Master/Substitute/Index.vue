<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import type { PartSubstitute, Part, Supplier, Paginated, PageProps } from '@/types';

const page = usePage<PageProps>();

const props = defineProps<{
    substitutes: Paginated<PartSubstitute>;
    filters: { search?: string; supplier_id?: string };
    suppliers: Supplier[];
    parts: Part[];
}>();

const search = ref(props.filters.search ?? '');
const supplierId = ref(props.filters.supplier_id ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;
watch([search, supplierId], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('substitutes.index'), {
            search: search.value || undefined,
            supplier_id: supplierId.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
});

const perms = computed(() => {
    const list: string[] = page.props.auth.permissions ?? [];
    const has = (p: string) => list.some((x) => [p, 'master.' + p].includes(x));
    return { create: has('part_substitute.create') || has('part.create'), update: has('part_substitute.update') || has('part.update'), delete: has('part_substitute.delete') || has('part.delete') };
});

const showForm = ref(false);
const editing = ref<PartSubstitute | null>(null);
const form = useForm({
    part_id: '',
    substitute_part_id: '',
    supplier_id: '',
    material_group: '',
    source: '',
    is_active: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}
function openEdit(s: PartSubstitute) {
    editing.value = s;
    form.clearErrors();
    form.part_id = s.part?.id != null ? String(s.part.id) : '';
    form.substitute_part_id = s.substitute_part?.id != null ? String(s.substitute_part.id) : '';
    form.supplier_id = s.supplier?.id != null ? String(s.supplier.id) : '';
    form.material_group = s.material_group ?? '';
    form.source = s.source ?? '';
    form.is_active = s.is_active;
    showForm.value = true;
}
function closeForm() { showForm.value = false; editing.value = null; }
function submit() {
    if (editing.value) form.put(route('substitutes.update', editing.value.id), { onSuccess: closeForm });
    else form.post(route('substitutes.store'), { onSuccess: closeForm });
}
function remove(s: PartSubstitute) {
    if (confirm('Hapus substitute ini?')) router.delete(route('substitutes.destroy', s.id));
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">Material Substitute</h1>
                <p class="mt-1 text-sm text-ink-secondary">Part material → substitute part + supplier + source.</p>
            </div>
            <button v-if="perms.create" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Tambah
            </button>
        </div>

        <!-- Filters -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <input v-model="search" type="search" placeholder="Cari part/substitute/supplier…" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />
            <select v-model="supplierId" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-64">
                <option value="">Semua Supplier</option>
                <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">{{ s.supplier_name }}</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">Material Part</th>
                        <th class="px-4 py-3">Substitute Part</th>
                        <th class="px-4 py-3">Supplier</th>
                        <th class="px-4 py-3">Group / Source</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="s in substitutes.data" :key="s.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3">
                            <div class="font-medium text-ink-primary">{{ s.part?.part_number ?? '—' }}</div>
                            <div class="text-xs text-ink-secondary">{{ s.part?.part_name }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-ink-primary">{{ s.substitute_part?.part_number ?? '—' }}</div>
                            <div class="text-xs text-ink-secondary">{{ s.substitute_part?.part_name }}</div>
                        </td>
                        <td class="px-4 py-3 text-ink-primary">{{ s.supplier?.supplier_name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="text-ink-primary">{{ s.material_group ?? '—' }}</div>
                            <div class="text-xs text-ink-secondary">{{ s.source ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton v-if="perms.update" label="Edit" variant="edit" @click="openEdit(s)" />
                                <ActionButton v-if="perms.delete" label="Hapus" variant="delete" @click="remove(s)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="substitutes.data.length === 0">
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-ink-secondary">Tidak ada substitute.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="substitutes.links" />

        <!-- Create/Edit modal -->
        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? 'Edit Substitute' : 'Tambah Substitute' }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Part Material</label>
                        <select v-model="form.part_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">— Pilih Part —</option>
                            <option v-for="p in parts" :key="p.id" :value="String(p.id)">{{ p.part_number }} · {{ p.part_name }}</option>
                        </select>
                        <InputError :message="form.errors.part_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Substitute Part</label>
                        <select v-model="form.substitute_part_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">— Pilih Part —</option>
                            <option v-for="p in parts" :key="p.id" :value="String(p.id)">{{ p.part_number }} · {{ p.part_name }}</option>
                        </select>
                        <InputError :message="form.errors.substitute_part_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Supplier</label>
                        <select v-model="form.supplier_id" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option value="">—</option>
                            <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">{{ s.supplier_name }}</option>
                        </select>
                        <InputError :message="form.errors.supplier_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Material Group</label>
                        <input v-model="form.material_group" type="text" placeholder="STEEL IN SHEET" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.material_group" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Source</label>
                        <input v-model="form.source" type="text" placeholder="Import / Local" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.source" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Status</label>
                        <select v-model="form.is_active" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option :value="true">Active</option>
                            <option :value="false">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background">Batal</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">{{ form.processing ? 'Menyimpan…' : 'Simpan' }}</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>