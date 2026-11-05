<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WipStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Papan WIP untuk APK Production.
 *
 * Menampilkan stok WIP (hasil produksi antar-step) dan FG yang sudah jadi,
 * per part. WIP berasal dari `ProductionResult` yang diposting ke stok dengan
 * part_type WIP; FG dengan part_type FG.
 */
class WipBoardApiController extends Controller
{
    public function __construct(private WipStockService $wipStock) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->hasPermission('production.view')
                || $request->user()?->hasPermission('stock.issue')
                || $request->user()?->hasPermission('work_order.view'),
            403,
            __('Tidak berwenang melihat papan WIP.'),
        );

        $type = strtoupper((string) $request->input('type', 'WIP'));

        // Sumber tunggal bersama halaman WIP Inventory di Web.
        $rows = $this->wipStock->current($type);

        return response()->json([
            'ok' => true,
            'data' => [
                'type' => $type,
                'parts' => $rows,
            ],
        ]);
    }
}
