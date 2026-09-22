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
import type { ManagedUser, Role, Paginated, PageProps } from '@/types';
import StatusBadge from '@/Components/StatusBadge.vue';

const { t } = useI18n();

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
    if (confirm(t('account.deactivateUserConfirm', { email: u.email }))) router.delete(route('users.destroy', u.id));
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('administration')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('account.userManagement') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('account.usersHelp') }}</p>
            </div>
            <button v-if="perms.create" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('account.addUser') }}
            </button>
        </div>

        <!-- Search -->
        <input v-model="search" @input="doSearch" type="search" :placeholder="t('account.searchUsers')" :aria-label="t('account.searchUsers')" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('account.name') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('account.email') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('account.roles') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('account.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('account.actions') }}</th>
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
                            <StatusBadge :tone="u.deleted_at ? 'warning' : (u.is_active ? 'success' : 'warning')">{{ u.deleted_at ? t('account.deactivated') : u.is_active ? t('account.active') : t('account.inactive') }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton v-if="perms.update && !u.deleted_at" :label="t('account.edit')" variant="edit" @click="openEdit(u)" />
                                <ActionButton v-if="perms.delete && !u.deleted_at" :label="t('account.deactivate')" variant="power" @click="remove(u)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="users.data.length === 0">
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('account.emptyUsers') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="users.links" />

        <!-- Create/Edit modal -->
        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? t('account.editUser') : t('account.addUser') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.name') }}</label>
                        <input :aria-label="t('account.name')" v-model="form.name" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.email') }}</label>
                        <input :aria-label="t('account.email')" v-model="form.email" type="email" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ editing ? t('account.optionalPassword') : t('account.password') }}</label>
                        <input :aria-label="editing ? t('account.optionalPassword') : t('account.password')" v-model="form.password" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.confirmPassword') }}</label>
                        <input :aria-label="t('account.confirmPassword')" v-model="form.password_confirmation" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.password_confirmation" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.status') }}</label>
                        <select :aria-label="t('account.status')" v-model="form.is_active" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option :value="true">{{ t('account.active') }}</option>
                            <option :value="false">{{ t('account.inactive') }}</option>
                        </select>
                    </div>
                </div>

                <div class="mt-5">
                    <h3 class="mb-2 text-sm font-semibold text-ink-primary">{{ t('account.roles') }}</h3>
                    <div class="flex flex-wrap gap-2">
                        <label v-for="r in roles" :key="r.id" class="flex cursor-pointer items-center gap-2 rounded-lg border border-borderline bg-background/40 px-3 py-1.5 text-sm text-ink-primary">
                            <input type="checkbox" :checked="form.roles.includes(r.id)" @change="toggleRole(r.id)" class="h-4 w-4 rounded border-borderline text-primary focus:ring-primary" />
                            <span>{{ r.label ?? r.name }}</span>
                        </label>
                    </div>
                    <InputError :message="form.errors.roles" class="mt-1" />
                </div>

                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('account.cancel') }}</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ form.processing ? t('account.saving') : t('account.save') }}</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>