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
import type { Uom, Paginated } from '@/types';
import StatusBadge from '@/Components/StatusBadge.vue';

const { t } = useI18n();

const props = defineProps<{
    uoms: Paginated<Uom>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('uoms.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
}

const form = useForm({ code: '', name: '', is_active: true });
const showForm = ref(false);
const editing = ref<Uom | null>(null);
function openCreate() { editing.value = null; form.reset(); form.clearErrors(); showForm.value = true; }
function openEdit(u: Uom) {
    editing.value = u;
    form.clearErrors();
    form.code = u.code;
    form.name = u.name ?? '';
    form.is_active = u.is_active;
    showForm.value = true;
}
function closeForm() { showForm.value = false; editing.value = null; }
function submit() {
    if (editing.value) form.put(route('uoms.update', editing.value.id), { onSuccess: () => closeForm() });
    else form.post(route('uoms.store'), { onSuccess: () => closeForm() });
}
function remove(u: Uom) {
    if (confirm(t('master.deleteUom', { name: u.code }))) router.delete(route('uoms.destroy', u.id));
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.uomTitle') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('master.uomSubtitle') }}</p>
            </div>
            <button
                @click="openCreate"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('master.add') }}
            </button>
        </div>

        <input v-model="search" @input="doSearch" type="search" :placeholder="t('master.uomSearch')" :aria-label="t('master.uomSearch')" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('master.code') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.name') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('master.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="u in uoms.data" :key="u.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ u.code }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ u.name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge :tone="u.is_active ? 'success' : 'warning'">{{ u.is_active ? t('master.active') : t('master.inactive') }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :label="t('master.edit')" variant="edit" @click="openEdit(u)" />
                                <ActionButton :label="t('master.delete')" variant="delete" @click="remove(u)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="uoms.data.length === 0">
                        <td colspan="4" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.uomEmpty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="uoms.links" />

        <Modal :show="showForm" max-width="md" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? t('master.editUom') : t('master.addUom') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.code') }}</label>
                        <input :aria-label="t('master.code')" v-model="form.code" type="text" placeholder="PCS" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.code" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.name') }}</label>
                        <input :aria-label="t('master.name')" v-model="form.name" type="text" :placeholder="t('master.pieces')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.name" class="mt-1" />
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