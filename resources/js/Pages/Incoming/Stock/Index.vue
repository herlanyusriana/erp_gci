<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import Pagination from '@/Components/Pagination.vue';
import type { PartStock, Paginated } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    stocks: Paginated<PartStock>;
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
function doSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('stocks.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
}

const totalQty = () => props.stocks.data.reduce((s, st) => s + Number(st.qty ?? 0), 0);
</script>

<template>
    <Head :title="t('incoming.stock')" />
    <AppLayout>
        <BackButton :href="route('incoming-data')" class="mb-4" />

        <div class="mb-6 flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('incoming.stock') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">{{ t('incoming.stockDescription') }}</p>
            </div>
            <div class="rounded-xl border border-borderline bg-surface px-4 py-2 text-sm">
                <span class="text-ink-secondary">{{ t('incoming.total') }} </span>
                <span class="font-semibold tabular-nums text-ink-primary">{{ totalQty() }}</span>
            </div>
        </div>

        <input v-model="search" @input="doSearch" type="search" :placeholder="t('incoming.searchStock')" class="mb-4 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary sm:w-80" />

        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th class="px-4 py-3">{{ t('incoming.part') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.type') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.tag') }}</th>
                        <th class="px-4 py-3 text-right">{{ t('incoming.qty') }}</th>
                        <th class="px-4 py-3">{{ t('incoming.unit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="st in stocks.data" :key="st.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ st.part?.part_number }} · {{ st.part?.part_name }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ st.part?.part_type?.name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ st.tag ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-ink-primary">{{ st.qty }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ st.qty_unit ?? '—' }}</td>
                    </tr>
                    <tr v-if="!stocks.data.length">
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('incoming.noStock') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="stocks.links" />
    </AppLayout>
</template>