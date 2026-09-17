<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { useI18n } from 'vue-i18n';
import type { Role, Permission, Paginated, PageProps } from '@/types';

const { t } = useI18n();

const page = usePage<PageProps>();

const props = defineProps<{
    roles: Paginated<Role>;
    filters: { search?: string };
    permissions: Permission[];
}>();

const perms = computed(() => {
    const list: string[] = page.props.auth.permissions ?? [];
    return {
        create: list.includes('role.create') || list.includes('role.update'),
        update: list.includes('role.update'),
        delete: list.includes('role.delete'),
    };
});

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('roles.index'), { search: search.value || undefined }, { preserveState: true, replace: true });
    }, 300);
}

// group permissions by module for nicer checkboxes
const groupedPermissions = computed(() => {
    const map = new Map<string, Permission[]>();
    for (const p of props.permissions) {
        const mod = p.module ?? 'other';
        if (!map.has(mod)) map.set(mod, []);
        map.get(mod)!.push(p);
    }
    return Array.from(map.entries()).map(([module, items]) => ({ module, items }));
});

const showForm = ref(false);
const editing = ref<Role | null>(null);
const form = useForm({
    name: '',
    label: '',
    description: '',
    permissions: [] as number[],
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}
function openEdit(role: Role) {
    editing.value = role;
    form.clearErrors();
    form.name = role.name;
    form.label = role.label ?? '';
    form.description = role.description ?? '';
    form.permissions = (role.permissions ?? []).map((p) => p.id);
    showForm.value = true;
}
function closeForm() { showForm.value = false; editing.value = null; }

function togglePerm(id: number) {
    const i = form.permissions.indexOf(id);
    if (i >= 0) form.permissions.splice(i, 1);
    else form.permissions.push(id);
}
function toggleModule(module: string, items: Permission[]) {
    const all = items.every((p) => form.permissions.includes(p.id));
    const ids = items.map((p) => p.id);
    if (all) form.permissions = form.permissions.filter((id) => !ids.includes(id));
    else form.permissions = Array.from(new Set([...form.permissions, ...ids]));
}

function submit() {
    if (editing.value) form.put(route('roles.update', editing.value.id), { onSuccess: closeForm });
    else form.post(route('roles.store'), { onSuccess: closeForm });
}

function remove(role: Role) {
    if (confirm(t('account.deleteRoleConfirm', { name: role.name }))) router.delete(route('roles.destroy', role.id));
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('administration')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('account.rolesPermissions') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('account.rolesHelp') }}</p>
            </div>
            <button v-if="perms.create" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('account.addRole') }}
            </button>
        </div>

        <!-- Search -->
        <input v-model="search" @input="doSearch" type="search" :placeholder="t('account.searchRoles')" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">{{ t('account.role') }}</th>
                        <th class="px-4 py-3">{{ t('account.label') }}</th>
                        <th class="px-4 py-3">{{ t('account.users') }}</th>
                        <th class="px-4 py-3">{{ t('account.permissions') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('account.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="r in roles.data" :key="r.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ r.name }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ r.label ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ r.users_count }}</td>
                        <td class="px-4 py-3 text-ink-secondary">{{ (r.permissions ?? []).length }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton v-if="perms.update && r.name !== 'super-admin'" :label="t('account.edit')" variant="edit" @click="openEdit(r)" />
                                <ActionButton v-if="perms.delete && r.name !== 'super-admin'" :label="t('account.delete')" variant="delete" @click="remove(r)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="roles.data.length === 0">
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('account.emptyRoles') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="roles.links" />

        <!-- Create/Edit modal -->
        <Modal :show="showForm" max-width="2xl" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? t('account.editRole') : t('account.addRole') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.slugName') }}</label>
                        <input v-model="form.name" type="text" placeholder="warehouse" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.label') }}</label>
                        <input v-model="form.label" type="text" :placeholder="t('account.warehouseLabel')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.label" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.description') }}</label>
                        <input v-model="form.description" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                </div>

                <div class="mt-5">
                    <h3 class="mb-3 text-sm font-semibold text-ink-primary">{{ t('account.permissions') }}</h3>
                    <div class="max-h-[45vh] overflow-y-auto pr-1">
                        <div v-for="g in groupedPermissions" :key="g.module" class="mb-4 rounded-lg border border-borderline bg-background/40 p-3">
                            <label class="mb-2 flex cursor-pointer items-center gap-2">
                                <input type="checkbox" :checked="g.items.every((p) => form.permissions.includes(p.id))" @change="toggleModule(g.module, g.items)" class="h-4 w-4 rounded border-borderline text-primary focus:ring-primary" />
                                <span class="text-sm font-semibold uppercase tracking-wide text-ink-primary">{{ g.module === 'other' ? t('account.otherPermissions') : g.module }}</span>
                            </label>
                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                <label v-for="p in g.items" :key="p.id" class="flex cursor-pointer items-center gap-2 text-sm text-ink-secondary">
                                    <input type="checkbox" :checked="form.permissions.includes(p.id)" @change="togglePerm(p.id)" class="h-4 w-4 rounded border-borderline text-primary focus:ring-primary" />
                                    <span>{{ p.name }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <InputError :message="form.errors.permissions" class="mt-1" />
                </div>

                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background">{{ t('account.cancel') }}</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">{{ form.processing ? t('account.saving') : t('account.save') }}</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>