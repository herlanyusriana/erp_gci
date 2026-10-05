<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\PartStock;
use App\Support\UomCatalog;
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

        // Part dengan stok > 0 yang bertipe WIP (atau FG bila diminta).
        $stocks = PartStock::query()
            ->where('qty', '>', 0)
            ->whereNotNull('tag')
            ->with(['part:id,part_number,part_name,model,size,part_type_id', 'part.partType:id,code,name'])
            ->get()
            ->filter(fn (PartStock $s) => strtoupper((string) $s->part?->partType?->code) === $type)
            ->groupBy('part_id');

        $rows = $stocks->map(function ($group, $partId) {
            /** @var PartStock $first */
            $first = $group->first();
            $qty = (float) $group->sum('qty');
            $uom = UomCatalog::normalize((string) $first->qty_unit);

            return [
                'part_id' => (int) $partId,
                'part_number' => $first->part?->part_number,
                'part_name' => $first->part?->part_name,
                'model' => $first->part?->model,
                'size' => $first->part?->size,
                'uom' => $uom,
                'qty' => round($qty, 4),
                'tags' => $group->map(fn (PartStock $s) => [
                    'tag' => $s->tag,
                    'qty' => round((float) $s->qty, 4),
                    'received_at' => $s->received_at?->toIso8601String(),
                ])->values()->all(),
            ];
        })->values()->all();

        return response()->json([
            'ok' => true,
            'data' => [
                'type' => $type,
                'parts' => $rows,
            ],
        ]);
    }
}
