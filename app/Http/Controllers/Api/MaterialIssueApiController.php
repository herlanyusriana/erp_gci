<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\WorkOrder;
use App\Services\ProductionResultService;
use App\Services\WoService;
use App\Support\UomCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * API mobile "issue out to production" — release WO lewat scan label.
 * Online-only; submit bersifat atomik + idempotent.
 */
class MaterialIssueApiController extends Controller
{
    public function __construct(
        private WoService $woService,
        private ProductionResultService $resultService,
    ) {}

    public function workOrders(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $page = WorkOrder::query()
            ->with(['part:id,part_number,part_name,model'])
            ->when(
                $request->input('status'),
                fn ($q, $status) => $q->where('status', $status),
                fn ($q) => $q->whereIn('status', ['planned', 'in_progress']),
            )
            ->when($request->input('search'), fn ($q, $s) => $q->where('wo_no', 'ilike', "%{$s}%"))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map(fn (WorkOrder $wo) => [
                'id' => $wo->id,
                'wo_no' => $wo->wo_no,
                'status' => $wo->status,
                'qty' => (float) $wo->qty,
                'planned_date' => $wo->planned_date?->toDateString(),
                'part' => $wo->part ? [
                    'id' => $wo->part->id,
                    'part_number' => $wo->part->part_number,
                    'part_name' => $wo->part->part_name,
                ] : null,
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Kebutuhan material per item + rekomendasi tag FIFO untuk di-scan.
     */
    public function releaseContext(WorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('issue', $workOrder);

        $workOrder->load(['part:id,part_number,part_name,model']);
        $items = $workOrder->items()
            ->with([
                'process:id,process_name',
                'machine:id,machine_code,machine_name',
                'childPart:id,part_number,part_name',
                'allocations.part:id,part_number,part_name',
            ])
            ->get();

        $postedParentIds = [];
        foreach ($items as $it) {
            if (strtoupper((string) $it->source) !== 'SUBCON' && $it->parent_part_id !== null) {
                $postedParentIds[$it->parent_part_id] = true;
            }
        }

        // Hanya leaf (child bukan WIP internal) yang perlu scan.
        $leafItems = $items->reject(
            fn ($it) => $it->child_part_id !== null && isset($postedParentIds[$it->child_part_id]),
        )->values();

        $allowedByItem = [];
        $allPartIds = [];
        foreach ($leafItems as $it) {
            $ids = $this->woService->allowedPartIdsForItem($it);
            $allowedByItem[$it->id] = $ids;
            $allPartIds = array_merge($allPartIds, $ids);
        }
        $allPartIds = array_values(array_unique($allPartIds));

        $parts = Part::query()->whereIn('id', $allPartIds)->get(['id', 'part_number', 'part_name'])->keyBy('id');

        // Satu query stok untuk semua part yang diizinkan (hindari N+1).
        $stocksByPart = PartStock::query()
            ->whereIn('part_id', $allPartIds)
            ->where('qty', '>', 0)
            ->orderByRaw('received_at ASC NULLS LAST')
            ->orderBy('id')
            ->get(['id', 'part_id', 'tag', 'qty', 'qty_unit', 'received_at'])
            ->groupBy('part_id');

        $rows = [];
        foreach ($leafItems as $it) {
            $allowedIds = $allowedByItem[$it->id];
            $mainId = (int) $it->child_part_id;
            $uom = UomCatalog::normalize((string) $it->uom_rm);

            $allowed = collect($allowedIds)->map(fn ($id) => [
                'id' => (int) $id,
                'part_number' => $parts[$id]->part_number ?? null,
                'part_name' => $parts[$id]->part_name ?? null,
                'kind' => (int) $id === $mainId ? 'main' : 'substitute',
            ])->values();

            $recommended = collect($allowedIds)
                ->flatMap(fn ($id) => $stocksByPart->get($id, collect()))
                ->filter(fn ($s) => $uom === null || UomCatalog::normalize((string) $s->qty_unit) === $uom)
                ->map(fn ($s) => [
                    'tag' => $s->tag,
                    'part_id' => (int) $s->part_id,
                    'part_number' => $parts[$s->part_id]->part_number ?? null,
                    'qty' => (float) $s->qty,
                    'uom' => UomCatalog::normalize((string) $s->qty_unit),
                    'received_at' => $s->received_at?->toIso8601String(),
                ])
                ->values();

            $rows[] = [
                'work_order_item_id' => $it->id,
                'sequence' => $it->sequence,
                'process' => $it->process?->process_name,
                'machine' => $it->machine?->machine_name,
                'part' => $it->childPart ? [
                    'id' => $it->childPart->id,
                    'part_number' => $it->childPart->part_number,
                    'part_name' => $it->childPart->part_name,
                ] : null,
                'child_part_name' => $it->child_part_name,
                'uom' => $uom,
                'required' => round((float) $it->qty_required, 4),
                'consumed' => round((float) $it->qty_consumed, 4),
                'remaining' => round(max(0.0, (float) $it->qty_required - (float) $it->qty_consumed), 4),
                'allowed_parts' => $allowed,
                'recommended_tags' => $recommended,
            ];
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'work_order' => [
                    'id' => $workOrder->id,
                    'wo_no' => $workOrder->wo_no,
                    'status' => $workOrder->status,
                    'qty' => (float) $workOrder->qty,
                    'part' => $workOrder->part ? [
                        'id' => $workOrder->part->id,
                        'part_number' => $workOrder->part->part_number,
                        'part_name' => $workOrder->part->part_name,
                    ] : null,
                ],
                'items' => $rows,
            ],
        ]);
    }

    /**
     * Resolve satu tag hasil scan → info part & stok tersedia.
     */
    public function resolveTag(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tag' => ['required', 'string', 'max:255'],
            'part_id' => ['nullable', 'integer', 'exists:parts,id'],
        ]);

        $stock = PartStock::query()
            ->whereRaw('LOWER(COALESCE(tag, \'\')) = ?', [mb_strtolower(trim($data['tag']))])
            ->where('qty', '>', 0)
            ->when(isset($data['part_id']), fn ($q) => $q->where('part_id', (int) $data['part_id']))
            ->orderByRaw('received_at ASC NULLS LAST')
            ->orderBy('id')
            ->first();

        if ($stock === null) {
            return response()->json([
                'ok' => false,
                'message' => __('Tag tidak ditemukan atau stok habis.'),
            ], 404);
        }

        $part = Part::find($stock->part_id);
        $receive = $stock->receive;

        return response()->json([
            'ok' => true,
            'data' => [
                'tag' => $stock->tag,
                'part_id' => (int) $stock->part_id,
                'part_number' => $part?->part_number,
                'part_name' => $part?->part_name,
                'qty' => (float) $stock->qty,
                'uom' => UomCatalog::normalize((string) $stock->qty_unit),
                'price' => $stock->price !== null ? (float) $stock->price : null,
                'invoice' => $receive?->invoice_no,
                'supplier' => $receive?->arrivalItem?->arrival?->supplier?->supplier_name,
                'received_at' => $stock->received_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Resolve QR label MESIN hasil scan → info mesin + step yang bisa dilaporkan.
     */
    public function resolveMachine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'machine_code' => ['nullable', 'string', 'max:80'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
        ]);

        $machine = Machine::query()
            ->when($data['machine_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when(! ($data['machine_id'] ?? null) && ($data['machine_code'] ?? null), fn ($q) => $q->whereRaw('LOWER(machine_code) = ?', [mb_strtolower(trim((string) $data['machine_code']))]))
            ->where('is_active', true)
            ->first();

        if ($machine === null) {
            return response()->json([
                'ok' => false,
                'message' => __('Mesin tidak ditemukan.'),
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => (int) $machine->id,
                'machine_code' => $machine->machine_code,
                'machine_name' => $machine->machine_name,
            ],
        ]);
    }

    /**
     * Step yang bisa dilaporkan + progres (mode WIP per proses).
     */
    public function resultContext(WorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('update', $workOrder);

        if ($workOrder->status !== 'in_progress') {
            return response()->json([
                'ok' => false,
                'message' => __('WO belum di-release.'),
            ], 422);
        }

        $produced = $workOrder->results()
            ->selectRaw('parent_part_id, SUM(qty_good) AS good, SUM(qty_reject) AS reject')
            ->groupBy('parent_part_id')
            ->get()
            ->keyBy('parent_part_id');

        $items = $workOrder->items()
            ->with([
                'parentPart:id,part_number,part_name',
                'parentPart.partType:id,code',
                'process:id,process_name',
                'machine:id,machine_code,machine_name',
            ])
            ->get();

        $steps = $items
            ->groupBy('parent_part_id')
            ->map(function ($rows, $parentId) use ($workOrder, $produced) {
                $first = $rows->sortBy(fn ($r) => [$r->sequence ?? 0, $r->id])->first();
                $done = $produced[$parentId] ?? null;

                return [
                    'parent_part_id' => (int) $parentId,
                    'part_number' => $first->parentPart?->part_number,
                    'part_name' => $first->parentPart?->part_name,
                    'part_type' => strtoupper((string) $first->parentPart?->partType?->code),
                    'process' => $first->process?->process_name,
                    'machine' => $first->machine?->machine_name,
                    'sequence' => $first->sequence,
                    'target_qty' => (float) $workOrder->qty,
                    'produced_qty' => (float) ($done->good ?? 0),
                    'reject_qty' => (float) ($done->reject ?? 0),
                ];
            })
            ->sortBy(fn ($s) => [$s['sequence'] ?? 0, $s['parent_part_id']])
            ->values();

        return response()->json([
            'ok' => true,
            'data' => [
                'work_order' => [
                    'id' => $workOrder->id,
                    'wo_no' => $workOrder->wo_no,
                    'qty' => (float) $workOrder->qty,
                    'status' => $workOrder->status,
                ],
                'steps' => $steps,
            ],
        ]);
    }

    /**
     * Submit hasil produksi satu step (WIP per proses).
     */
    public function storeResult(Request $request, WorkOrder $workOrder): JsonResponse
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

        $result = $this->resultService->report(
            $workOrder,
            (int) $data['parent_part_id'],
            $data,
            (int) $request->user()->id,
        );

        return response()->json([
            'ok' => true,
            'message' => __('Hasil produksi tersimpan.'),
            'data' => [
                'id' => $result->id,
                'parent_part_id' => (int) $result->parent_part_id,
                'qty_good' => (float) $result->qty_good,
                'qty_reject' => (float) $result->qty_reject,
            ],
        ]);
    }

    /**
     * Submit release WO dengan tag hasil scan (atomik + idempotent).
     */
    public function release(Request $request, WorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('issue', $workOrder);

        $data = $request->validate([
            'issue_date' => ['nullable', 'date'],
            'received_by' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.work_order_item_id' => ['required', 'integer'],
            'items.*.scans' => ['required', 'array', 'min:1'],
            'items.*.scans.*.tag' => ['required', 'string', 'max:255'],
            'items.*.scans.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.scans.*.part_id' => ['nullable', 'integer', 'exists:parts,id'],
        ]);

        [$wo, $shortages, $issue] = $this->woService->releaseWithScans(
            $workOrder,
            $data['items'],
            [
                'issue_date' => $data['issue_date'] ?? null,
                'received_by' => $data['received_by'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
            (int) $request->user()->id,
        );

        return response()->json([
            'ok' => true,
            'message' => count($shortages) > 0
                ? __('WO di-release dengan kekurangan material.')
                : __('WO di-release.'),
            'data' => [
                'issue_no' => $issue->issue_no,
                'work_order_id' => $wo->id,
                'status' => $wo->status,
                'shortages' => $shortages,
            ],
        ]);
    }
}
