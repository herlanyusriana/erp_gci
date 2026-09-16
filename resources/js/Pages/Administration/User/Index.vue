<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import type { ManagedUser, Role, Paginated, PageProps } from '@/types';

const page = usePage<PageProps>();

const props = defineProps<{
    users: Paginated<ManagedUser>;
    filters: { search?: string };
    roles: Role[];
}>();

const perms = computed(() => {
    const list: string[] = page.props.auth.permissions ?? [];
    return {
        create: list.includes('user.create') || list.includes('user.update'),
        update: list.includes('user.update'),
        delete: list.includes('user.delete'),
    };
});

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('users.index'), { search: search.value || undefined }, { preserveState: true, replace: true });
    }, 300);
}

const showForm = ref(false);
const editing = ref<ManagedUser | null>(null);
const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    is_active: true,
    roles: [] as number[],
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}
function openEdit(u: ManagedUser) {
    editing.value = u;
    form.clearErrors();
    form.name = u.name;
    form.email = u.email;
    form.password = '';
    form.password_confirmation = '';
    form.is_active = u.is_active;
    form.roles = (u.roles ?? []).map((r) => r.id);
    showForm.value = true;
}
function closeForm() { showForm.value = false; editing.value = null; }

function toggleRole(id: number) {
    const i = form.roles.indexOf(id);
    if (i >= 0) form.roles.splice(i, 1);
    else form.roles.push(id);
}

function submit() {
    if (editing.value) form.put(route('users.update', editing.value.id), { onSuccess: closeForm });
    else form.post(route('users.store'), { onSuccess: closeForm });
}
function remove(u: ManagedUser) {
    if (confirm(`Nonaktifkan user ${u.email}?`)) router.delete(route('users.destroy', u.id));
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('administration')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">User Management</h1>
                <p class="mt-1 text-sm text-ink-secondary">Kelola pengguna dan role.</p>
            </div>
            <button v-if="perms.create" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Tambah User
            </button>
        </div>

        <!-- Search -->
        <input v-model="search" @input="doSearch" type="search" placeholder="Cari nama/email…" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">Nama</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Roles</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="u in users.data" :key="u.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ u.name }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ u.email }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <span v-for="r in u.roles ?? []" :key="r.id" class="rounded-md bg-primary-light px-2 py-0.5 text-xs font-semibold text-primary">{{ r.label ?? r.name }}</span>
                                <span v-if="(u.roles ?? []).length === 0" class="text-xs text-ink-secondary">—</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="u.deleted_at ? 'bg-warning/10 text-warning' : u.is_active ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'" class="rounded-md px-2 py-0.5 text-xs font-semibold">
                                {{ u.deleted_at ? 'NONAKTIF' : u.is_active ? 'ACTIVE' : 'INACTIVE' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton v-if="perms.update && !u.deleted_at" label="Edit" variant="edit" @click="openEdit(u)" />
                                <ActionButton v-if="perms.delete && !u.deleted_at" label="Nonaktifkan" variant="power" @click="remove(u)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="users.data.length === 0">
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-ink-secondary">Tidak ada user.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="users.links" />

        <!-- Create/Edit modal -->
        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? 'Edit User' : 'Tambah User' }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Nama</label>
                        <input v-model="form.name" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Email</label>
                        <input v-model="form.email" type="email" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ editing ? 'Password Baru (opsional)' : 'Password' }}</label>
                        <input v-model="form.password" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Konfirmasi Password</label>
                        <input v-model="form.password_confirmation" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.password_confirmation" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">Status</label>
                        <select v-model="form.is_active" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option :value="true">Active</option>
                            <option :value="false">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="mt-5">
                    <h3 class="mb-2 text-sm font-semibold text-ink-primary">Roles</h3>
                    <div class="flex flex-wrap gap-2">
                        <label v-for="r in roles" :key="r.id" class="flex cursor-pointer items-center gap-2 rounded-lg border border-borderline bg-background/40 px-3 py-1.5 text-sm text-ink-primary">
                            <input type="checkbox" :checked="form.roles.includes(r.id)" @change="toggleRole(r.id)" class="h-4 w-4 rounded border-borderline text-primary focus:ring-primary" />
                            <span>{{ r.label ?? r.name }}</span>
                        </label>
                    </div>
                    <InputError :message="form.errors.roles" class="mt-1" />
                </div>

                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background">Batal</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">{{ form.processing ? 'Menyimpan…' : 'Simpan' }}</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>