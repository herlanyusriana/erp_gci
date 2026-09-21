<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\MachineCycleTime;
use App\Models\Part;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MachineCycleTimeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', MachineCycleTime::class);

        $cycleTimes = MachineCycleTime::query()
            ->with(['machine:id,machine_code,machine_name', 'part:id,part_number,part_name'])
            ->when($request->input('search'), function ($q, $search) {
                $q->where(fn ($w) => $w
                    ->whereHas('machine', fn ($m) => $m
                        ->where('machine_name', 'ilike', "%{$search}%")
                        ->orWhere('machine_code', 'ilike', "%{$search}%"))
                    ->orWhereHas('part', fn ($p) => $p
                        ->where('part_number', 'ilike', "%{$search}%")
                        ->orWhere('part_name', 'ilike', "%{$search}%")));
            })
            ->orderBy('machine_id')
            ->orderBy('part_id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Master/CycleTime/Index', [
            'cycleTimes' => $cycleTimes,
            'filters' => $request->only('search'),
            'machines' => Machine::query()
                ->where('is_active', true)
                ->whereRaw("UPPER(COALESCE(machine_code, '')) <> 'SUBCON' AND LOWER(COALESCE(machine_name, '')) NOT LIKE '%subcon%'")
                ->orderBy('machine_name')
                ->get(['id', 'machine_code', 'machine_name']),
            'parts' => Part::query()
                ->where('is_active', true)
                ->orderBy('part_number')
                ->get(['id', 'part_number', 'part_name']),
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
