<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\Part;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanItem;
use App\Models\WorkOrder;
use App\Services\WoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductionPlanController extends Controller
{
    public function __construct(protected WoService $woService) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ProductionPlan::class);

        $date = $request->input('date') ?: now()->toDateString();

        // Subcon bukan mesin internal → tidak boleh muncul di papan produksi.
        $subconMachineIds = $this->subconMachineIds();

        $plan = ProductionPlan::query()->whereDate('plan_date', $date)->first();

        $items = $plan
            ? $plan->items()
                ->whereNotIn('machine_id', $subconMachineIds)
                ->with([
                    'machine:id,machine_code,machine_name',
                    'workOrder:id,wo_no,part_id,qty,status',
                    'workOrder.part:id,part_number,part_name',
                    'fgPart:id,part_number,part_name',
                    'inputPart:id,part_number,part_name',
                    'wipPart:id,part_number,part_name',
                ])
                ->orderByRaw('step_sequence ASC NULLS LAST')
                ->orderBy('machine_id')
                ->orderBy('sequence')
                ->orderBy('id')
                ->get()
            : collect();

        $machines = Machine::query()
            ->where('is_active', true)
            ->whereNotIn('id', $subconMachineIds)
            ->orderBy('machine_name')
            ->get(['id', 'machine_code', 'machine_name']);

        // WO planned yang BELUM masuk plan mana pun → supaya tidak "hilang" dari papan.
        $unplannedWorkOrders = WorkOrder::query()
            ->with('part:id,part_number,part_name')
            ->where('status', 'planned')
            ->whereDoesntHave('planItems')
            ->orderByDesc('id')
            ->get(['id', 'wo_no', 'part_id', 'qty', 'status', 'planned_date']);

        $fgParts = Part::query()
            ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['fg']))
            ->where('is_active', true)
            ->orderBy('part_number')
            ->get(['id', 'part_number', 'part_name', 'model', 'part_type_id']);

        $wipParts = Part::query()
            ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['wip']))
            ->where('is_active', true)
            ->orderBy('part_number')
            ->get(['id', 'part_number', 'part_name']);

        return Inertia::render('Production/Plan/Index', [
            'date' => $date,
            'plan' => $plan ? $plan->only(['id', 'plan_date', 'notes']) : null,
            'items' => $items,
            'machines' => $machines,
            'fgParts' => $fgParts,
            'wipParts' => $wipParts,
            'unplannedWorkOrders' => $unplannedWorkOrders,
        ]);
    }

    /**
     * ID mesin Subcon — papan Production Plan hanya untuk mesin internal.
     *
     * @return list<int>
     */
    private function subconMachineIds(): array
    {
        return Machine::query()
            ->whereRaw("UPPER(COALESCE(machine_code, '')) = 'SUBCON' OR LOWER(COALESCE(machine_name, '')) LIKE '%subcon%'")
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ProductionPlan::class);

        $data = $request->validate([
            'plan_date' => ['required', 'date'],
            'fg_part_id' => ['required', 'integer', 'exists:parts,id'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $isFg = Part::query()
            ->whereKey($data['fg_part_id'])
            ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['fg']))
            ->exists();

        if (! $isFg) {
            throw ValidationException::withMessages(['fg_part_id' => __('Part yang dipilih bukan FG.')]);
        }

        if ($this->woService->activeBomFor((int) $data['fg_part_id']) === null) {
            throw ValidationException::withMessages(['fg_part_id' => __('Belum ada BOM aktif untuk FG ini.')]);
        }

        $actorId = (int) auth()->id();

        $result = DB::transaction(function () use ($data, $actorId) {
            [$workOrderId, $warnings] = $this->woService->createWorkOrder(
                (int) $data['fg_part_id'],
                (float) $data['qty'],
                $data['plan_date'],
                null,
                $actorId,
            );

            return [WorkOrder::query()->findOrFail($workOrderId), $warnings];
        });

        [$workOrder, $warnings] = $result;

        $redirect = redirect()->route('production-plans.index', ['date' => $data['plan_date']]);

        if (count($warnings) > 0) {
            return $redirect->with('error', __('WO dibuat, tapi ada kekurangan stok: :details', ['details' => implode(' · ', array_slice($warnings, 0, 5)).(count($warnings) > 5 ? ' …' : '')]));
        }

        return $redirect->with('success', __('WO :number dibuat dan masuk ke Production Plan.', ['number' => $workOrder->wo_no]));
    }

    /**
     * Tempel WO yang sudah dibuat (planned) ke Production Plan.
     */
    public function attachWorkOrder(Request $request): RedirectResponse
    {
        Gate::authorize('create', ProductionPlan::class);

        $data = $request->validate([
            'work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
            'plan_date' => ['required', 'date'],
        ]);

        $workOrder = WorkOrder::query()->findOrFail((int) $data['work_order_id']);

        if ($workOrder->status !== 'planned') {
            throw ValidationException::withMessages(['work_order_id' => __('Hanya WO berstatus planned yang bisa dimasukkan ke plan.')]);
        }
        if ($workOrder->planItems()->exists()) {
            throw ValidationException::withMessages(['work_order_id' => __('WO ini sudah ada di Production Plan.')]);
        }

        $this->woService->populatePlanItems($workOrder, $data['plan_date'], (int) auth()->id());

        return redirect()
            ->route('production-plans.index', ['date' => $data['plan_date']])
            ->with('success', __('WO :number masuk ke Production Plan.', ['number' => $workOrder->wo_no]));
    }

    public function update(Request $request, ProductionPlanItem $item): RedirectResponse
    {
        Gate::authorize('update', $item->plan);

        $data = $request->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'wip_part_id' => ['nullable', 'integer', 'exists:parts,id'],
            'target_d' => ['nullable', 'numeric', 'min:0'],
            'target_d1' => ['nullable', 'numeric', 'min:0'],
            'target_d2' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item->update([
            'machine_id' => $data['machine_id'],
            'wip_part_id' => $data['wip_part_id'] ?? null,
            'target_d' => $data['target_d'] ?? null,
            'target_d1' => $data['target_d1'] ?? null,
            'target_d2' => $data['target_d2'] ?? null,
            'updated_by' => auth()->id(),
        ]);

        return redirect()
            ->route('production-plans.index', ['date' => $item->plan?->plan_date?->toDateString()])->with('success', __('Baris Production Plan diperbarui.'));
    }

    public function reorder(Request $request): RedirectResponse
    {
        Gate::authorize('reorder', ProductionPlan::class);

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:production_plan_items,id'],
            'items.*.sequence' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $row) {
                ProductionPlanItem::query()->whereKey($row['id'])->update([
                    'sequence' => $row['sequence'],
                    'updated_by' => auth()->id(),
                ]);
            }
        });

        return back()->with('success', __('Urutan diperbarui.'));
    }

    public function detach(ProductionPlanItem $item): RedirectResponse
    {
        Gate::authorize('delete', $item->plan);

        $planDate = $item->plan?->plan_date?->toDateString();
        $woNo = $item->workOrder?->wo_no;

        $item->delete();

        $message = $woNo
            ? __('WO :number dilepas dari Production Plan (WO tetap ada).', ['number' => $woNo])
            : __('Baris dilepas dari Production Plan.');

        return redirect()
            ->route('production-plans.index', ['date' => $planDate])->with('success', $message);
    }
}
