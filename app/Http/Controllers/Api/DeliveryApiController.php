<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryScan;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * JSON API untuk Flutter "Material Tracker" — loading barang jadi ke truk.
 *
 * Operator memindai label troli, lalu memindai label FG hasil produksi.
 * Tahap loading di web tidak bisa dirilis sebelum kedua jenis scan lengkap.
 */
class DeliveryApiController extends Controller
{
    public function __construct(private DeliveryService $deliveryService) {}

    /** Daftar pengiriman yang siap dimuat (QC lolos / sedang dimuat). */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Delivery::class);

        $deliveries = Delivery::query()
            ->with('customer:id,customer_code,customer_name')
            ->whereIn('status', [Delivery::STATUS_QC_PASSED, Delivery::STATUS_LOADED])
            ->orderBy('delivery_date')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (Delivery $delivery): array => [
                'id' => (int) $delivery->id,
                'delivery_no' => (string) $delivery->delivery_no,
                'delivery_date' => $delivery->delivery_date?->toDateString(),
                'status' => (string) $delivery->status,
                'customer_name' => $delivery->customer?->customer_name,
                'truck_no' => $delivery->truck_no,
                'scanned_tags' => $delivery->scannedTagCount(),
                'total_tags' => $delivery->items()->count(),
                'trolleys' => $delivery->scannedTrolleyCount(),
            ])
            ->values();

        return response()->json(['data' => $deliveries]);
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
        ]);

        try {
            $result = $this->deliveryService->recordScan(
                $delivery,
                $validated['code'],
                $request->user()?->id,
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

    /** Hapus satu scan (salah pindai). */
    public function destroyScan(Delivery $delivery, DeliveryScan $scan): JsonResponse
    {
        Gate::authorize('update', $delivery);

        abort_unless((int) $scan->delivery_id === (int) $delivery->id, 404);

        if ($delivery->isLoaded() || $delivery->isReleased()) {
            return response()->json([
                'ok' => false,
                'message' => __('Label sudah dirilis; scan tidak dapat dihapus.'),
            ], 422);
        }

        $scan->delete();

        return response()->json([
            'ok' => true,
            'data' => $this->deliveryService->loadingContext($delivery->fresh()),
        ]);
    }
}
