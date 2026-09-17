<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\Part;
use App\Models\PartSubstitute;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\WoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    public function __construct(protected WoService $woService) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $workOrders = WorkOrder::query()
            ->with(['part:id,part_number,part_name,part_type_id', 'part.partType:id,code,name'])
            ->withCount('items')
            ->when($request->input('search'), function ($q, $search) {
                $q->where('wo_no', 'ilike', "%{$search}%")
                    ->orWhereHas('part', function ($w) use ($search) {
                        $w->where('part_number', 'ilike', "%{$search}%")
                            ->orWhere('part_name', 'ilike', "%{$search}%");
                    });
            })
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Production/WorkOrder/Index', [
            'workOrders' => $workOrders,
            'filters' => $request->only(['search', 'status']),
            'statuses' => WorkOrder::STATUSES,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', WorkOrder::class);

        // FG parts saja yang boleh diproduksi.
        $fgParts = Part::query()
            ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['fg']))
            ->where('is_active', true)
            ->orderBy('part_number')
            ->get(['id', 'part_number', 'part_name', 'model', 'part_type_id']);

        return Inertia::render('Production/WorkOrder/Create', [
            'fgParts' => $fgParts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', WorkOrder::class);

        $validated = $request->validate([
            'part_id' => ['required', 'integer', 'exists:parts,id'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            'planned_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $isFg = Part::query()
            ->whereKey($validated['part_id'])
            ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['fg']))
            ->exists();
        if (! $isFg) {
            throw \Illuminate\Validation\ValidationException::withMessages(['part_id' => __('Work Order hanya untuk part FG.')]);
        }

        [$workOrderId, $warnings] = $this->woService->createWorkOrder(
            (int) $validated['part_id'],
            (float) $validated['qty'],
            $validated['planned_date'] ?? null,
            $validated['remarks'] ?? null,
            (int) auth()->id(),
        );

        if (count($warnings) > 0) {
            return redirect()
                ->route('work-orders.show', $workOrderId)
                ->with('error', __('WO dibuat, tapi ada kekurangan stok: :details', ['details' => implode(' · ', array_slice($warnings, 0, 5)) . (count($warnings) > 5 ? ' …' : '')]));
        }

        return redirect()
            ->route('work-orders.show', $workOrderId)->with('success', __('Work Order dibuat.'));
    }

    public function show(WorkOrder $workOrder): Response
    {
        Gate::authorize('view', $workOrder);

        $workOrder->load([
            'part' => fn ($q) => $q->with('partType', 'uom'),
            'items' => fn ($q) => $q->with(['process', 'machine', 'parentPart', 'childPart.partSubstitutes.substitutePart', 'selectedPart']),
            'consumptions',
        ]);
        $machines = Machine::query()->where('is_active', true)->orderBy('machine_name')->get(['id', 'machine_code', 'machine_name']);

        return Inertia::render('Production/WorkOrder/Show', [
            'workOrder' => $workOrder,
            'machines' => $machines,
            'can' => [
                'release' => Gate::allows('release', $workOrder),
                'complete' => Gate::allows('update', $workOrder),
                'cancel' => Gate::allows('update', $workOrder),
                'delete' => Gate::allows('delete', $workOrder),
            ],
        ]);
    }

    public function editItem(WorkOrder $workOrder, WorkOrderItem $item): Response
    {
        Gate::authorize('update', $workOrder);
        abort_unless($item->work_order_id === $workOrder->id && $workOrder->status === 'planned', 403, __('Item WO tidak dapat diubah.'));

        $item->load([
            'process',
            'machine',
            'childPart.partSubstitutes.substitutePart',
            'selectedPart',
        ]);

        $machines = Machine::query()->where('is_active', true)->orderBy('machine_name')->get(['id', 'machine_code', 'machine_name']);

        return Inertia::render('Production/WorkOrder/ItemEdit', [
            'workOrder' => $workOrder->only(['id', 'wo_no']),
            'item' => $item,
            'machines' => $machines,
        ]);
    }

    public function updateItem(Request $request, WorkOrder $workOrder, WorkOrderItem $item): RedirectResponse
    {
        Gate::authorize('update', $workOrder);
        abort_unless($item->work_order_id === $workOrder->id && $workOrder->status === 'planned', 422, __('Item WO tidak dapat diubah.'));

        $data = $request->validate([
            'selected_part_id' => ['required', 'integer', 'exists:parts,id'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
        ]);
        $allowed = (int) $data['selected_part_id'] === (int) $item->child_part_id
            || PartSubstitute::where('part_id', $item->child_part_id)->where('substitute_part_id', $data['selected_part_id'])->where('is_active', true)->exists();
        abort_unless($allowed, 422, __('Part bukan main material atau substitute aktif untuk material BOM ini.'));
        $item->update([
            'selected_part_id' => $data['selected_part_id'],
            'machine_id' => $data['machine_id'] ?? null,
        ]);

        return redirect()
            ->route('work-orders.show', $workOrder)->with('success', __('Routing WO diperbarui.'));
    }

    public function release(WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('release', $workOrder);

        [$workOrder, $shortages] = $this->woService->releaseWorkOrder($workOrder, (int) auth()->id());

        if (count($shortages) > 0) {
            $lines = array_map(
                fn ($s) => __(':name (:number) kurang :short :unit', ['name' => $s['child_part_name'], 'number' => $s['child_part_no'], 'short' => $s['short'], 'unit' => $s['uom']]),
                $shortages,
            );

            return redirect()
                ->route('work-orders.show', $workOrder)
                ->with('error', __('WO di-release, tapi ada kekurangan: :details', ['details' => implode(' · ', array_slice($lines, 0, 5)) . (count($lines) > 5 ? ' …' : '')]));
        }

        return redirect()
            ->route('work-orders.show', $workOrder)->with('success', __('WO di-release, material & WIP dikonsumsi FIFO.'));
    }

    public function complete(WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('update', $workOrder);

        $this->woService->complete($workOrder, (int) auth()->id());

        return redirect()
            ->route('work-orders.show', $workOrder)->with('success', __('WO selesai.'));
    }

    public function cancel(WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('update', $workOrder);

        $this->woService->cancel($workOrder, (int) auth()->id());

        return redirect()
            ->route('work-orders.show', $workOrder)->with('success', __('WO dibatalkan.'));
    }

    public function destroy(WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('delete', $workOrder);

        $workOrder->delete();

        return redirect()->route('work-orders.index')->with('success', __('WO dihapus.'));
    }
}