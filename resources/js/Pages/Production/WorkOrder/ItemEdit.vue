<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import type { WorkOrderItem, WorkOrderMachine } from '@/types';

const props = defineProps<{
    workOrder: { id: number; wo_no: string };
    item: WorkOrderItem;
    machines: WorkOrderMachine[];
}>();

const form = useForm({
    selected_part_id: String(props.item.selected_part_id || props.item.child_part_id || ''),
    machine_id: String(props.item.machine_id || ''),
});

const materialOptions = computed(() => {
    const rows = [];
    if (props.item.child_part) {
        rows.push({
            id: props.item.child_part.id,
            part_number: props.item.child_part.part_number,
            part_name: props.item.child_part.part_name,
            kind: 'Main Material',
        });
    }
    for (const s of props.item.child_part?.partSubstitutes ?? []) {
        const sp = s.substitute_part;
        if (!sp) continue;
        rows.push({
            id: sp.id,
            part_number: sp.part_number,
            part_name: sp.part_name,
            kind: 'Substitute',
        });
    }
    return rows;
});

const selected = computed(() => materialOptions.value.find((o) => String(o.id) === String(form.selected_part_id)) ?? null);

const query = ref('');
const showSuggestions = ref(false);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    const list = materialOptions.value;
    if (!q) return list.slice(0, 50);
    return list.filter((o) => [o.part_number, o.part_name, o.kind]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(q))).slice(0, 50);
});

function selectOption(opt: (typeof materialOptions.value)[number]) {
    form.selected_part_id = String(opt.id);
    query.value = `${opt.part_number} · ${opt.part_name} (${opt.kind})`;
    showSuggestions.value = false;
}

function submit() {
    form.patch(route('work-orders.items.update', [props.workOrder.id, props.item.id]));
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-2xl">
            <BackButton :href="route('work-orders.show', workOrder.id)" class="mb-4" />

            <div class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">Edit Routing WO</h1>
                <p class="mt-1 text-sm text-ink-secondary">
                    {{ workOrder.wo_no }} · {{ item.process?.process_name ?? item.child_part_name ?? '—' }}
                </p>
            </div>

            <form @submit.prevent="submit" class="space-y-6 rounded-xl border border-borderline bg-surface p-6">
                <!-- Material / Substitute -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">Material / Substitute</label>
                    <div class="relative">
                        <input
                            v-model="query"
                            type="text"
                            autocomplete="off"
                            :placeholder="selected ? selected.part_number : 'Cari main material atau substitute...'"
                            @focus="showSuggestions = true"
                            @input="showSuggestions = true"
                            class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <div v-if="showSuggestions" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-borderline bg-surface py-1 shadow-lg">
                            <button
                                v-for="o in filtered"
                                :key="o.id"
                                type="button"
                                @mousedown.prevent="selectOption(o)"
                                class="block w-full px-3 py-2 text-left text-sm hover:bg-primary-light"
                            >
                                <span class="font-medium text-ink-primary">{{ o.part_number }}</span>
                                <span class="text-ink-secondary"> · {{ o.part_name }}</span>
                                <span :class="o.kind === 'Main Material' ? 'text-primary' : 'text-purple-700'" class="ml-2 text-xs font-semibold">{{ o.kind }}</span>
                            </button>
                            <div v-if="filtered.length === 0" class="px-3 py-3 text-sm text-ink-secondary">Tidak ada material yang cocok.</div>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-ink-secondary">Main material BOM beserta substitute aktif yang bisa dipilih untuk WO ini.</p>
                    <div v-if="form.errors.selected_part_id" class="mt-1 text-xs text-danger">{{ form.errors.selected_part_id }}</div>
                </div>

                <!-- Machine -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">Mesin</label>
                    <select v-model="form.machine_id" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option value="">— Pilih mesin —</option>
                        <option v-for="m in machines" :key="m.id" :value="m.id">{{ m.machine_code }} · {{ m.machine_name }}</option>
                    </select>
                    <div v-if="form.errors.machine_id" class="mt-1 text-xs text-danger">{{ form.errors.machine_id }}</div>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-borderline pt-4">
                    <a :href="route('work-orders.show', workOrder.id)" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background">Batal</a>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                        {{ form.processing ? 'Menyimpan…' : 'Simpan' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>