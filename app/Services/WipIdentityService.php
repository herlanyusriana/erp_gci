<?php

namespace App\Services;

use App\Models\PartStock;
use App\Models\ProductionResult;
use App\Support\UomCatalog;

/**
 * Identitas WIP mengikuti **nomor WO**, bukan nomor batch terpisah.
 *
 * Keputusan PRD: barcode/label per unit ditolak karena biaya kertas dan waktu
 * tempel. Konsekuensinya WIP tidak bisa dibedakan per jam; beberapa periode
 * dalam WO yang sama menambah qty pada identitas yang sama (kunci: WO + part).
 */
final class WipIdentityService
{
    public function __construct(private ReceiveMaterialService $stockService) {}

    /**
     * Identitas WIP sebuah WO: satu baris per part hasil.
     *
     * @return list<array<string, mixed>>
     */
    public function forWorkOrder(int $workOrderId): array
    {
        $rows = ProductionResult::query()
            ->where('work_order_id', $workOrderId)
            ->with(['parentPart:id,part_number,part_name'])
            ->get(['id', 'work_order_id', 'parent_part_id', 'qty_good', 'uom', 'shift', 'result_date']);

        if ($rows->isEmpty()) {
            return [];
        }

        $byPart = $rows->groupBy('parent_part_id');
        $partIds = $byPart->keys()->map(fn ($id) => (int) $id)->all();
        $available = $this->availableByPart($partIds);

        return $byPart
            ->map(function ($group, $partId) use ($available): array {
                $first = $group->first();
                $produced = (float) $group->sum('qty_good');
                $uom = UomCatalog::normalize((string) ($first->uom ?? ''));

                return [
                    'work_order_id' => (int) $first->work_order_id,
                    'part_id' => (int) $partId,
                    'part_number' => $first->parentPart?->part_number,
                    'part_name' => $first->parentPart?->part_name,
                    'uom' => $uom,
                    'produced_qty' => round($produced, 4),
                    'available_qty' => round((float) ($available[(int) $partId] ?? 0.0), 4),
                    'result_count' => $group->count(),
                    'first_result_date' => $group->min(fn ($r) => $r->result_date?->toDateString()),
                    'last_result_date' => $group->max(fn ($r) => $r->result_date?->toDateString()),
                ];
            })
            ->sortBy('part_number')
            ->values()
            ->all();
    }

    /**
     * WO mana saja yang menyediakan WIP berstok untuk sebuah part.
     *
     * Dipakai sebagai pengaman FIFO: bila hanya satu kandidat, konsumsi berjalan
     * otomatis; bila lebih dari satu, APK harus bertanya WO mana yang dipakai.
     *
     * @return list<array{work_order_id:int, wo_no:string, available_qty:float, uom:string|null}>
     */
    public function candidatesForPart(int $partId): array
    {
        // Kandidat diturunkan dari hasil produksi yang masih menautkan stok WIP
        // berisi saldo. `output_part_stock_id` adalah satu-satunya tautan
        // eksplisit antara hasil produksi dan stok yang lahir darinya.
        $linked = ProductionResult::query()
            ->where('parent_part_id', $partId)
            ->whereNotNull('output_part_stock_id')
            ->with('workOrder:id,wo_no')
            ->get(['id', 'work_order_id', 'output_part_stock_id']);

        if ($linked->isEmpty()) {
            return [];
        }

        $stocks = PartStock::query()
            ->whereIn('id', $linked->pluck('output_part_stock_id')->unique()->all())
            ->where('qty', '>', 0)
            ->get(['id', 'qty', 'qty_unit'])
            ->keyBy('id');

        if ($stocks->isEmpty()) {
            return [];
        }

        $booked = $this->stockService->bookedQtyByStock($stocks->keys()->all());

        $byWorkOrder = [];
        foreach ($linked as $result) {
            $stock = $stocks[$result->output_part_stock_id] ?? null;
            if ($stock === null) {
                continue;
            }

            $available = max(0.0, (float) $stock->qty - (float) ($booked[$stock->id] ?? 0));
            if ($available <= 1e-9) {
                continue;
            }

            $woId = (int) $result->work_order_id;
            $byWorkOrder[$woId] ??= [
                'work_order_id' => $woId,
                'wo_no' => (string) ($result->workOrder?->wo_no ?? ''),
                'available_qty' => 0.0,
                'uom' => UomCatalog::normalize((string) $stock->qty_unit) ?? UomCatalog::PIECE,
            ];
            $byWorkOrder[$woId]['available_qty'] += $available;
        }

        return collect($byWorkOrder)
            ->map(fn (array $row): array => [
                ...$row,
                'available_qty' => round($row['available_qty'], 4),
            ])
            ->sortBy('work_order_id')
            ->values()
            ->all();
    }

    /**
     * Stok WIP tersedia per part (dikurangi booking WO aktif).
     *
     * @param  list<int>  $partIds
     * @return array<int, float>
     */
    private function availableByPart(array $partIds): array
    {
        if ($partIds === []) {
            return [];
        }

        $stocks = PartStock::query()
            ->whereIn('part_id', $partIds)
            ->where('qty', '>', 0)
            ->get(['id', 'part_id', 'qty']);

        if ($stocks->isEmpty()) {
            return [];
        }

        $booked = $this->stockService->bookedQtyByStock($stocks->pluck('id'));

        $result = [];
        foreach ($stocks as $stock) {
            $partId = (int) $stock->part_id;
            $result[$partId] = ($result[$partId] ?? 0.0)
                + max(0.0, (float) $stock->qty - (float) ($booked[$stock->id] ?? 0));
        }

        return $result;
    }
}
