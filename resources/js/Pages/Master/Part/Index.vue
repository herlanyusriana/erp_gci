<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref, computed, watch } from 'vue';
import { router, useForm, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import ActionButton from '@/Components/ActionButton.vue';
import Pagination from '@/Components/Pagination.vue';
import type { Part, PartType, Paginated, PageProps } from '@/types';
import StatusBadge from '@/Components/StatusBadge.vue';

const { t } = useI18n();

const page = usePage<PageProps>();

const props = defineProps<{
    parts: Paginated<Part>;
    filters: { search?: string; type?: string };
    partTypes: PartType[];
}>();

const search = ref(props.filters.search ?? '');
const type = ref(props.filters.type ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;
watch([search, type], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('parts.index'), {
            search: search.value || undefined,
            type: type.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
});

const perms = computed<{ create: boolean; update: boolean; delete: boolean }>(() => {
    const list: string[] = page.props.auth.permissions ?? [];
    return {
        create: list.some((p) => ['part.create', 'master.part.create'].includes(p)),
        update: list.some((p) => ['part.update', 'master.part.update'].includes(p)),
        delete: list.some((p) => ['part.delete', 'master.part.delete'].includes(p)),
    };
});

const newMenuOpen = ref(false);
function toggleNewMenu() {
    newMenuOpen.value = !newMenuOpen.value;
}

const deleteForm = useForm({});
function destroy(part: Part) {
    if (confirm(t('master.deletePart', { name: part.part_number }))) {
        deleteForm.delete(route('parts.destroy', part.id));
    }
}

function partTypeLabel(partType: PartType | null): string {
    switch (partType?.code) {
        case 'FG': return t('master.finishedGoods');
        case 'MATERIAL': return t('master.material');
        case 'WIP': return t('master.wip');
        default: return partType?.name ?? '';
    }
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('master-data')" class="mb-4" />

        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('master.partTitle') }}</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ t('master.partSubtitle') }} <code class="rounded bg-primary-light px-1">part_type</code>
                </p>
            </div>
            <div v-if="perms.create" class="relative">
                <button @click="toggleNewMenu" class="inline-flex items-center gap-1 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">
                    + {{ t('master.newPart') }}
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                </button>
                <div v-if="newMenuOpen" class="absolute right-0 z-10 mt-2 w-56 overflow-hidden rounded-lg border border-borderline bg-surface shadow-lg">
                    <Link
                        v-for="partType in partTypes"
                        :key="partType.id"
                        :href="route('parts.create', { type: partType.code })"
                        class="flex items-center justify-between px-4 py-2.5 text-sm text-ink-primary transition hover:bg-primary-light"
                    >
                        <span class="font-medium">{{ partTypeLabel(partType) }}</span>
                        <span class="text-xs text-ink-secondary">{{ partType.code }}</span>
                    </Link>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="mb-4 flex flex-wrap gap-3">
            <input v-model="search" type="search" :placeholder="t('master.partSearch')" :aria-label="t('master.partSearch')" class="w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary placeholder-ink-secondary focus:border-primary focus:ring-primary sm:w-72" />
            <select :aria-label="t('master.allTypes')" v-model="type" class="rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                <option value="">{{ t('master.allTypes') }}</option>
                <option v-for="partType in partTypes" :key="partType.id" :value="partType.code">{{ partTypeLabel(partType) }}</option>
            </select>
        </div>

        <!-- Table -->
        <div class="overflow-hidden rounded-xl border border-borderline bg-surface">
            <table class="min-w-full divide-y divide-borderline text-sm">
                <thead class="bg-background">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        <th scope="col" class="px-4 py-3">{{ t('master.partNumber') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.name') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.hsCode') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.type') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.model') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.uom') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.size') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('master.netWeight') }}</th>
                        <th scope="col" class="px-4 py-3">{{ t('master.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ t('master.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borderline">
                    <tr v-for="p in parts.data" :key="p.id" class="hover:bg-primary-light/40">
                        <td class="px-4 py-3 font-medium text-ink-primary">{{ p.part_number }}</td>
                        <td class="px-4 py-3 text-ink-primary">{{ p.part_name }}</td>
                        <td class="px-4 py-3 text-ink-secondary">{{ p.hs_code ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-md bg-primary-light px-2 py-0.5 text-xs font-semibold text-primary">{{ p.part_type?.code }}</span>
                        </td>
                        <td class="px-4 py-3 text-ink-secondary">{{ p.model ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-secondary">{{ p.uom?.code ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-secondary">{{ p.size ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-secondary">{{ p.nett_weight ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge :tone="p.is_active ? 'success' : 'warning'">{{ p.is_active ? t('master.active') : t('master.inactive') }}</StatusBadge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <ActionButton v-if="perms.update" :href="route('parts.edit', p.id)" :label="t('master.edit')" variant="edit" />
                                <ActionButton v-if="perms.delete" :label="t('master.delete')" variant="delete" @click="destroy(p)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="parts.data.length === 0">
                        <td colspan="10" class="px-4 py-12 text-center text-sm text-ink-secondary">{{ t('master.partEmpty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="parts.links" />
    </AppLayout>
</template>