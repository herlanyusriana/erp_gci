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
import type { Machine, Paginated } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    machines: Paginated<Machine>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('machines.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
}

const form = useForm({ machine_code: '', machine_name: '', sequence: '' as number | '', is_active: true });
const showForm = ref(false);
const editing = ref<Machine | null>(null);
function openCreate() { editing.value = null; form.reset(); form.clearErrors(); showForm.value = true; }
function openEdit(m: Machine) {
    editing.value = m;
    form.clearErrors();
    form.machine_code = m.machine_code;
    form.machine_name = m.machine_name;
    form.sequence = m.sequence ?? '';
    form.is_active = m.is_active;
    showForm.value = true;
}
function closeForm() { showForm.value = false; editing.value = null; }
function submit() {
    if (editing.value) form.put(route('machines.update', editing.value.id), { onSuccess: () => closeForm() });
    else form.post(route('machines.store'), { onSuccess: () => closeForm() });
}
function remove(m: Machine) {
    if (confirm(t('master.deleteMachine', { name: m.machine_code }))) router.delete(route('machines.destroy', m.id));
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.machineTitle') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('master.machineSubtitle') }}</p>
            </div>
            <button
                @click="openCreate"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('master.add') }}
            </button>
        </div>

        <input v-model="search" @input="doSearch" type="search" :placeholder="t('master.machineSearch')" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="w-20 px-4 py-3">{{ t('master.sequenceLabel') }}</th>
                        <th class="px-4 py-3">{{ t('master.code') }}</th>
                        <th class="px-4 py-3">{{ t('master.name') }}</th>
                        <th class="px-4 py-3">{{ t('master.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('master.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="m in machines.data" :key="m.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 tabular-nums text-ink-secondary">{{ m.sequence ?? '—' }}</td>
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ m.machine_code }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ m.machine_name }}</td>
                        <td class="px-4 py-3">
                            <span :class="m.is_active ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'" class="rounded-md px-2 py-0.5 text-xs font-semibold">{{ m.is_active ? t('master.active') : t('master.inactive') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :label="t('master.printLabel')" variant="print" :href="route('machines.label', m.id)" />
                                <ActionButton :label="t('master.edit')" variant="edit" @click="openEdit(m)" />
                                <ActionButton :label="t('master.delete')" variant="delete" @click="remove(m)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="machines.data.length === 0">
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.machineEmpty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="machines.links" />

        <Modal :show="showForm" max-width="md" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? t('master.editMachine') : t('master.addMachine') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.code') }}</label>
                        <input v-model="form.machine_code" type="text" :placeholder="t('master.machineCode')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.machine_code" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.name') }}</label>
                        <input v-model="form.machine_name" type="text" :placeholder="t('master.machineName')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.machine_name" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.sequenceLabel') }}</label>
                        <input v-model="form.sequence" type="number" min="0" step="1" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <p class="mt-1 text-xs text-ink-secondary">{{ t('master.sequenceHelp') }}</p>
                        <InputError :message="form.errors.sequence" class="mt-1" />
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