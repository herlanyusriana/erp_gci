<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import Pagination from '@/Components/Pagination.vue';
import InputError from '@/Components/InputError.vue';
import type { IncomingReceive, Paginated } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    receives: Paginated<IncomingReceive>;
    locations: Array<{ code: string; name: string }>;
}>();

const selected = ref<number[]>([]);

const form = useForm({
    location_code: '',
    receive_ids: [] as number[],
});

const allSelected = computed(() => props.receives.data.length > 0 && selected.value.length === props.receives.data.length);

function toggle(id: number) {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((value) => value !== id)
        : [...selected.value, id];
}

function toggleAll() {
    selected.value = allSelected.value ? [] : props.receives.data.map((receive) => receive.id);
}

function assign() {
    form.receive_ids = selected.value;
    form.post(route('locations.setup.assign'), {
        preserveScroll: true,
        onSuccess: () => { selected.value = []; form.reset(); },
    });
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('locations.index')" class="mb-4" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.setupTitle') }}</h1>
            <p class="mt-1 text-sm text-ink-secondary">{{ t('master.setupSubtitle') }}</p>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-borderline bg-surface p-4">
            <div class="min-w-56 flex-1">
                <label class="text-xs font-semibold text-ink-secondary">{{ t('master.setupSelectLocation') }}</label>
                <select
                    :aria-label="t('master.setupSelectLocation')"
                    v-model="form.location_code"
                    class="mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                >
                    <option value="">{{ t('master.setupSelectLocation') }}</option>
                    <option v-for="loc in locations" :key="loc.code" :value="loc.code">{{ loc.code }} — {{ loc.name }}</option>
                </select>
                <InputError :message="form.errors.location_code" class="mt-1" />
            </div>
            <button
                type="button"
                :disabled="form.processing || selected.length === 0 || form.location_code === ''"
                class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
                @click="assign"
            >
                {{ t('master.setupAssign') }}
            </button>
            <span class="text-xs text-ink-secondary">{{ t('master.setupSelected', { n: selected.length }) }}</span>
            <InputError :message="form.errors.receive_ids" class="w-full" />
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
                        <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-borderline text-sm">
                    <thead class="bg-background">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                            <th scope="col" class="w-12 px-4 py-3">
                                <input
                                    type="checkbox"
                                    :aria-label="t('master.setupAssign')"
                                    :checked="allSelected"
                                    @change="toggleAll"
                                    class="rounded border-borderline text-primary focus:ring-primary"
                                />
                            </th>
                            <th scope="col" class="px-4 py-3">{{ t('master.setupTag') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.setupPart') }}</th>
                            <th scope="col" class="px-4 py-3">{{ t('master.setupInvoice') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-borderline">
                        <tr v-for="receive in receives.data" :key="receive.id" class="hover:bg-primary-light/40">
                            <td class="px-4 py-3">
                                <input
                                    type="checkbox"
                                    :aria-label="receive.tag ?? ''"
                                    :checked="selected.includes(receive.id)"
                                    @change="toggle(receive.id)"
                                    class="rounded border-borderline text-primary focus:ring-primary"
                                />
                            </td>
                            <td class="px-4 py-3 font-medium text-ink-primary">{{ receive.tag ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-primary">
                                {{ receive.part?.part_number ?? '—' }}
                                <span class="text-ink-secondary">{{ receive.part?.part_name ?? '' }}</span>
                            </td>
                            <td class="px-4 py-3 text-ink-secondary">{{ receive.invoice_no ?? '—' }}</td>
                        </tr>
                        <tr v-if="receives.data.length === 0">
                            <td colspan="4" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.setupEmpty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Pagination :links="receives.links" />
    </AppLayout>
</template>
