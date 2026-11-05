<?php

namespace App\Services;

use App\Models\Part;
use App\Models\PartStock;
use App\Support\UomCatalog;
use Illuminate\Support\Collection;

/**
 * Stok WIP fisik yang tersedia saat ini.
 *
 * Sumber tunggal untuk Web (WIP Inventory) dan APK (Papan WIP), supaya angka
 * di kedua tempat tidak pernah berbeda. Yang dihitung adalah stok yang lahir
 * dari laporan produksi (`part_stocks` bertipe WIP dengan qty > 0) — bukan
 * hitungan resep BOM.
 */
final class WipStockService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function current(string $type = 'WIP'): array
    {
        $type = strtoupper($type) !== '' ? strtoupper($type) : 'WIP';

        $stocks = PartStock::query()
            ->where('qty', '>', 0)
            ->whereNotNull('tag')
            ->with([
                'part:id,part_number,part_name,model,size,part_type_id',
                'part.partType:id,code,name',
            ])
            ->get(['id', 'part_id', 'tag', 'qty', 'qty_unit', 'received_at'])
            ->filter(fn (PartStock $stock): bool => strtoupper((string) $stock->part?->partType?->code) === $type);

        return $this->groupByPart($stocks);
    }

    /**
     * @param  Collection<int, PartStock>  $stocks
     * @return list<array<string, mixed>>
     */
    private function groupByPart(Collection $stocks): array
    {
        return $stocks
            ->groupBy('part_id')
            ->map(function (Collection $group, $partId): array {
                /** @var PartStock $first */
                $first = $group->first();
                $qty = (float) $group->sum(fn (PartStock $s) => (float) $s->qty);

                $oldest = $group
                    ->filter(fn (PartStock $s) => $s->received_at !== null)
                    ->sortBy(fn (PartStock $s) => $s->received_at)
                    ->first();

                return [
                    'part_id' => (int) $partId,
                    'part_number' => $first->part?->part_number,
                    'part_name' => $first->part?->part_name,
                    'model' => $first->part?->model,
                    'size' => $first->part?->size,
                    'uom' => UomCatalog::normalize((string) $first->qty_unit),
                    'qty' => round($qty, 4),
                    'tag_count' => $group->count(),
                    'oldest_received_at' => $oldest?->received_at?->toIso8601String(),
                    'tags' => $group
                        ->map(fn (PartStock $s): array => [
                            'tag' => $s->tag,
                            'qty' => round((float) $s->qty, 4),
                            'received_at' => $s->received_at?->toIso8601String(),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy(fn (array $row) => (string) $row['part_number'])
            ->values()
            ->all();
    }

    /**
     * Total qty WIP untuk satu part — dipakai saat memeriksa ketersediaan.
     */
    public function qtyForPart(int $partId): float
    {
        return round((float) PartStock::query()
            ->where('part_id', $partId)
            ->where('qty', '>', 0)
            ->sum('qty'), 4);
    }

    /** Apakah part ini bertipe WIP? */
    public function isWipPart(int $partId): bool
    {
        $part = Part::query()->with('partType:id,code')->find($partId);

        return strtoupper((string) $part?->partType?->code) === 'WIP';
    }
}
