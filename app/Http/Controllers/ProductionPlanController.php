<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\MachineCycleTime;
use App\Models\Part;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanHistory;
use App\Models\ProductionPlanItem;
use App\Models\ProductionResult;
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

        $items = ProductionPlanItem::query()
            ->whereHas('plan', fn ($query) => $query->whereDate('plan_date', '<=', $date))
            ->whereHas('workOrder', fn ($query) => $query->whereIn('status', ['in_progress', 'completed']))
            ->whereNotIn('machine_id', $subconMachineIds)
            ->with([
                'machine:id,machine_code,machine_name',
                'process:id,process_name',
                'workOrder:id,wo_no,part_id,qty,status',
                'workOrder.part:id,part_number,part_name',
                'fgPart:id,part_number,part_name,model',
                'inputPart:id,part_number,part_name',
                'wipPart:id,part_number,part_name',
            ])
            ->orderByRaw('step_sequence ASC NULLS LAST')
            ->orderBy('machine_id')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        // Sisa WO adalah kuantitas yang belum direalisasikan, bukan target harian
        // yang masih berupa rencana. Result setelah tanggal papan tidak ikut
        // mengurangi sisa saat melihat papan tanggal lampau.
        $producedByStep = ProductionResult::query()
            ->whereDate('result_date', '<=', $date)
            ->whereIn('work_order_id', $items->pluck('work_order_id')->filter()->unique())
            ->whereIn('parent_part_id', $items->pluck('wip_part_id')->filter()->unique())
            ->selectRaw('work_order_id, parent_part_id, SUM(qty_good) as qty_good')
            ->groupBy('work_order_id', 'parent_part_id')
            ->get()
            ->keyBy(fn (ProductionResult $result) => $result->work_order_id.'|'.$result->parent_part_id);

        $items->each(function (ProductionPlanItem $item) use ($producedByStep) {
            $key = ($item->work_order_id ?? 0).'|'.($item->wip_part_id ?? 0);
            $produced = (float) ($producedByStep->get($key)?->qty_good ?? 0);
            $item->setAttribute('remaining_qty', max(0, (float) ($item->workOrder?->qty ?? 0) - $produced));
        });

        $items = $items
            ->filter(fn (ProductionPlanItem $item) => in_array($item->workOrder?->status, ['in_progress', 'completed'], true)
                && (float) $item->remaining_qty > 0)
            ->values();

        // Estimasi waktu: qty WO × cycle time (mesin × part hasil baris itu).
        $cycleTimes = MachineCycleTime::query()
            ->where('is_active', true)
            ->whereIn('machine_id', $items->pluck('machine_id')->filter()->unique()->values())
            ->whereIn('part_id', $items->pluck('wip_part_id')->filter()->unique()->values())
            ->get(['machine_id', 'part_id', 'cycle_time_seconds'])
            ->keyBy(fn ($row) => $row->machine_id.'|'.$row->part_id);

        $items->each(function (ProductionPlanItem $item) use ($cycleTimes) {
            $key = ($item->machine_id ?? 0).'|'.($item->wip_part_id ?? 0);
            $seconds = $cycleTimes->get($key)?->cycle_time_seconds;
            $qty = (float) ($item->workOrder?->qty ?? 0);

            $item->setAttribute(
                'estimated_seconds',
                $seconds !== null ? round($qty * (float) $seconds, 2) : null,
            );
        });

        // Urutan papan = urutan master mesin (kolom `sequence`), fallback abjad.
        $machines = Machine::query()
            ->where('is_active', true)
            ->whereNotIn('id', $subconMachineIds)
            ->orderByRaw('sequence ASC NULLS LAST')
            ->orderBy('machine_name')
            ->get(['id', 'machine_code', 'machine_name', 'sequence']);

        // WO planned yang BELUM masuk plan mana pun → supaya tidak "hilang" dari papan.
        $unplannedWorkOrders = WorkOrder::query()
            ->with('part:id,part_number,part_name')
            ->where('status', 'planned')
            ->whereDoesntHave('planItems')
            ->orderByDesc('id')
            ->get(['id', 'wo_no', 'part_id', 'qty', 'status', 'planned_date']);

        // WO aktif yang baru dialokasikan setelah tanggal papan terpilih.
        $offBoardWorkOrders = WorkOrder::query()
            ->with([
                'part:id,part_number,part_name',
                'planItems' => fn ($query) => $query
                    ->whereNotIn('machine_id', $subconMachineIds)
                    ->whereHas('plan', fn ($planQuery) => $planQuery->whereDate('plan_date', '>', $date))
                    ->with('plan:id,plan_date'),
            ])
            ->whereIn('status', ['in_progress', 'completed'])
            ->whereNotIn('id', $items->pluck('work_order_id')->filter()->unique())
            ->whereHas('planItems', fn ($query) => $query
                ->whereNotIn('machine_id', $subconMachineIds)
                ->whereHas('plan', fn ($planQuery) => $planQuery->whereDate('plan_date', '>', $date)))
            ->orderByDesc('id')
            ->get(['id', 'wo_no', 'part_id', 'qty', 'status', 'planned_date']);

        $offBoardProduced = ProductionResult::query()
            ->whereDate('result_date', '<=', $date)
            ->whereIn('work_order_id', $offBoardWorkOrders->pluck('id'))
            ->whereIn('parent_part_id', $offBoardWorkOrders->flatMap->planItems->pluck('wip_part_id')->filter()->unique()->values())
            ->selectRaw('work_order_id, parent_part_id, SUM(qty_good) as qty_good')
            ->groupBy('work_order_id', 'parent_part_id')
            ->get()
            ->keyBy(fn (ProductionResult $result) => $result->work_order_id.'|'.$result->parent_part_id);

        $offBoardWorkOrders = $offBoardWorkOrders
            ->filter(function (WorkOrder $workOrder) use ($offBoardProduced): bool {
                foreach ($workOrder->planItems as $planItem) {
                    $produced = (float) ($offBoardProduced->get($workOrder->id.'|'.$planItem->wip_part_id)?->qty_good ?? 0);
                    if (max(0, (float) $workOrder->qty - $produced) > 0) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->map(function (WorkOrder $workOrder): WorkOrder {
                $workOrder->setAttribute('plan_dates', $workOrder->planItems
                    ->pluck('plan.plan_date')
                    ->filter()
                    ->map(fn ($planDate) => $planDate->toDateString())
                    ->unique()
                    ->sort()
                    ->values());

                return $workOrder;
            });

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

        $histories = $plan
            ? $plan->histories()
                ->with('user:id,name')
                ->latest('id')
                ->limit(100)
                ->get()
            : collect();

        return Inertia::render('Production/Plan/Index', [
            'date' => $date,
            'plan' => $plan ? $plan->only(['id', 'plan_date', 'notes']) : null,
            'items' => $items,
            'machines' => $machines,
            'fgParts' => $fgParts,
            'wipParts' => $wipParts,
            'histories' => $histories,
            'unplannedWorkOrders' => $unplannedWorkOrders,
            'offBoardWorkOrders' => $offBoardWorkOrders,
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

        // WO yang baru dibuat masih `planned`; papan produksi baru memuatnya
        // setelah di-release. Pesan harus mencerminkan itu supaya WO tidak
        // dikira "hilang" dari papan.
        return $redirect->with('success', __('WO :number dibuat. WO masuk papan produksi setelah di-release.', ['number' => $workOrder->wo_no]));
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

        if (! $workOrder->planItems()->exists()) {
            // Tidak ada step routing yang bisa jadi baris papan (mis. semua Subcon
            // atau tanpa parent part). Jangan laporkan sukses palsu.
            return redirect()
                ->route('production-plans.index', ['date' => $data['plan_date']])
                ->with('error', __('WO :number tidak punya step routing yang bisa masuk papan (cek BOM/mesin, termasuk step Subcon).', ['number' => $workOrder->wo_no]));
        }

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

        $before = $this->itemSnapshot($item);
        $item->update([
            'machine_id' => $data['machine_id'],
            'wip_part_id' => $data['wip_part_id'] ?? null,
            'target_d' => $data['target_d'] ?? null,
            'target_d1' => $data['target_d1'] ?? null,
            'target_d2' => $data['target_d2'] ?? null,
            'updated_by' => auth()->id(),
        ]);
        $this->recordHistory($item, 'item_updated', $before, $this->itemSnapshot($item));

        return redirect()
            ->route('production-plans.index', ['date' => $item->plan?->plan_date?->toDateString()])->with('success', __('Baris Production Plan diperbarui.'));
    }

    /** Simpan target dan sequence D / D+1 / D+2 banyak baris sekaligus. */
    public function updateTargets(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:production_plan_items,id'],
            'items.*.target_d' => ['nullable', 'numeric', 'min:0'],
            'items.*.target_d1' => ['nullable', 'numeric', 'min:0'],
            'items.*.target_d2' => ['nullable', 'numeric', 'min:0'],
            'items.*.sequence_d' => ['sometimes', 'integer', 'min:0'],
            'items.*.sequence_d1' => ['sometimes', 'integer', 'min:0'],
            'items.*.sequence_d2' => ['sometimes', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $request) {
            foreach ($data['items'] as $row) {
                $item = ProductionPlanItem::query()->with('plan')->find((int) $row['id']);
                if ($item === null) {
                    continue;
                }

                Gate::authorize('update', $item->plan);

                $before = $this->targetSnapshot($item);
                $item->update([
                    'target_d' => $row['target_d'] ?? null,
                    'target_d1' => $row['target_d1'] ?? null,
                    'target_d2' => $row['target_d2'] ?? null,
                    'sequence_d' => $row['sequence_d'] ?? $item->sequence_d,
                    'sequence_d1' => $row['sequence_d1'] ?? $item->sequence_d1,
                    'sequence_d2' => $row['sequence_d2'] ?? $item->sequence_d2,
                    'updated_by' => $request->user()?->id,
                ]);
                $this->recordHistory($item, 'targets_updated', $before, $this->targetSnapshot($item), $request->user()?->id);
            }
        });

        return back()->with('success', __('Target Production Plan disimpan.'));
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
                $item = ProductionPlanItem::query()->with('plan')->findOrFail((int) $row['id']);
                Gate::authorize('update', $item->plan);
                $before = ['sequence' => $item->sequence];
                $item->update([
                    'sequence' => $row['sequence'],
                    'updated_by' => auth()->id(),
                ]);
                $this->recordHistory($item, 'sequence_updated', $before, ['sequence' => $item->sequence]);
            }
        });

        return back()->with('success', __('Urutan diperbarui.'));
    }

    public function detach(ProductionPlanItem $item): RedirectResponse
    {
        Gate::authorize('delete', $item->plan);

        $planDate = $item->plan?->plan_date?->toDateString();
        $woNo = $item->workOrder?->wo_no;

        $this->recordHistory($item, 'item_detached', $this->itemSnapshot($item), null);

        $item->delete();

        $message = $woNo
            ? __('WO :number dilepas dari Production Plan (WO tetap ada).', ['number' => $woNo])
            : __('Baris dilepas dari Production Plan.');

        return redirect()
            ->route('production-plans.index', ['date' => $planDate])->with('success', $message);
    }

    /** @return array{machine_id:int|null,wip_part_id:int|null,target_d:float|null,target_d1:float|null,target_d2:float|null,sequence_d:int,sequence_d1:int,sequence_d2:int} */
    private function itemSnapshot(ProductionPlanItem $item): array
    {
        return [
            'machine_id' => $item->machine_id,
            'wip_part_id' => $item->wip_part_id,
            ...$this->targetSnapshot($item),
        ];
    }

    /** @return array{target_d:float|null,target_d1:float|null,target_d2:float|null,sequence_d:int,sequence_d1:int,sequence_d2:int} */
    private function targetSnapshot(ProductionPlanItem $item): array
    {
        return [
            'target_d' => $item->target_d,
            'target_d1' => $item->target_d1,
            'target_d2' => $item->target_d2,
            'sequence_d' => $item->sequence_d,
            'sequence_d1' => $item->sequence_d1,
            'sequence_d2' => $item->sequence_d2,
        ];
    }

    private function recordHistory(ProductionPlanItem $item, string $event, ?array $before, ?array $after, ?int $userId = null): void
    {
        ProductionPlanHistory::create([
            'production_plan_id' => $item->production_plan_id,
            'production_plan_item_id' => $item->id,
            'event' => $event,
            'before' => $before,
            'after' => $after,
            'user_id' => $userId ?? auth()->id(),
        ]);
    }
}
