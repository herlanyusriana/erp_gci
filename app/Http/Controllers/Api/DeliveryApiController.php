<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryScan;
use App\Models\PartStock;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * JSON API untuk Flutter "Material Tracker" — loading barang jadi ke truk.
 *
 * Operator memilih customer/seq dulu, lalu memindai label FG (+ qty) dan label
 * troli. Stok FG dipotong saat pemindahan selesai; submit hanya mengunci
 * dokumen.
 */
class DeliveryApiController extends Controller
{
    public function __construct(private DeliveryService $deliveryService) {}

    /** Daftar pengiriman yang siap dimuat (QC lolos / sedang dimuat). */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Delivery::class);

        $deliveries = Delivery::query()
            ->with([
                'customer:id,customer_code,customer_name',
                'pickingList:id,picking_no',
                'items:id,delivery_id,part_id,qty',
                'scans:id,delivery_id,scan_type,part_stock_id,qty',
            ])
            ->whereIn('status', [Delivery::STATUS_DRAFT, Delivery::STATUS_PICKED])
            ->orderBy('delivery_date')
            ->orderBy('id')
            ->limit(50)
            ->get();

        // part_id untuk setiap tag yang dipindai, satu query untuk semua dokumen.
        $stockParts = PartStock::query()
            ->whereIn('id', $deliveries->pluck('scans')->flatten()->pluck('part_stock_id')->filter()->unique())
            ->pluck('part_id', 'id');

        $rows = $deliveries->map(function (Delivery $delivery) use ($stockParts): array {
            $scannedByPart = $delivery->scans
                ->where('scan_type', DeliveryScan::TYPE_TAG)
                ->groupBy(fn (DeliveryScan $scan) => (int) ($stockParts[$scan->part_stock_id] ?? 0))
                ->map(fn ($group): float => (float) $group->sum('qty'));

            $plannedByPart = $delivery->items
                ->groupBy('part_id')
                ->map(fn ($group): float => (float) $group->sum('qty'));

            $covered = $plannedByPart->filter(
                fn (float $qty, $partId): bool => ($scannedByPart[$partId] ?? 0.0) + 1e-9 >= $qty,
            );

            return [
                'id' => (int) $delivery->id,
                'delivery_no' => (string) $delivery->delivery_no,
                'picking_list_id' => $delivery->picking_list_id !== null ? (int) $delivery->picking_list_id : null,
                'picking_no' => $delivery->pickingList?->picking_no,
                'delivery_date' => $delivery->delivery_date?->toDateString(),
                'status' => (string) $delivery->status,
                'customer_name' => $delivery->customer?->customer_name,
                'truck_no' => $delivery->truck_no,
                'scanned_tags' => $covered->count(),
                'total_tags' => $plannedByPart->count(),
                'trolleys' => $delivery->scans->where('scan_type', DeliveryScan::TYPE_TROLLEY)->count(),
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    /** Konteks loading: item, progres scan, dan daftar troli. */
    public function loadingContext(Delivery $delivery): JsonResponse
    {
        Gate::authorize('view', $delivery);

        return response()->json([
            'ok' => true,
            'data' => $this->deliveryService->loadingContext($delivery),
        ]);
    }

    /** Pindai satu kode: label troli atau label FG produksi. */
    public function scan(Request $request, Delivery $delivery): JsonResponse
    {
        Gate::authorize('update', $delivery);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:1000'],
            'qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $result = $this->deliveryService->recordScan(
                $delivery,
                $validated['code'],
                $request->user()?->id,
                isset($validated['qty']) ? (float) $validated['qty'] : null,
            );
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                ...$result,
                'context' => $this->deliveryService->loadingContext($delivery->fresh()),
            ],
        ]);
    }

    /** OQC per troli: ok / ng. */
    public function trolleyQc(Request $request, Delivery $delivery): JsonResponse
    {
        Gate::authorize('oqc', $delivery);

        $validated = $request->validate([
            'trolley_code' => ['required', 'string', 'max:100'],
            'status' => ['required', 'in:ok,ng'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->deliveryService->setTrolleyQc(
                $delivery,
                $validated['trolley_code'],
                $validated['status'] === 'ok' ? Delivery::QC_PASSED : Delivery::QC_REJECTED,
                $request->user()?->id,
                $validated['notes'] ?? null,
            );
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->deliveryService->loadingContext($delivery->fresh()),
        ]);
    }

    /** Hapus satu scan (salah pindai). */
    public function destroyScan(Delivery $delivery, DeliveryScan $scan): JsonResponse
    {
        Gate::authorize('update', $delivery);

        abort_unless((int) $scan->delivery_id === (int) $delivery->id, 404);

        // Setelah pindah, stok sudah dipotong; hapus scan akan membuat selisih.
        if (! $delivery->isDraft()) {
            return response()->json([
                'ok' => false,
                'message' => __('Scan hanya dapat dihapus sebelum barang dipindah ke area pengiriman.'),
            ], 422);
        }

        if ($scan->deducted_qty !== null) {
            return response()->json([
                'ok' => false,
                'message' => __('Scan ini sudah memotong stok. Kembalikan barang ke lokasi asal sebelum menghapusnya.'),
            ], 422);
        }

        $scan->delete();

        return response()->json([
            'ok' => true,
            'data' => $this->deliveryService->loadingContext($delivery->fresh()),
        ]);
    }
}
