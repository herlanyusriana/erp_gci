<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncomingArrival as Arrival;
use App\Models\IncomingArrivalItem as ArrivalItem;
use App\Services\ReceiveMaterialService;
use App\Support\UomCatalog;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * JSON API untuk Flutter "Material Tracker" — incoming receiving.
 * Slim single-tag receive; kontrol penuh (multi-tag, bundle, edit) tetap via web.
 */
class IncomingApiController extends Controller
{
    public function __construct(private ReceiveMaterialService $receiveService) {}

    public function departures(): JsonResponse
    {
        Gate::authorize('viewAny', Arrival::class);

        $arrivals = Arrival::query()
            ->with(['supplier:id,supplier_name', 'items.receives'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $arrivals->map(function (Arrival $arrival) {
                return [
                    'id' => $arrival->id,
                    'invoice_no' => $arrival->invoice_no,
                    'arrival_no' => $arrival->arrival_no,
                    'is_local' => (bool) $arrival->is_local,
                    'supplier_name' => $arrival->supplier?->supplier_name,
                    'items' => $arrival->items->map(function (ArrivalItem $item) {
                        $received = (float) $item->receives()->sum('qty');

                        return [
                            'id' => $item->id,
                            'part_no' => $item->part?->part_number,
                            'qty_goods' => (float) $item->qty_goods,
                            'unit_goods' => $item->unit_goods,
                            'qty_received' => $received,
                            'qty_remaining' => max(0, (float) $item->qty_goods - $received),
                        ];
                    })->values(),
                ];
            }),
        ]);
    }

    public function receive(Request $request, ArrivalItem $arrivalItem): JsonResponse
    {
        Gate::authorize('receive', $arrivalItem->arrival);

        $validated = $request->validate([
            'receive_date' => ['required', 'date'],
            'tag' => ['required', 'string', 'max:255'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            'qc_status' => ['required', 'in:pass,reject'],
        ]);

        $arrivalItem->loadMissing(['arrival']);
        $goodsUnit = UomCatalog::normalize($arrivalItem->unit_goods) ?? UomCatalog::WEIGHT;

        try {
            $this->receiveService->ensureTagsUniqueForArrivalItem($arrivalItem, [
                ['tag' => trim($validated['tag']), 'qty' => $validated['qty'], 'qc_status' => $validated['qc_status']],
            ], 'tags');
        } catch (ValidationException) {
            return response()->json(['ok' => false, 'message' => __('Tag sudah pernah dipakai pada item ini.')], 422);
        } catch (HttpResponseException) {
            return response()->json(['ok' => false, 'message' => __('Tag sudah pernah dipakai pada item ini.')], 422);
        }

        $totalRequested = (float) $validated['qty'];
        $remainingQty = $this->receiveService->remainingQty($arrivalItem);
        if ($totalRequested > $remainingQty + 1e-9) {
            return response()->json([
                'ok' => false,
                'message' => __('Qty melebihi sisa (:remaining :unit).', ['remaining' => $remainingQty, 'unit' => $goodsUnit]),
            ], 422);
        }

        $receiveAt = Carbon::parse($validated['receive_date'])->setTimeFromTimeString(now()->format('H:i:s'));

        $receive = DB::transaction(function () use ($validated, $arrivalItem, $goodsUnit, $receiveAt) {
            $tag = $this->receiveService->normalizeTag($validated['tag']);

            $netWeight = UomCatalog::isWeight($goodsUnit) ? (float) $validated['qty'] : null;

            $receive = $arrivalItem->receives()->create([
                'part_id' => $this->receiveService->resolvePartId($arrivalItem),
                'tag' => $tag,
                'qty' => $validated['qty'],
                'bundle_qty' => 0,
                'bundle_unit' => null,
                'weight' => $netWeight,
                'net_weight' => $netWeight,
                'gross_weight' => null,
                'qty_unit' => $goodsUnit,
                'qc_status' => $validated['qc_status'],
                'invoice_no' => $arrivalItem->arrival?->invoice_no,
                'truck_no' => null,
                'ata_date' => $receiveAt,
            ]);

            $resolvedTag = $this->receiveService->resolveReceiveTag($validated['tag'], (int) $receive->id, $receiveAt);
            if ($resolvedTag !== null && $receive->tag !== $resolvedTag) {
                $receive->update(['tag' => $resolvedTag]);
                $receive->tag = $resolvedTag;
            }

            if ($validated['qc_status'] === 'pass') {
                $this->receiveService->postStock($receive);
            }

            return $receive;
        });

        $arrival = $arrivalItem->arrival()->with('items.receives')->first();
        if ($arrival && ! $this->receiveService->hasPendingReceives($arrival) && empty($arrival->transaction_no)) {
            $arrival->transaction_no = Arrival::generateTransactionNo($receiveAt->toDateString());
            $arrival->save();
        }

        return response()->json([
            'ok' => true,
            'message' => __('Tag :tag diterima (:qty :unit).', ['tag' => $receive->tag, 'qty' => $validated['qty'], 'unit' => $goodsUnit]),
            'data' => ['receive_id' => $receive->id],
        ]);
    }
}
