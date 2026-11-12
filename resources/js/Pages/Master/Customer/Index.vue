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

const { t } = useI18n();

interface Customer {
    id: number;
    customer_code: string;
    customer_name: string;
    address: string | null;
    phone: string | null;
    email: string | null;
    is_active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

const props = defineProps<{
    customers: Paginated<Customer>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('customers.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
}

const form = useForm({
    customer_code: '',
    customer_name: '',
    address: '',
    phone: '',
    email: '',
    is_active: true,
});
const showForm = ref(false);
const editing = ref<Customer | null>(null);

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}

function openEdit(c: Customer) {
    editing.value = c;
    form.clearErrors();
    form.customer_code = c.customer_code;
    form.customer_name = c.customer_name;
    form.address = c.address ?? '';
    form.phone = c.phone ?? '';
    form.email = c.email ?? '';
    form.is_active = c.is_active;
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    editing.value = null;
}

function submit() {
    if (editing.value) {
        form.put(route('customers.update', editing.value.id), { onSuccess: () => closeForm() });
    } else {
        form.post(route('customers.store'), { onSuccess: () => closeForm() });
    }
}

function remove(c: Customer) {
    if (confirm(t('outgoing.deleteCustomerConfirm', { name: c.customer_name }))) {
        router.delete(route('customers.destroy', c.id));
    }
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('outgoing.customerTitle') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('outgoing.customerSubtitle') }}</p>
            </div>
            <button
                @click="openCreate"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ t('outgoing.add') }}
            </button>
        </div>

        <input
            v-model="search"
            @input="doSearch"
            type="search"
            :placeholder="t('outgoing.customerSearch')"
            :aria-label="t('outgoing.customerSearch')"
            class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80"
        />

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.customerCode') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.customerName') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.customerContact') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('outgoing.customerAddress') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.status') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ t('master.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="c in customers.data" :key="c.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3 font-mono font-medium text-ink-primary">{{ c.customer_code }}</td>
                            <td class="px-4 py-3 text-ink-primary">{{ c.customer_name }}</td>
                            <td class="px-4 py-3 text-ink-secondary">
                                <div>{{ c.phone ?? '—' }}</div>
                                <div class="text-xs">{{ c.email ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 text-ink-secondary">{{ c.address ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <StatusBadge :tone="c.is_active ? 'success' : 'warning'">{{ c.is_active ? t('master.active') : t('master.inactive') }}</StatusBadge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <ActionButton :label="t('master.edit')" variant="edit" @click="openEdit(c)" />
                                    <ActionButton :label="t('master.delete')" variant="delete" @click="remove(c)" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="customers.data.length === 0">
                            <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('outgoing.customerEmpty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Pagination :links="customers.links" />

        <Modal :show="showForm" max-width="md" @close="closeForm">
            <form @submit.prevent="submit" class="p-6">
                <h2 class="mb-4 text-base font-semibold text-ink-primary">
                    {{ editing ? t('outgoing.editCustomer') : t('outgoing.addCustomer') }}
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.customerCode') }}</label>
                        <input v-model="form.customer_code" type="text" :aria-label="t('outgoing.customerCode')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm font-mono text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.customer_code" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.customerName') }}</label>
                        <input v-model="form.customer_name" type="text" :aria-label="t('outgoing.customerName')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.customer_name" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.customerAddress') }}</label>
                        <input v-model="form.address" type="text" :aria-label="t('outgoing.customerAddress')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.address" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.customerPhone') }}</label>
                        <input v-model="form.phone" type="text" :aria-label="t('outgoing.customerPhone')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.phone" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('outgoing.customerEmail') }}</label>
                        <input v-model="form.email" type="email" :aria-label="t('outgoing.customerEmail')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.status') }}</label>
                        <select v-model="form.is_active" :aria-label="t('master.status')" class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                            <option :value="true">{{ t('master.active') }}</option>
                            <option :value="false">{{ t('master.inactive') }}</option>
                        </select>
                    </div>
                </div>
                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" @click="closeForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-primary hover:bg-background">{{ t('master.cancel') }}</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                        {{ form.processing ? t('master.saving') : t('master.save') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>