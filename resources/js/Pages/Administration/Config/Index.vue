<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { useI18n } from 'vue-i18n';
import type { ConfigMaster, Paginated, PageProps } from '@/types';
import StatusBadge from '@/Components/StatusBadge.vue';
import type { Tone } from '@/utils/tone';

const { t } = useI18n();

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
/** Tone badge per tipe data config (dipakai StatusBadge). */
const badgeTone = (dt: string): Tone =>
    (({ boolean: 'info', integer: 'success', float: 'success', decimal: 'success', json: 'warning' }) as Record<string, Tone>)[dt] ?? 'primary';

function remove(c: ConfigMaster) {
    if (confirm(t('account.deleteConfigConfirm', { name: `${c.group}.${c.key}` }))) router.delete(route('config.destroy', c.id));
}

</script>

<template>
    <AppLayout>
        <BackButton :href="route('administration')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('account.configMaster') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('account.configHelp') }}</p>
            </div>
            <button v-if="canEdit" @click="openCreate" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('account.add') }}
            </button>
        </div>

        <!-- Filters -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <select :aria-label="t('account.allGroups')" v-model="group" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-48">
                <option value="">{{ t('account.allGroups') }}</option>
                <option v-for="g in groups" :key="g" :value="g">{{ g }}</option>
            </select>
            <input v-model="search" type="search" :placeholder="t('account.searchConfig')" :aria-label="t('account.searchConfig')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('account.group') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('account.key') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('account.value') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('account.type') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('account.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('account.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="c in configs.data" :key="c.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ c.group }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ c.key }}</td>
                        <td class="px-4 py-3 max-w-[16rem] truncate text-ink-primary">{{ c.value ?? '—' }}</td>
                        <td class="px-4 py-3"><StatusBadge :tone="badgeTone(c.data_type)">{{ t(`account.dataTypes.${c.data_type}`) }}</StatusBadge></td>
                        <td class="px-4 py-3">
                            <StatusBadge :tone="c.is_active ? 'success' : 'warning'">{{ c.is_active ? t('account.active') : t('account.inactive') }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton v-if="canEdit" :label="t('account.edit')" variant="edit" @click="openEdit(c)" />
                                <ActionButton v-if="canEdit" :label="t('account.delete')" variant="delete" @click="remove(c)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="configs.data.length === 0">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('account.emptyConfigs') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="configs.links" />

        <!-- Create/Edit modal -->
        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">{{ editing ? t('account.editConfig') : t('account.addConfig') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.group') }}</label>
                        <input :aria-label="t('account.group')" v-model="form.group" type="text" placeholder="SYSTEM" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.group" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.key') }}</label>
                        <input :aria-label="t('account.key')" v-model="form.key" type="text" placeholder="company_name" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.key" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.value') }}</label>
                        <input :aria-label="t('account.value')" v-model="form.value" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.value" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.dataType') }}</label>
                        <select :aria-label="t('account.dataType')" v-model="form.data_type" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option v-for="dt in dataTypes" :key="dt" :value="dt">{{ t(`account.dataTypes.${dt}`) }}</option>
                        </select>
                        <InputError :message="form.errors.data_type" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.description') }}</label>
                        <input :aria-label="t('account.description')" v-model="form.description" type="text" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('account.status') }}</label>
                        <select :aria-label="t('account.status')" v-model="form.is_active" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option :value="true">{{ t('account.active') }}</option>
                            <option :value="false">{{ t('account.inactive') }}</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ t('account.cancel') }}</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">{{ form.processing ? t('account.saving') : t('account.save') }}</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>