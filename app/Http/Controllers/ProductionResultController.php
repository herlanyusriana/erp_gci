<?php

namespace App\Http\Controllers;

use App\Models\ProductionResult;
use App\Models\WorkOrder;
use App\Services\ProductionResultService;
use App\Services\WoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductionResultController extends Controller
{
    public function __construct(
        protected ProductionResultService $resultService,
        protected WoService $woService,
    ) {}

    /**
     * Menu Hasil Produksi: daftar WO yang sedang berjalan & bisa dilaporkan.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $workOrders = WorkOrder::query()
            ->with('part:id,part_number,part_name')
            ->where('status', 'in_progress')
            ->withCount('results')
            ->when($request->input('search'), fn ($q, $search) => $q->where(fn ($w) => $w
                ->where('wo_no', 'ilike', "%{$search}%")
                ->orWhereHas('part', fn ($p) => $p
                    ->where('part_number', 'ilike', "%{$search}%")
                    ->orWhere('part_name', 'ilike', "%{$search}%"))))
            ->orderByDesc('released_at')
            ->paginate(15)
            ->withQueryString();

        // Output FG per WO (parent = part FG WO itu).
        $fgProduced = ProductionResult::query()
            ->whereIn('work_order_id', $workOrders->pluck('id'))
            ->whereIn('parent_part_id', $workOrders->pluck('part_id'))
            ->groupBy('work_order_id')
            ->selectRaw('work_order_id, SUM(qty_good) AS total')
            ->pluck('total', 'work_order_id');

        $workOrders->getCollection()->transform(function (WorkOrder $wo) use ($fgProduced) {
            $wo->setAttribute('fg_produced', round((float) ($fgProduced[$wo->id] ?? 0), 4));

            return $wo;
        });

        return Inertia::render('Production/Result/Index', [
            'workOrders' => $workOrders,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(WorkOrder $workOrder): Response
    {
        Gate::authorize('update', $workOrder);

        if ($workOrder->status !== 'in_progress') {
            abort(422, __('WO belum di-release.'));
        }

        $workOrder->load(['part:id,part_number,part_name']);

        return Inertia::render('Production/Result/Create', [
            'workOrder' => $workOrder->only(['id', 'wo_no', 'qty', 'status']),
            'workOrderPart' => $workOrder->part,
            'steps' => $this->steps($workOrder),
            'results' => ProductionResult::query()
                ->where('work_order_id', $workOrder->id)
                ->with(['parentPart:id,part_number,part_name', 'process:id,process_name', 'machine:id,machine_code,machine_name', 'reporter:id,name'])
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('update', $workOrder);

        $data = $request->validate([
            'parent_part_id' => ['required', 'integer', 'exists:parts,id'],
            'qty_good' => ['required', 'numeric', 'gt:0'],
            'qty_reject' => ['nullable', 'numeric', 'min:0'],
            'result_date' => ['nullable', 'date'],
            'shift' => ['nullable', 'string', 'max:20'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->resultService->report($workOrder, (int) $data['parent_part_id'], $data, (int) auth()->id());

        return redirect()
            ->route('production-results.create', $workOrder)
            ->with('success', __('Hasil produksi tersimpan.'));
    }

    public function destroy(WorkOrder $workOrder, ProductionResult $result): RedirectResponse
    {
        Gate::authorize('update', $workOrder);

        if ($result->work_order_id !== $workOrder->id) {
            throw ValidationException::withMessages(['result' => __('Hasil tidak termasuk WO ini.')]);
        }

        $result->delete();

        return redirect()
            ->route('production-results.create', $workOrder)
            ->with('success', __('Hasil produksi dihapus.'));
    }

    /**
     * Step yang bisa dilaporkan: parent_part unik, urut sequence, plus progres.
     *
     * @return list<array<string, mixed>>
     */
    private function steps(WorkOrder $workOrder): array
    {
        $items = $workOrder->items()
            ->with(['parentPart:id,part_number,part_name,part_type_id', 'parentPart.partType:id,code', 'process:id,process_name', 'machine:id,machine_code,machine_name'])
            ->get();

        $produced = $workOrder->results()
            ->selectRaw('parent_part_id, SUM(qty_good) AS good, SUM(qty_reject) AS reject')
            ->groupBy('parent_part_id')
            ->pluck('good', 'parent_part_id');

        return $items
            ->groupBy('parent_part_id')
            ->map(function ($rows, $parentId) use ($workOrder, $produced) {
                $first = $rows->sortBy(fn ($r) => [$r->sequence ?? 0, $r->id])->first();

                return [
                    'parent_part_id' => (int) $parentId,
                    'part_number' => $first->parentPart?->part_number,
                    'part_name' => $first->parentPart?->part_name,
                    'part_type' => strtoupper((string) $first->parentPart?->partType?->code),
                    'process' => $first->process?->process_name,
                    'machine' => $first->machine?->machine_name,
                    'machine_id' => $first->machine_id,
                    'sequence' => $first->sequence,
                    'target_qty' => (float) $workOrder->qty,
                    'produced_qty' => (float) ($produced[$parentId] ?? 0),
                ];
            })
            ->sortBy(fn ($s) => [$s['sequence'] ?? 0, $s['parent_part_id']])
            ->values()
            ->all();
    }
}
