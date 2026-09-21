<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ProductionResultService;
use App\Services\ReceiveMaterialService;
use App\Services\WoService;
use App\Support\UomCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    public function __construct(
        protected WoService $woService,
        protected ReceiveMaterialService $receiveService,
        protected ProductionResultService $resultService,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $workOrders = WorkOrder::query()
            ->with(['part:id,part_number,part_name,model,part_type_id', 'part.partType:id,code,name'])
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
            throw ValidationException::withMessages(['part_id' => __('Work Order hanya untuk part FG.')]);
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
                ->with('error', __('WO dibuat, tapi ada kekurangan stok: :details', ['details' => implode(' · ', array_slice($warnings, 0, 5)).(count($warnings) > 5 ? ' …' : '')]));
        }

        return redirect()
            ->route('work-orders.show', $workOrderId)->with('success', __('Work Order dibuat.'));
    }

    public function show(WorkOrder $workOrder): Response
    {
        Gate::authorize('view', $workOrder);

        $workOrder->load([
            'part' => fn ($q) => $q->with('partType', 'uom'),
            'items' => fn ($q) => $q->with(['process', 'machine', 'parentPart', 'childPart.partSubstitutes.substitutePart', 'selectedPart', 'allocations.part']),
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
            'allocations',
        ]);

        $machines = Machine::query()->where('is_active', true)->orderBy('machine_name')->get(['id', 'machine_code', 'machine_name']);

        $options = $this->materialOptionsWithStock($item);
        $tagsByPart = $this->stockTagsForParts(array_column($options, 'id'), $item->uom_rm);

        return Inertia::render('Production/WorkOrder/ItemEdit', [
            'workOrder' => $workOrder->only(['id', 'wo_no']),
            'item' => $item,
            'machines' => $machines,
            'materialOptions' => array_map(
                fn ($option) => $option + ['tags' => $tagsByPart[(int) $option['id']] ?? []],
                $options,
            ),
            'allocations' => $item->allocations
                ->map(fn ($a) => ['part_id' => (int) $a->part_id, 'qty' => (float) $a->qty])
                ->values(),
        ]);
    }

    /**
     * Tag stok aktif per part (FIFO: received_at ASC NULLS LAST, lalu id),
     * difilter UOM material item. Dipakai panel "tag" di halaman alokasi.
     *
     * @param  list<int|string>  $partIds
     * @return array<int, list<array{tag:string|null, qty:float, uom:string|null, received_at:string|null, invoice:string|null, supplier:string|null}>>
     */
    private function stockTagsForParts(array $partIds, ?string $uom): array
    {
        $partIds = array_values(array_unique(array_map('intval', $partIds)));
        if ($partIds === []) {
            return [];
        }

        $normalized = UomCatalog::normalize($uom);

        return PartStock::query()
            ->whereIn('part_id', $partIds)
            ->where('qty', '>', 0)
            ->when($normalized !== null, fn ($q) => $q->whereRaw('UPPER(COALESCE(qty_unit, \'\')) = ?', [$normalized]))
            ->with([
                'receive:id,invoice_no,arrival_item_id',
                'receive.arrivalItem:id,arrival_id',
                'receive.arrivalItem.arrival:id,supplier_id',
                'receive.arrivalItem.arrival.supplier:id,supplier_name',
            ])
            ->orderByRaw('received_at ASC NULLS LAST')
            ->orderBy('id')
            ->get(['id', 'part_id', 'tag', 'qty', 'qty_unit', 'received_at', 'receive_id'])
            ->groupBy('part_id')
            ->map(fn ($rows) => $rows->map(fn ($stock) => [
                'tag' => $stock->tag,
                'qty' => round((float) $stock->qty, 4),
                'uom' => UomCatalog::normalize((string) $stock->qty_unit),
                'received_at' => $stock->received_at?->toIso8601String(),
                'invoice' => $stock->receive?->invoice_no,
                'supplier' => $stock->receive?->arrivalItem?->arrival?->supplier?->supplier_name,
            ])->values()->all())
            ->all();
    }

    /**
     * Opsi material untuk sebuah item WO: main material BOM (acuan, tanpa stok)
     * + substitute aktif yang memegang stok. Stok diambil sekali (batch) agar
     * tidak N+1 walau substitute puluhan.
     *
     * @return list<array{id:int, part_number:string, part_name:string, kind:string, stock:float}>
     */
    private function materialOptionsWithStock(WorkOrderItem $item): array
    {
        $main = $item->childPart;
        $subs = collect($main?->partSubstitutes ?? [])
            ->map(fn ($s) => $s->substitutePart)
            ->filter()
            ->unique('id')
            ->values();

        $uuids = collect([$main?->id, ...$subs->pluck('id')->all()])->filter()->unique();
        $stocks = $this->receiveService->availableFifoBatch($uuids, $item->uom_rm);

        $rows = [];
        if ($main !== null) {
            $rows[] = [
                'id' => (int) $main->id,
                'part_number' => (string) $main->part_number,
                'part_name' => (string) $main->part_name,
                'kind' => 'mainMaterial',
                'stock' => (float) ($stocks[$main->id] ?? 0),
            ];
        }

        foreach ($subs as $sub) {
            $rows[] = [
                'id' => (int) $sub->id,
                'part_number' => (string) $sub->part_number,
                'part_name' => (string) $sub->part_name,
                'kind' => 'substitute',
                'stock' => (float) ($stocks[$sub->id] ?? 0),
            ];
        }

        return $rows;
    }

    public function updateItem(Request $request, WorkOrder $workOrder, WorkOrderItem $item): RedirectResponse
    {
        Gate::authorize('update', $workOrder);
        abort_unless($item->work_order_id === $workOrder->id && $workOrder->status === 'planned', 422, __('Item WO tidak dapat diubah.'));

        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.part_id' => ['required', 'integer', 'exists:parts,id', 'distinct'],
            'allocations.*.qty' => ['required', 'numeric', 'gt:0'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
        ]);

        $allowedIds = collect($this->materialOptionsWithStock($item))->pluck('id')->all();
        foreach ($data['allocations'] as $index => $row) {
            if (! in_array((int) $row['part_id'], $allowedIds, true)) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.part_id" => __('Part bukan main material atau substitute aktif untuk material BOM ini.'),
                ]);
            }
        }

        // Boleh sebagian (sisanya jadi shortage saat release), tapi tidak boleh
        // melebihi kebutuhan.
        $allocatedTotal = collect($data['allocations'])->sum(fn ($row) => (float) $row['qty']);
        if ($allocatedTotal > (float) $item->qty_required + 1e-9) {
            throw ValidationException::withMessages([
                'allocations' => __('Total alokasi (:total) melebihi kebutuhan (:required).', [
                    'total' => rtrim(rtrim(number_format($allocatedTotal, 4, '.', ''), '0'), '.'),
                    'required' => rtrim(rtrim(number_format((float) $item->qty_required, 4, '.', ''), '0'), '.'),
                ]),
            ]);
        }

        DB::transaction(function () use ($item, $data) {
            $item->update(['machine_id' => $data['machine_id'] ?? null]);
            $item->allocations()->delete();
            $item->allocations()->createMany(
                collect($data['allocations'])->map(fn ($row) => [
                    'part_id' => (int) $row['part_id'],
                    'qty' => (float) $row['qty'],
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ])->all(),
            );

            // selected_part_id dipertahankan untuk kompatibilitas tampilan lama:
            // pakai alokasi terbesar.
            $primary = collect($data['allocations'])->sortByDesc('qty')->first();
            $item->update(['selected_part_id' => (int) $primary['part_id']]);
        });

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
                ->with('error', __('WO di-release, tapi ada kekurangan: :details', ['details' => implode(' · ', array_slice($lines, 0, 5)).(count($lines) > 5 ? ' …' : '')]));
        }

        return redirect()
            ->route('work-orders.show', $workOrder)->with('success', __('WO di-release, material RM dikonsumsi.'));
    }

    public function complete(WorkOrder $workOrder): RedirectResponse
    {
        Gate::authorize('update', $workOrder);

        $produced = $this->resultService->fgProduced($workOrder);
        $this->woService->complete($workOrder, (int) auth()->id());

        if ($produced + 1e-9 < (float) $workOrder->qty) {
            return redirect()
                ->route('work-orders.show', $workOrder)
                ->with('error', __('WO ditutup dengan kekurangan output FG: :produced dari :qty.', [
                    'produced' => rtrim(rtrim(number_format($produced, 4, '.', ''), '0'), '.'),
                    'qty' => rtrim(rtrim(number_format((float) $workOrder->qty, 4, '.', ''), '0'), '.'),
                ]));
        }

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
