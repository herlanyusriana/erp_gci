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
import StatusBadge from '@/Components/StatusBadge.vue';
import type { Location, Paginated } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    locations: Paginated<Location>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('locations.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
}

const form = useForm({ code: '', name: '', is_active: true });
const showForm = ref(false);
const editing = ref<Location | null>(null);

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}

function openEdit(location: Location) {
    editing.value = location;
    form.clearErrors();
    form.code = location.code;
    form.name = location.name;
    form.is_active = location.is_active;
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    editing.value = null;
}

function submit() {
    if (editing.value) form.put(route('locations.update', editing.value.id), { onSuccess: () => closeForm() });
    else form.post(route('locations.store'), { onSuccess: () => closeForm() });
}

function remove(location: Location) {
    if (confirm(t('master.deleteLocation', { name: location.code }))) router.delete(route('locations.destroy', location.id));
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.locationTitle') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('master.locationSubtitle') }}</p>
            </div>
            <div class="flex items-center gap-2">
            <a
                :href="route('locations.setup')"
                class="inline-flex items-center gap-1.5 rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
            >
                {{ t('master.setupOpen') }}
            </a>
            <button
                @click="openCreate"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('master.add') }}
            </button>
            </div>
        </div>

        <input v-model="search" @input="doSearch" type="search" :placeholder="t('master.locationSearch')" :aria-label="t('master.locationSearch')" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

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
                    <tr v-for="location in locations.data" :key="location.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ location.code }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ location.name }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge :tone="location.is_active ? 'success' : 'warning'">{{ location.is_active ? t('master.active') : t('master.inactive') }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :label="t('master.printLabel')" variant="print" :href="route('locations.label', location.id)" />
                                <ActionButton :label="t('master.edit')" variant="edit" @click="openEdit(location)" />
                                <ActionButton :label="t('master.delete')" variant="delete" @click="remove(location)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="locations.data.length === 0">
                        <td colspan="4" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.locationEmpty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="locations.links" />

        <Modal :show="showForm" max-width="md" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? t('master.editLocation') : t('master.addLocation') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.code') }}</label>
                        <input :aria-label="t('master.code')" v-model="form.code" type="text" :placeholder="t('master.locationCode')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.code" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.name') }}</label>
                        <input :aria-label="t('master.name')" v-model="form.name" type="text" :placeholder="t('master.locationName')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                </div>
                <p class="mt-3 text-xs text-ink-secondary">{{ t('master.locationLabelHint') }}</p>
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
