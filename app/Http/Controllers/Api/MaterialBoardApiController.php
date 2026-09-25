<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\DailyScheduleService;
use App\Services\MaterialBoardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Papan material harian untuk APK Material Tracker.
 *
 * Satu baris = satu pasangan (material, satuan), berisi qty kebutuhan untuk
 * tanggal berjalan dan besoknya. Endpoint ini tidak mengubah endpoint lama.
 */
class MaterialBoardApiController extends Controller
{
    public function __construct(
        private MaterialBoardService $board,
        private DailyScheduleService $schedule,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $waiting = $this->schedule->waitingForPlan($this->schedule->plantToday());

        return response()->json([
            'ok' => true,
            'data' => [
                ...$this->board->board(),
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
     * Rincian satu material: WO yang membutuhkannya dan subspart yang bisa discan.
     *
     * `uom` opsional tetapi sebaiknya selalu dikirim APK, karena satu material bisa
     * diminta dalam satuan berbeda dan agregatnya tidak boleh dicampur.
     */
    public function details(Request $request, int $part): JsonResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'uom' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->board->details(
                $part,
                $data['date'] ?? $this->schedule->plantToday(),
                $data['uom'] ?? null,
            ),
        ]);
    }
}
