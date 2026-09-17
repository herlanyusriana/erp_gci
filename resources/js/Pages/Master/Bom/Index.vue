<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { watch, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import type { Bom, Paginated } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    boms: Paginated<Bom>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('boms.index'), {
            search: search.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
});

const fmtQty = (n: number | null) => (n == null ? '—' : Number(n));
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.bomTitle') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ t('master.bomSubtitle') }}
                </p>
            </div>
        </div>

        <div class="mb-4">
            <input v-model="search" type="search" :placeholder="t('master.bomSearch')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary placeholder-ink-secondary focus:border-primary focus:ring-primary sm:w-96" />
        </div>

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">{{ t('master.number') }}</th>
                        <th class="px-4 py-3">{{ t('master.fgPartNumber') }}</th>
                        <th class="px-4 py-3">{{ t('master.fgName') }}</th>
                        <th class="px-4 py-3">{{ t('master.model') }}</th>
                        <th class="px-4 py-3 text-center">{{ t('master.itemCount') }}</th>
                        <th class="px-4 py-3">{{ t('master.status') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('master.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="b in boms.data" :key="b.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 text-ink-secondary">{{ b.bom_no ?? b.id }}</td>
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ b.part?.part_number }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ b.part?.part_name }}</td>
                        <td class="px-4 py-3 text-ink-secondary">{{ b.part?.model ?? '—' }}</td>
                        <td class="px-4 py-3 text-center tabular-nums text-ink-secondary">{{ b.items_count ?? b.items?.length ?? 0 }}</td>
                        <td class="px-4 py-3">
                            <span :class="b.is_active ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'" class="rounded-md px-2 py-0.5 text-xs font-semibold">
                                {{ b.is_active ? t('master.active') : t('master.inactive') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton :href="route('boms.show', b.id)" :label="t('master.view')" variant="view" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="boms.data.length === 0">
                        <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.bomEmpty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="boms.links" />
    </AppLayout>
</template>