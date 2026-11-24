<?php

namespace App\Http\Controllers;

use App\Models\BomItem;
use App\Models\Machine;
use App\Models\MachineCycleTime;
use App\Models\Part;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MachineCycleTimeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', MachineCycleTime::class);

        $selectedMachineId = $request->filled('machine_id') ? (int) $request->input('machine_id') : null;

        $cycleTimes = MachineCycleTime::query()
            ->with(['machine:id,machine_code,machine_name', 'part:id,part_number,part_name,model'])
            ->when($selectedMachineId !== null, fn ($q) => $q->where('machine_id', $selectedMachineId))
            ->when($request->input('search'), function ($q, $search) {
                $q->where(fn ($w) => $w
                    ->whereHas('machine', fn ($m) => $m
                        ->where('machine_name', 'ilike', "%{$search}%")
                        ->orWhere('machine_code', 'ilike', "%{$search}%"))
                    ->orWhereHas('part', fn ($p) => $p
                        ->where('part_number', 'ilike', "%{$search}%")
                        ->orWhere('part_name', 'ilike', "%{$search}%")
                        ->orWhere('model', 'ilike', "%{$search}%")));
            })
            ->orderBy('machine_id')
            ->orderBy('part_id')
            ->paginate(25)
            ->withQueryString();

        $machines = Machine::query()
            ->where('is_active', true)
            ->whereRaw("UPPER(COALESCE(machine_code, '')) <> 'SUBCON' AND LOWER(COALESCE(machine_name, '')) NOT LIKE '%subcon%'")
            ->orderByRaw('sequence ASC NULLS LAST')
            ->orderBy('machine_name')
            ->get(['id', 'machine_code', 'machine_name']);

        $machineRows = $selectedMachineId !== null
            ? $this->buildMachineMatrixRows($selectedMachineId)
            : [];

        return Inertia::render('Master/CycleTime/Index', [
            'cycleTimes' => $cycleTimes,
            'filters' => [
                'search' => $request->input('search'),
                'machine_id' => $selectedMachineId !== null ? (string) $selectedMachineId : null,
            ],
            'machines' => $machines,
            'parts' => Part::query()
                ->where('is_active', true)
                ->orderBy('part_number')
                ->get(['id', 'part_number', 'part_name', 'model']),
            'machineRows' => $machineRows,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', MachineCycleTime::class);

        $data = $request->validate($this->rules($request), $this->messages());

        MachineCycleTime::create($data + [
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('cycle-times.index')->with('success', __('Cycle time created.'));
    }

    public function update(Request $request, MachineCycleTime $cycleTime): RedirectResponse
    {
        Gate::authorize('update', $cycleTime);

        $data = $request->validate($this->rules($request, $cycleTime), $this->messages());

        $cycleTime->update($data + ['updated_by' => $request->user()?->id]);

        return redirect()->route('cycle-times.index')->with('success', __('Cycle time updated.'));
    }

    public function destroy(MachineCycleTime $cycleTime): RedirectResponse
    {
        Gate::authorize('delete', $cycleTime);

        $cycleTime->delete();

        return redirect()->route('cycle-times.index')->with('success', __('Cycle time deleted.'));
    }

    /**
     * Simpan massal Tact Time & QDC per mesin (satu halaman satu mesin).
     * Baris dengan tact time kosong akan dihapus (atau diabaikan jika belum ada).
     */
    public function bulkSave(Request $request): RedirectResponse
    {
        Gate::authorize('create', MachineCycleTime::class);

        $data = $request->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'rows' => ['required', 'array'],
            'rows.*.part_id' => ['required', 'integer', 'exists:parts,id'],
            'rows.*.cycle_time_seconds' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
            'rows.*.qdc_minutes' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'rows.*.is_active' => ['sometimes', 'boolean'],
        ]);

        $machineId = (int) $data['machine_id'];
        $actorId = $request->user()?->id;

        DB::transaction(function () use ($machineId, $data, $actorId): void {
            foreach ($data['rows'] as $row) {
                $partId = (int) $row['part_id'];
                $seconds = $row['cycle_time_seconds'] ?? null;
                $qdc = $row['qdc_minutes'] ?? null;

                if ($seconds === null || $seconds === '') {
                    MachineCycleTime::query()
                        ->where('machine_id', $machineId)
                        ->where('part_id', $partId)
                        ->delete();

                    continue;
                }

                MachineCycleTime::updateOrCreate(
                    [
                        'machine_id' => $machineId,
                        'part_id' => $partId,
                    ],
                    [
                        'cycle_time_seconds' => (float) $seconds,
                        'qdc_minutes' => ($qdc !== null && $qdc !== '') ? (float) $qdc : null,
                        'is_active' => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ],
                );
            }
        });

        return redirect()
            ->route('cycle-times.index', ['machine_id' => $machineId])
            ->with('success', __('Cycle time updated.'));
    }

    /**
     * Daftar part (WIP/FG) yang terhubung ke satu mesin dari BOM aktif maupun
     * record `machine_cycle_times` yang sudah ada, agar PPIC bisa mengisi
     * Tact Time & QDC sekaligus per mesin.
     *
     * @return list<array<string, mixed>>
     */
    private function buildMachineMatrixRows(int $machineId): array
    {
        $existing = MachineCycleTime::query()
            ->where('machine_id', $machineId)
            ->with('part:id,part_number,part_name,model')
            ->get()
            ->keyBy('part_id');

        $bomRows = BomItem::query()
            ->where('machine_id', $machineId)
            ->whereNotNull('parent_part_id')
            ->whereHas('bom', fn ($q) => $q->where('is_active', true))
            ->with([
                'bom.part:id,part_number,part_name,model',
                'process:id,process_name',
                'parentPart:id,part_number,part_name,model',
            ])
            ->orderBy('bom_id')
            ->orderBy('sequence')
            ->get();

        $rowsByPart = [];

        foreach ($bomRows as $item) {
            $partId = (int) $item->parent_part_id;
            if (isset($rowsByPart[$partId])) {
                continue;
            }

            $saved = $existing->get($partId);
            $fgPart = $item->bom?->part;

            $rowsByPart[$partId] = [
                'part_id' => $partId,
                'part_number' => $item->parentPart?->part_number,
                'part_name' => $item->parent_part_name ?: $item->parentPart?->part_name,
                'model' => $item->parentPart?->model ?: $fgPart?->model,
                'fg_part_number' => $fgPart?->part_number,
                'fg_part_name' => $fgPart?->part_name,
                'fg_model' => $fgPart?->model,
                'process_name' => $item->process?->process_name,
                'cycle_time_seconds' => $saved?->cycle_time_seconds,
                'qdc_minutes' => $saved?->qdc_minutes,
                'is_active' => $saved ? (bool) $saved->is_active : true,
            ];
        }

        // Sertakan juga record existing yang belum muncul di BOM aktif.
        foreach ($existing as $partId => $saved) {
            if (isset($rowsByPart[$partId])) {
                continue;
            }

            $rowsByPart[$partId] = [
                'part_id' => (int) $partId,
                'part_number' => $saved->part?->part_number,
                'part_name' => $saved->part?->part_name,
                'model' => $saved->part?->model,
                'fg_part_number' => null,
                'fg_part_name' => null,
                'fg_model' => null,
                'process_name' => null,
                'cycle_time_seconds' => $saved->cycle_time_seconds,
                'qdc_minutes' => $saved->qdc_minutes,
                'is_active' => (bool) $saved->is_active,
            ];
        }

        return array_values($rowsByPart);
    }

    /**
     * Unik per pasangan (machine_id, part_id) — diwakili lewat kolom machine_id.
     *
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?MachineCycleTime $ignore = null): array
    {
        return [
            'machine_id' => [
                'required', 'integer', 'exists:machines,id',
                Rule::unique('machine_cycle_times', 'machine_id')
                    ->where(fn ($q) => $q->where('part_id', (int) $request->input('part_id')))
                    ->ignore($ignore?->id),
            ],
            'part_id' => ['required', 'integer', 'exists:parts,id'],
            'cycle_time_seconds' => ['required', 'numeric', 'gt:0'],
            'qdc_minutes' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'machine_id.unique' => __('Kombinasi mesin + part ini sudah punya cycle time.'),
        ];
    }
}
