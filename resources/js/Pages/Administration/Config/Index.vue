<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import type { ConfigMaster, Paginated, PageProps } from '@/types';

const page = usePage<PageProps>();

const props = defineProps<{
    configs: Paginated<ConfigMaster>;
    filters: { group?: string; search?: string };
    groups: string[];
}>();

const group = ref(props.filters.group ?? '');
const search = ref(props.filters.search ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;
watch([group, search], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('config.index'), {
            group: group.value || undefined,
            search: search.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
});

const canEdit = computed(() => (page.props.auth.permissions ?? []).includes('config.update'));

const showForm = ref(false);
const editing = ref<ConfigMaster | null>(null);
const form = useForm({
    group: '',
    key: '',
    value: '',
    data_type: 'string',
    description: '',
    is_active: true,
});

const dataTypes = ['string', 'boolean', 'integer', 'float', 'decimal', 'json'];

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}
function openEdit(c: ConfigMaster) {
    editing.value = c;
    form.clearErrors();
    form.group = c.group;
    form.key = c.key;
    form.value = c.value ?? '';
    form.data_type = c.data_type;
    form.description = c.description ?? '';
    form.is_active = c.is_active;
    showForm.value = true;
}
function closeForm() { showForm.value = false; editing.value = null; }
function submit() {
    if (editing.value) form.put(route('config.update', editing.value.id), { onSuccess: closeForm });
    else form.post(route('config.store'), { onSuccess: closeForm });
}
function remove(c: ConfigMaster) {
    if (confirm(`Hapus config ${c.group}.${c.key}?`)) router.delete(route('config.destroy', c.id));
}

const badgeClass = (dt: string) => {
    const map: Record<string, string> = {
        boolean: 'bg-info/10 text-info',
        integer: 'bg-success/10 text-success',
        float: 'bg-success/10 text-success',
        decimal: 'bg-success/10 text-success',
        json: 'bg-warning/10 text-warning',
    };
    return map[dt] ?? 'bg-primary-light text-primary';
};
</script>

<template>
    <AppLayout>
        <BackButton :href="route('administration')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">Config Master</h1>
                <p class="mt-1 text-sm text-ink-secondary">Pengaturan sistem — group / key / value.</p>
            </div>
            <button v-if="canEdit" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Tambah
            </button>
        </div>

        <!-- Filters -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <select v-model="group" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-48">
                <option value="">Semua Group</option>
                <option v-for="g in groups" :key="g" :value="g">{{ g }}</option>
            </select>
            <input v-model="search" type="search" placeholder="Cari key/description…" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">Group</th>
                        <th class="px-4 py-3">Key</th>
                        <th class="px-4 py-3">Value</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="c in configs.data" :key="c.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ c.group }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ c.key }}</td>
                        <td class="px-4 py-3 max-w-[16rem] truncate text-ink-primary">{{ c.value ?? '—' }}</td>
                        <td class="px-4 py-3"><span :class="badgeClass(c.data_type)" class="rounded-md px-2 py-0.5 text-xs font-semibold">{{ c.data_type }}</span></td>
                        <td class="px-4 py-3">
                            <span :class="c.is_active ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'" class="rounded-md px-2 py-0.5 text-xs font-semibold">{{ c.is_active ? 'ACTIVE' : 'INACTIVE' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton v-if="canEdit" label="Edit" variant="edit" @click="openEdit(c)" />
                                <ActionButton v-if="canEdit" label="Hapus" variant="delete" @click="remove(c)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="configs.data.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">Tidak ada config.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="configs.links" />

        <!-- Create/Edit modal -->
        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? 'Edit Config' : 'Tambah Config' }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Group</label>
                        <input v-model="form.group" type="text" placeholder="SYSTEM" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.group" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Key</label>
                        <input v-model="form.key" type="text" placeholder="company_name" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.key" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Value</label>
                        <input v-model="form.value" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.value" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Data Type</label>
                        <select v-model="form.data_type" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option v-for="dt in dataTypes" :key="dt" :value="dt">{{ dt }}</option>
                        </select>
                        <InputError :message="form.errors.data_type" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">Description</label>
                        <input v-model="form.description" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
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