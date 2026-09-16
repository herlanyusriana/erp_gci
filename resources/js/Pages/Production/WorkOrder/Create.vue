<script setup lang="ts">
import { ref } from 'vue';
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

function submit() {
    form.post(route('work-orders.store'), {
        onSuccess: () => form.reset(),
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
                    <select v-model="form.part_id" required class="w-full rounded-lg border-borderline bg-background px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary">
                        <option value="" disabled>— Pilih FG —</option>
                        <option v-for="p in fgParts" :key="p.id" :value="p.id">
                            {{ p.part_number }} · {{ p.part_name }}
                        </option>
                    </select>
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
                    <button type="button" @click="form.reset()" class="rounded-md border border-borderline px-4 py-2 text-sm font-medium text-ink-secondary transition hover:bg-background">Reset</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                        {{ form.processing ? 'Menyimpan…' : 'Simpan WO' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>