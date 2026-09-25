<?php

namespace App\Services;

use App\Models\Part;
use App\Models\PartStock;
use App\Support\UomCatalog;
use Illuminate\Support\Carbon;

/**
 * Snapshot stok per part: total, tersedia, dan tag yang layak discan.
 *
 * Tag memuat **invoice** dan **supplier** asal material, diurutkan FIFO: kedatangan
 * fisik (`received_at`), lalu tanggal invoice, lalu id.
 *
 * Dipakai bersama oleh konteks release WO dan papan material harian, sehingga
 * urutan serta isi tag selalu sama di kedua tempat.
 */
class PartStockTagService
{
    public function __construct(private ReceiveMaterialService $stockService) {}

    /**
     * @param  list<int>  $partIds
     * @return array<int, array{stock_qty: float, available_qty: float, tags: list<array<string, mixed>>}>
     */
    public function forParts(array $partIds, ?string $uom): array
    {
        $partIds = array_values(array_unique(array_map('intval', $partIds)));

        $result = [];
        foreach ($partIds as $partId) {
            $result[$partId] = ['stock_qty' => 0.0, 'available_qty' => 0.0, 'tags' => []];
        }

        if ($partIds === []) {
            return $result;
        }

        $stocks = PartStock::query()
            ->whereIn('part_id', $partIds)
            ->where('qty', '>', 0)
            ->with([
                'receive:id,invoice_no,arrival_item_id',
                'receive.arrivalItem:id,arrival_id',
                'receive.arrivalItem.arrival:id,supplier_id,invoice_date',
                'receive.arrivalItem.arrival.supplier:id,supplier_name',
            ])
            ->get(['id', 'part_id', 'tag', 'qty', 'qty_unit', 'received_at', 'receive_id'])
            ->sortBy(fn (PartStock $stock) => [
                $stock->received_at === null ? 1 : 0,
                $stock->received_at?->getTimestamp() ?? 0,
                $this->invoiceDate($stock) === null ? 1 : 0,
                $this->invoiceDate($stock)?->getTimestamp() ?? 0,
                $stock->id,
            ])
            ->values();

        $bookedByStock = $this->stockService->bookedQtyByStock($stocks->pluck('id'));
        $partNumbers = Part::query()->whereIn('id', $partIds)->pluck('part_number', 'id');

        foreach ($stocks as $stock) {
            if ($uom !== null && UomCatalog::normalize((string) $stock->qty_unit) !== $uom) {
                continue;
            }

            $partId = (int) $stock->part_id;
            $booked = (float) ($bookedByStock[$stock->id] ?? 0);
            $available = max(0.0, (float) $stock->qty - $booked);

            $result[$partId]['stock_qty'] += (float) $stock->qty;
            $result[$partId]['available_qty'] += $available;

            if ($available <= 1e-9) {
                continue;
            }

            $result[$partId]['tags'][] = [
                'tag' => $stock->tag,
                'part_id' => $partId,
                'part_number' => $partNumbers[$partId] ?? null,
                'qty' => round($available, 4),
                'stock_qty' => (float) $stock->qty,
                'booked' => $booked,
                'uom' => UomCatalog::normalize((string) $stock->qty_unit),
                'received_at' => $stock->received_at?->toIso8601String(),
                'invoice' => $stock->receive?->invoice_no,
                'supplier' => $stock->receive?->arrivalItem?->arrival?->supplier?->supplier_name,
            ];
        }

        foreach ($result as $partId => $row) {
            $result[$partId]['stock_qty'] = round($row['stock_qty'], 4);
            $result[$partId]['available_qty'] = round($row['available_qty'], 4);
        }

        return $result;
    }

    /** Tanggal invoice kedatangan yang menaungi sebuah baris stok, bila ada. */
    private function invoiceDate(PartStock $stock): ?Carbon
    {
        return $stock->receive?->arrivalItem?->arrival?->invoice_date;
    }
}
