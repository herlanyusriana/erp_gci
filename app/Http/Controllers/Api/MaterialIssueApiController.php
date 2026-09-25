<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\WorkOrder;
use App\Services\DailyScheduleService;
use App\Services\ProductionResultService;
use App\Services\ReceiveMaterialService;
use App\Services\ReleaseContextService;
use App\Services\WoService;
use App\Support\UomCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * API mobile "issue out to production" — release WO lewat scan label.
 * Online-only; submit bersifat atomik + idempotent.
 */
class MaterialIssueApiController extends Controller
{
    public function __construct(
        private WoService $woService,
        private ProductionResultService $resultService,
        private ReceiveMaterialService $stockService,
        private ReleaseContextService $releaseContextService,
        private DailyScheduleService $schedule,
    ) {}

    /**
     * Daftar WO yang dijadwalkan pada tanggal pabrik berjalan.
     *
     * Kolom Production Plan yang dibaca bergeser sesuai jarak tanggal:
     * `target_d` pada `plan_date`, `target_d1` sehari sesudahnya, `target_d2` dua
     * hari sesudahnya. Qty hari itu diambil dari nilai terbesar antar baris mesin.
     */
    public function workOrders(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $today = $this->schedule->plantToday();
        $waiting = $this->schedule->waitingForPlan($today);

        $page = WorkOrder::query()
            ->with(['part:id,part_number,part_name,model'])
            ->joinSub($this->schedule->scheduledQuantities($today), 'schedule', 'schedule.work_order_id', '=', 'work_orders.id')
            ->leftJoinSub($this->schedule->issuedQuantities(), 'issued', 'issued.work_order_id', '=', 'work_orders.id')
            ->whereIn('work_orders.status', ['planned', 'in_progress'])
            ->when($request->input('status'), fn ($q, $status) => $q->where('work_orders.status', $status))
            ->when($request->input('search'), function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('work_orders.wo_no', 'ilike', "%{$search}%")
                        ->orWhereHas('part', fn ($p) => $p->where('part_number', 'ilike', "%{$search}%"));
                });
            })
            ->orderByRaw('schedule.step_sequence ASC NULLS LAST')
            ->orderBy('work_orders.wo_no')
            ->select('work_orders.*')
            ->selectRaw('schedule.planned_qty AS schedule_planned_qty')
            ->selectRaw('schedule.plan_date AS schedule_plan_date')
            ->selectRaw('COALESCE(issued.issued_qty, 0) AS issued_total')
            ->paginate(min(max((int) $request->input('per_page', 200), 1), 500));

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map(function (WorkOrder $wo) {
                $planned = round((float) $wo->schedule_planned_qty, 4);
                $issued = round((float) $wo->issued_total, 4);

                return [
                    'id' => $wo->id,
                    'wo_no' => $wo->wo_no,
                    'status' => $wo->status,
                    'qty' => (float) $wo->qty,
                    'planned_date' => $wo->planned_date?->toDateString(),
                    'plan_date' => $wo->schedule_plan_date,
                    'planned_qty' => $planned,
                    'issued_qty' => $issued,
                    'remaining_qty' => round(max(0.0, $planned - $issued), 4),
                    'part' => $wo->part ? [
                        'id' => $wo->part->id,
                        'part_number' => $wo->part->part_number,
                        'part_name' => $wo->part->part_name,
                        'model' => $wo->part->model,
                    ] : null,
                ];
            })->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'waiting_for_plan' => $waiting->count(),
                'waiting_work_orders' => $waiting->map(fn (WorkOrder $wo) => [
                    'id' => $wo->id,
                    'wo_no' => $wo->wo_no,
                    'part' => $wo->part ? [
                        'part_number' => $wo->part->part_number,
                        'part_name' => $wo->part->part_name,
                    ] : null,
                ])->values()->all(),
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
                'items' => $this->releaseContextService->items($workOrder),
            ],
        ]);
    }

    /**
     * Resolve satu tag hasil scan → info part & stok tersedia.
     */
    public function resolveTag(Request $request): JsonResponse
    {
        // Resolusi tag mengungkap stok, harga, invoice, dan supplier —
        // batasi ke pemegang permission issue material.
        abort_unless(
            $request->user()?->hasPermission('stock.issue') ?? false,
            403,
            __('Tidak berwenang mengakses data stok.'),
        );

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

        // Tag yang stoknya habis ter-book WO lain dianggap tidak tersedia.
        if ($stock !== null) {
            $booked = (float) ($this->stockService->bookedQtyByStock([$stock->id])[$stock->id] ?? 0);
            if ((float) $stock->qty - $booked <= 1e-9) {
                $stock = null;
            }
        }

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
                'booked' => (float) ($this->stockService->bookedQtyByStock([$stock->id])[$stock->id] ?? 0),
                'uom' => UomCatalog::normalize((string) $stock->qty_unit),
                'price' => $stock->price !== null ? (float) $stock->price : null,
                'invoice' => $receive?->invoice_no,
                'supplier' => $receive?->arrivalItem?->arrival?->supplier?->supplier_name,
                // Rak yang tercatat saat penerimaan; APK memakainya untuk
                // memperingatkan bila operator memindai rak yang berbeda.
                'location_code' => $receive?->location_code,
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
            // Lokasi rak bersifat opsional: satu data rak yang basi tidak boleh
            // menghentikan pengeluaran material.
            'location_code' => ['nullable', 'string', 'max:40', Rule::exists('locations', 'code')->where('is_active', true)],
            // Wajib: mencegah Issue Out ganda saat APK retry setelah jaringan putus.
            'idempotency_key' => ['required', 'string', 'max:80'],
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
                'location_code' => $data['location_code'] ?? null,
                'idempotency_key' => $data['idempotency_key'],
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
