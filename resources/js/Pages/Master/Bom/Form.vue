<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import InputError from '@/Components/InputError.vue';
import PartSelect from '@/Components/PartSelect.vue';
import type { Bom, BomItem, Machine, Part, Process } from '@/types';

const { t } = useI18n();

interface ItemRow {
    uid: number;
    sequence: number | '';
    process_id: number | '';
    machine_id: number | '';
    parent_part_id: number | '';
    parent_part_name: string;
    parent_qty: number | '';
    parent_uom: string;
    child_part_id: number | '';
    child_part_name: string;
    size: string;
    child_qty: number | '';
    uom_rm: string;
    special_code: string;
    source: string;
}

const props = defineProps<{
    bom: Bom | null;
    items?: BomItem[];
    fgParts: Array<Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'>>;
    materials: Array<Pick<Part, 'id' | 'part_number' | 'part_name'> & { uom?: { code?: string | null } | null }>;
    processes: Array<Pick<Process, 'id' | 'process_code' | 'process_name'>>;
    machines: Array<Pick<Machine, 'id' | 'machine_code' | 'machine_name'>>;
    uoms: string[];
}>();

const editing = computed(() => props.bom !== null);

let rowUid = 0;

const blankItem = (): ItemRow => ({
    uid: ++rowUid,
    sequence: '',
    process_id: '',
    machine_id: '',
    parent_part_id: '',
    parent_part_name: '',
    parent_qty: '',
    parent_uom: '',
    child_part_id: '',
    child_part_name: '',
    size: '',
    child_qty: '',
    uom_rm: '',
    special_code: '',
    source: '',
});

const seedItems = (): ItemRow[] => {
    if (props.items?.length) {
        return props.items.map((i) => ({
            uid: ++rowUid,
            sequence: i.sequence ?? '',
            process_id: i.process?.id ?? '',
            machine_id: i.machine?.id ?? '',
            parent_part_id: i.parent_part_id ?? i.parent_part?.id ?? '',
            parent_part_name: i.parent_part_name ?? '',
            parent_qty: i.parent_qty ?? '',
            parent_uom: i.parent_uom ?? '',
            child_part_id: i.child_part_id ?? i.child_part?.id ?? '',
            child_part_name: i.child_part_name ?? '',
            size: i.size ?? '',
            child_qty: i.child_qty ?? '',
            uom_rm: i.uom_rm ?? '',
            special_code: i.special_code ?? '',
            source: i.source ?? '',
        }));
    }

    return [blankItem()];
};

const rows = ref<ItemRow[]>(seedItems());

const form = useForm({
    part_id: props.bom?.part?.id != null ? String(props.bom.part.id) : '',
    version: props.bom?.version ?? '1.0',
    items: [] as ItemRow[],
});

function addRow() {
    rows.value.push(blankItem());
}

function rowHasData(r: ItemRow): boolean {
    return r.child_part_id !== ''
        || r.child_qty !== ''
        || r.parent_part_id !== ''
        || r.sequence !== ''
        || r.process_id !== ''
        || r.machine_id !== ''
        || r.size !== ''
        || r.uom_rm !== ''
        || r.special_code !== ''
        || r.source !== '';
}

function removeRow(i: number) {
    if (rows.value.length === 1) return;

    if (rowHasData(rows.value[i]) && !confirm(t('master.confirmRemoveItem'))) {
        return;
    }

    rows.value.splice(i, 1);
}

/** Pilih child part → isi nama dan UOM bila belum diisi. */
function onChildChange(r: ItemRow) {
    const part = props.materials.find((p) => p.id === Number(r.child_part_id));
    if (!part) return;
    if (r.child_part_name === '') r.child_part_name = part.part_name;
    if (r.uom_rm === '') r.uom_rm = part.uom?.code ?? '';
}

function onParentChange(r: ItemRow) {
    const part = props.materials.find((p) => p.id === Number(r.parent_part_id));
    if (!part) return;
    if (r.parent_part_name === '') r.parent_part_name = part.part_name;
    if (r.parent_uom === '') r.parent_uom = part.uom?.code ?? '';
}

function submit() {
    form.items = rows.value.map((r) => ({
        sequence: r.sequence === '' ? null : Number(r.sequence),
        process_id: r.process_id === '' ? null : Number(r.process_id),
        machine_id: r.machine_id === '' ? null : Number(r.machine_id),
        parent_part_id: r.parent_part_id === '' ? null : Number(r.parent_part_id),
        parent_part_name: r.parent_part_name,
        parent_qty: r.parent_qty === '' ? null : Number(r.parent_qty),
        parent_uom: r.parent_uom,
        child_part_id: r.child_part_id === '' ? null : Number(r.child_part_id),
        child_part_name: r.child_part_name,
        size: r.size,
        child_qty: r.child_qty === '' ? null : Number(r.child_qty),
        uom_rm: r.uom_rm,
        special_code: r.special_code,
        source: r.source,
    })) as any;

    if (props.bom) {
        form.put(route('boms.update', props.bom.id));
    } else {
        form.post(route('boms.store'));
    }
}

const fieldClass = 'mt-1 w-full rounded-lg border-borderline bg-surface px-3 py-2 text-sm text-ink-primary focus:border-primary focus:ring-primary';
</script>

<template>
    <Head :title="editing ? t('master.editBom') : t('master.addBom')" />
    <AppLayout>
        <BackButton :href="route('boms.index')" class="mb-4" />

        <h1 class="mb-2 text-2xl font-bold tracking-tight text-ink-primary">
            {{ editing ? t('master.editBom') : t('master.addBom') }}
        </h1>
        <p class="mb-6 text-sm text-ink-secondary">{{ t('master.bomFormHelp') }}</p>

        <form @submit.prevent="submit" class="space-y-5">
            <div class="rounded-xl border border-borderline bg-surface p-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.fgPart') }}</label>
                        <select :aria-label="t('master.fgPart')" v-model="form.part_id" :disabled="editing" :class="[fieldClass, editing ? 'cursor-not-allowed bg-background text-ink-secondary' : '']">
                            <option value="">{{ t('master.selectFgPart') }}</option>
                            <option v-for="p in fgParts" :key="p.id" :value="String(p.id)">{{ p.part_number }} · {{ p.part_name }}</option>
                        </select>
                        <p v-if="editing" class="mt-1 text-xs text-ink-secondary">{{ t('master.bomPartLocked') }}</p>
                        <InputError :message="form.errors.part_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-ink-secondary">{{ t('master.version') }}</label>
                        <input :aria-label="t('master.version')" v-model="form.version" type="text" :class="fieldClass" />
                        <InputError :message="form.errors.version" class="mt-1" />
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-borderline bg-surface">
                <div class="flex items-center justify-between gap-3 border-b border-borderline px-6 py-4">
                    <div>
                        <h2 class="text-base font-semibold text-ink-primary">{{ t('master.bomItems') }}</h2>
                        <p class="mt-0.5 text-xs text-ink-secondary">{{ t('master.bomItemsHelp') }}</p>
                    </div>
                    <button type="button" @click="addRow" class="inline-flex items-center gap-1.5 rounded-md border border-borderline px-3 py-2 text-sm font-medium text-ink-primary transition hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        {{ t('master.addItem') }}
                    </button>
                </div>

                <InputError :message="form.errors.items" class="px-6 pt-4" />

                <div v-for="(r, i) in rows" :key="r.uid" class="border-b border-borderline px-6 py-4 last:border-b-0">
                    <div class="mb-3 flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wide text-ink-secondary">{{ t('master.bomItem') }} {{ i + 1 }}</span>
                        <button type="button" @click="removeRow(i)" :disabled="rows.length === 1" class="text-xs font-semibold text-danger transition hover:underline disabled:cursor-not-allowed disabled:opacity-40">{{ t('master.removeItem') }}</button>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-2">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('master.sequence') }}</label>
                            <input :aria-label="t('master.sequence')" v-model="r.sequence" type="number" min="0" step="1" :class="fieldClass" />
                            <InputError :message="form.errors[`items.${i}.sequence`]" class="mt-1" />
                        </div>
                        <div class="sm:col-span-5">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('master.process') }}</label>
                            <select :aria-label="t('master.process')" v-model="r.process_id" :class="fieldClass">
                                <option value="">—</option>
                                <option v-for="p in processes" :key="p.id" :value="p.id">{{ p.process_name }}</option>
                            </select>
                            <InputError :message="form.errors[`items.${i}.process_id`]" class="mt-1" />
                        </div>
                        <div class="sm:col-span-5">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('master.machine') }}</label>
                            <select :aria-label="t('master.machine')" v-model="r.machine_id" :class="fieldClass">
                                <option value="">—</option>
                                <option v-for="m in machines" :key="m.id" :value="m.id">{{ m.machine_name }}</option>
                            </select>
                            <InputError :message="form.errors[`items.${i}.machine_id`]" class="mt-1" />
                        </div>

                        <div class="sm:col-span-6">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('master.parentPart') }}</label>
                            <PartSelect
                                :model-value="r.parent_part_id"
                                :options="materials"
                                :list-id="`bom-parent-${r.uid}`"
                                :aria-label="t('master.parentPart')"
                                :placeholder="t('master.searchPart')"
                                @update:model-value="r.parent_part_id = $event"
                                @change="onParentChange(r)"
                            />
                            <InputError :message="form.errors[`items.${i}.parent_part_id`]" class="mt-1" />
                        </div>
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('master.parentQty') }}</label>
                            <input :aria-label="t('master.parentQty')" v-model="r.parent_qty" type="number" step="any" min="0" :class="fieldClass" />
                            <InputError :message="form.errors[`items.${i}.parent_qty`]" class="mt-1" />
                        </div>
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('master.parentUom') }}</label>
                            <select :aria-label="t('master.parentUom')" v-model="r.parent_uom" :class="fieldClass">
                                <option value="">—</option>
                                <option v-for="u in uoms" :key="u" :value="u">{{ u }}</option>
                            </select>
                            <InputError :message="form.errors[`items.${i}.parent_uom`]" class="mt-1" />
                        </div>

                        <div class="sm:col-span-6">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('master.childPart') }} <span class="text-danger">*</span></label>
                            <PartSelect
                                :model-value="r.child_part_id"
                                :options="materials"
                                :list-id="`bom-child-${r.uid}`"
                                :aria-label="t('master.childPart')"
                                :placeholder="t('master.searchPart')"
                                @update:model-value="r.child_part_id = $event"
                                @change="onChildChange(r)"
                            />
                            <InputError :message="form.errors[`items.${i}.child_part_id`]" class="mt-1" />
                        </div>
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('master.childQty') }} <span class="text-danger">*</span></label>
                            <input :aria-label="t('master.childQty')" v-model="r.child_qty" type="number" step="any" min="0" :class="fieldClass" />
                            <InputError :message="form.errors[`items.${i}.child_qty`]" class="mt-1" />
                        </div>
                        <div class="sm:col-span-3">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('master.uomRm') }}</label>
                            <select :aria-label="t('master.uomRm')" v-model="r.uom_rm" :class="fieldClass">
                                <option value="">—</option>
                                <option v-for="u in uoms" :key="u" :value="u">{{ u }}</option>
                            </select>
                            <InputError :message="form.errors[`items.${i}.uom_rm`]" class="mt-1" />
                        </div>

                        <div class="sm:col-span-4">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('master.size') }}</label>
                            <input :aria-label="t('master.size')" v-model="r.size" type="text" :class="fieldClass" />
                            <InputError :message="form.errors[`items.${i}.size`]" class="mt-1" />
                        </div>
                        <div class="sm:col-span-4">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('master.specialCode') }}</label>
                            <input :aria-label="t('master.specialCode')" v-model="r.special_code" type="text" :class="fieldClass" />
                            <InputError :message="form.errors[`items.${i}.special_code`]" class="mt-1" />
                        </div>
                        <div class="sm:col-span-4">
                            <label class="text-xs font-semibold text-ink-secondary">{{ t('master.source') }}</label>
                            <input :aria-label="t('master.source')" v-model="r.source" type="text" :class="fieldClass" />
                            <InputError :message="form.errors[`items.${i}.source`]" class="mt-1" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <BackButton :href="route('boms.index')">{{ t('master.cancel') }}</BackButton>
                <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-5 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface">
                    {{ form.processing ? t('master.saving') : t('master.save') }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
