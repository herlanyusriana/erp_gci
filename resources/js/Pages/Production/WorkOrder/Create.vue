<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import type { Part } from '@/types';

const props = defineProps<{
    fgParts: Array<Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'>>;
}>();

const form = useForm({
    part_id: '',
    qty: '',
    planned_date: '',
    remarks: '',
});

const fgQuery = ref('');
const showSuggestions = ref(false);
const selectedFg = computed(() => props.fgParts.find((p) => String(p.id) === String(form.part_id)) ?? null);
const filteredFgParts = computed(() => {
    const query = fgQuery.value.trim().toLowerCase();
    if (!query) return props.fgParts.slice(0, 25);
    return props.fgParts.filter((p) => [p.part_number, p.part_name, p.model]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(query))).slice(0, 25);
});

function selectFg(part: (typeof props.fgParts)[number]) {
    form.part_id = String(part.id);
    fgQuery.value = `${part.part_number} · ${part.part_name} · ${part.model || '—'}`;
    showSuggestions.value = false;
}

function clearFg() {
    form.part_id = '';
    fgQuery.value = '';
    showSuggestions.value = true;
}

function resetForm() {
    form.reset();
    fgQuery.value = '';
    showSuggestions.value = false;
}

function submit() {
    form.post(route('work-orders.store'), {
        onSuccess: resetForm,
    });
}
</script>

<template>
    <AppLayout>
        <BackButton :href="route('work-orders.index')" class="mb-4" />

        <div class="mx-auto max-w-xl">
            <div class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight text-ink-primary">Buat Work Order</h1>
                <p class="mt-1 text-sm text-ink-secondary">Pilih Finished Good, tentukan qty, dan tanggal rencana produksi.</p>
            </div>

            <form @submit.prevent="submit" class="space-y-5 rounded-xl border border-borderline bg-surface p-6">
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">Finished Good</label>
                    <div class="relative">
                        <input
                            v-model="fgQuery"
                            type="text"
                            required
                            autocomplete="off"
                            placeholder="Ketik part number, nama, atau model..."
                            @focus="showSuggestions = true"
                            @input="showSuggestions = true; form.part_id = ''"
                            class="w-full rounded-lg border-borderline bg-background px-3 py-2 pr-20 text-sm text-ink-primary focus:border-primary focus:ring-primary"
                        />
                        <button v-if="selectedFg" type="button" @click="clearFg" class="absolute inset-y-0 right-2 my-auto h-7 rounded px-2 text-xs text-ink-secondary hover:bg-background">Ganti</button>
                        <div v-if="showSuggestions" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-borderline bg-surface py-1 shadow-lg">
                            <button
                                v-for="p in filteredFgParts"
                                :key="p.id"
                                type="button"
                                @mousedown.prevent="selectFg(p)"
                                class="block w-full px-3 py-2 text-left text-sm hover:bg-primary-light"
                            >
                                <span class="font-medium text-ink-primary">{{ p.part_number }}</span>
                                <span class="text-ink-secondary"> · {{ p.part_name }} · {{ p.model || '—' }}</span>
                            </button>
                            <div v-if="filteredFgParts.length === 0" class="px-3 py-3 text-sm text-ink-secondary">FG tidak ditemukan.</div>
                        </div>
                    </div>
                    <div v-if="form.errors.part_id" class="mt-1 text-xs text-danger">{{ form.errors.part_id }}</div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">Qty (PCS)</label>
                    <input v-model="form.qty" type="number" step="any" min="0.0001" required class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <div v-if="form.errors.qty" class="mt-1 text-xs text-danger">{{ form.errors.qty }}</div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">Tanggal Rencana</label>
                    <input v-model="form.planned_date" type="date" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary" />
                    <div v-if="form.errors.planned_date" class="mt-1 text-xs text-danger">{{ form.errors.planned_date }}</div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-primary">Keterangan</label>
                    <textarea v-model="form.remarks" rows="3" class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary"></textarea>
                    <div v-if="form.errors.remarks" class="mt-1 text-xs text-danger">{{ form.errors.remarks }}</div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" @click="resetForm" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background">Reset</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                        {{ form.processing ? 'Menyimpan…' : 'Simpan WO' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>