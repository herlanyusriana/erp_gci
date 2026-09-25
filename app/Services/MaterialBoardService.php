<?php

namespace App\Services;

use App\Models\Part;
use App\Models\PartSubstitute;
use App\Support\UomCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Papan material harian: berapa banyak sebuah material harus keluar pada tanggal
 * D dan D+1, dan WO mana saja yang membutuhkannya.
 *
 * Baris dikunci per pasangan (material, satuan) karena satu material bisa diminta
 * dalam satuan berbeda; menjumlahkan KGM dengan PCS akan salah.
 *
 * Material yang ditampilkan adalah **material acuan BOM** dari item leaf. Di sini
 * perannya adalah kebutuhan, bukan barang yang discan — yang discan tetap subspart
 * berstok (lihat `ReleaseContextService`).
 */
class MaterialBoardService
{
    public function __construct(
        private DailyScheduleService $schedule,
        private PartStockTagService $stockTags,
    ) {}

    /**
     * Rincian satu material pada satu tanggal: WO mana saja yang membutuhkannya,
     * dan subspart mana saja yang bisa discan.
     *
     * @return array{date: string, material: array<string, mixed>, work_orders: list<array<string, mixed>>, substitutes: list<array<string, mixed>>}
     */
    public function details(int $partId, string $date, ?string $uom): array
    {
        $uom = UomCatalog::normalize($uom);

        $rows = DB::table('work_order_items as woi')
            ->join('work_orders as wo', 'wo.id', '=', 'woi.work_order_id')
            ->joinSub(
                $this->schedule->scheduledQuantities($date),
                'schedule',
                'schedule.work_order_id',
                '=',
                'wo.id',
            )
            ->leftJoin('parts as fg', 'fg.id', '=', 'wo.part_id')
            ->where('woi.child_part_id', $partId)
            ->where('wo.qty', '>', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('work_order_items as sibling')
                ->whereColumn('sibling.work_order_id', 'woi.work_order_id')
                ->whereColumn('sibling.parent_part_id', 'woi.child_part_id')
                ->whereRaw("UPPER(COALESCE(sibling.source, '')) <> 'SUBCON'"))
            ->when($uom !== null, fn ($q) => $q->whereRaw("UPPER(TRIM(COALESCE(woi.uom_rm, ''))) = ?", [$uom]))
            ->orderBy('wo.wo_no')
            ->get([
                'wo.id as work_order_id',
                'wo.wo_no',
                'wo.status',
                'wo.qty as wo_qty',
                'woi.id as work_order_item_id',
                'woi.qty_required',
                'woi.uom_rm',
                'schedule.planned_qty',
                'fg.part_number as fg_part_number',
                'fg.part_name as fg_part_name',
                'fg.model as fg_model',
            ]);

        $issuedByItem = DB::table('material_issue_items as mii')
            ->join('material_issues as mi', 'mi.id', '=', 'mii.material_issue_id')
            ->where('mi.status', 'posted')
            ->whereIn('mii.work_order_item_id', $rows->pluck('work_order_item_id')->all())
            ->groupBy('mii.work_order_item_id')
            ->selectRaw('mii.work_order_item_id, SUM(mii.qty) AS issued_qty')
            ->pluck('issued_qty', 'work_order_item_id');

        $workOrders = $rows->map(function ($row) use ($issuedByItem) {
            $planned = (float) $row->planned_qty;
            $woQty = (float) $row->wo_qty;
            $required = round((float) $row->qty_required * ($planned / $woQty), 4);
            $issued = round((float) ($issuedByItem[$row->work_order_item_id] ?? 0), 4);

            return [
                'id' => (int) $row->work_order_id,
                'work_order_item_id' => (int) $row->work_order_item_id,
                'wo_no' => $row->wo_no,
                'status' => $row->status,
                'qty' => $woQty,
                'planned_qty' => round($planned, 4),
                'required_qty' => $required,
                'issued_qty' => $issued,
                'remaining_qty' => round(max(0.0, $required - $issued), 4),
                'uom' => UomCatalog::normalize((string) $row->uom_rm),
                'fg_part' => $row->fg_part_number === null ? null : [
                    'part_number' => $row->fg_part_number,
                    'part_name' => $row->fg_part_name,
                    'model' => $row->fg_model,
                ],
            ];
        })->values();

        $part = Part::query()->find($partId, ['id', 'part_number', 'part_name', 'model', 'size']);
        $requiredTotal = round((float) $workOrders->sum('required_qty'), 4);
        $issuedTotal = round((float) $workOrders->sum('issued_qty'), 4);

        $substituteIds = PartSubstitute::query()
            ->where('part_id', $partId)
            ->where('is_active', true)
            ->pluck('substitute_part_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $stock = $this->stockTags->forParts($substituteIds, $uom);
        $substituteParts = Part::query()
            ->whereIn('id', $substituteIds)
            ->get(['id', 'part_number', 'part_name', 'size'])
            ->keyBy('id');

        $substitutes = collect($substituteIds)->map(function (int $id) use ($stock, $substituteParts, $uom) {
            $stats = $stock[$id] ?? ['stock_qty' => 0.0, 'available_qty' => 0.0, 'tags' => []];

            return [
                'part_id' => $id,
                'part_number' => $substituteParts[$id]->part_number ?? null,
                'part_name' => $substituteParts[$id]->part_name ?? null,
                'size' => $substituteParts[$id]->size ?? null,
                'uom' => $uom,
                'stock_qty' => $stats['stock_qty'],
                'available_qty' => $stats['available_qty'],
                'tags' => $stats['tags'],
            ];
        })->values()->all();

        return [
            'date' => $date,
            'material' => [
                'part_id' => $partId,
                'part_number' => $part?->part_number,
                'part_name' => $part?->part_name,
                'model' => $part?->model,
                'size' => $part?->size,
                'uom' => $uom,
                'required_qty' => $requiredTotal,
                'issued_qty' => $issuedTotal,
                'remaining_qty' => round(max(0.0, $requiredTotal - $issuedTotal), 4),
            ],
            'work_orders' => $workOrders->all(),
            'substitutes' => $substitutes,
        ];
    }

    /**
     * @return array{date: string, next_date: string, materials: list<array<string, mixed>>}
     */
    public function board(): array
    {
        $today = $this->schedule->plantToday();
        $tomorrow = Carbon::parse($today)->addDay()->toDateString();

        $day = $this->requirementsFor($today);
        $nextDay = $this->requirementsFor($tomorrow);

        $keys = $day->keys()->merge($nextDay->keys())->unique()->values();

        $materials = $keys
            ->map(function (string $key) use ($day, $nextDay) {
                $current = $day->get($key);
                $next = $nextDay->get($key);
                $base = $current ?? $next;

                return [
                    'part_id' => $base['part_id'],
                    'uom' => $base['uom'],
                    'day_qty' => round((float) ($current['required_qty'] ?? 0), 4),
                    'day_issued_qty' => round((float) ($current['issued_qty'] ?? 0), 4),
                    'day_wo_count' => (int) ($current['wo_count'] ?? 0),
                    'next_day_qty' => round((float) ($next['required_qty'] ?? 0), 4),
                    'next_day_issued_qty' => round((float) ($next['issued_qty'] ?? 0), 4),
                    'next_day_wo_count' => (int) ($next['wo_count'] ?? 0),
                ];
            })
            ->filter(fn (array $row) => $this->hasRemaining($row))
            ->values();

        $parts = Part::query()
            ->whereIn('id', $materials->pluck('part_id')->unique()->all())
            ->get(['id', 'part_number', 'part_name', 'model', 'size'])
            ->keyBy('id');

        return [
            'date' => $today,
            'next_date' => $tomorrow,
            'materials' => $materials
                ->map(function (array $row) use ($parts) {
                    $part = $parts[$row['part_id']] ?? null;
                    $row['part_number'] = $part?->part_number;
                    $row['part_name'] = $part?->part_name;
                    $row['model'] = $part?->model;
                    $row['size'] = $part?->size;
                    $row['day_remaining'] = round(
                        max(0.0, $row['day_qty'] - $row['day_issued_qty']),
                        4,
                    );
                    $row['next_day_remaining'] = round(
                        max(0.0, $row['next_day_qty'] - $row['next_day_issued_qty']),
                        4,
                    );

                    return $row;
                })
                ->sortBy([['part_number', 'asc']])
                ->values()
                ->all(),
        ];
    }

    /**
     * Kebutuhan material pada satu tanggal, dikunci per (material, satuan).
     *
     * Qty kebutuhan = `qty_required × (planned_qty ÷ qty WO)` dijumlahkan atas
     * seluruh WO yang dijadwalkan tanggal itu. Rasio ini menjaga satuan tetap
     * benar: kebutuhan KGM tetap KGM, kebutuhan PCS tetap PCS.
     *
     * @return Collection<string, array{part_id:int, uom:string|null, required_qty:float, issued_qty:float, wo_count:int}>
     */
    private function requirementsFor(string $date): Collection
    {
        $required = DB::table('work_order_items as woi')
            ->join('work_orders as wo', 'wo.id', '=', 'woi.work_order_id')
            ->joinSub(
                $this->schedule->scheduledQuantities($date),
                'schedule',
                'schedule.work_order_id',
                '=',
                'wo.id',
            )
            ->whereNotNull('woi.child_part_id')
            ->where('wo.qty', '>', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('work_order_items as sibling')
                ->whereColumn('sibling.work_order_id', 'woi.work_order_id')
                ->whereColumn('sibling.parent_part_id', 'woi.child_part_id')
                ->whereRaw("UPPER(COALESCE(sibling.source, '')) <> 'SUBCON'"))
            ->groupBy('woi.child_part_id', 'woi.uom_rm')
            ->selectRaw('woi.child_part_id AS part_id')
            ->selectRaw('woi.uom_rm AS uom')
            ->selectRaw('SUM(woi.qty_required * (schedule.planned_qty / wo.qty)) AS required_qty')
            ->selectRaw('COUNT(DISTINCT wo.id) AS wo_count')
            ->get();

        $issued = DB::table('material_issue_items as mii')
            ->join('material_issues as mi', 'mi.id', '=', 'mii.material_issue_id')
            ->join('work_order_items as woi', 'woi.id', '=', 'mii.work_order_item_id')
            ->where('mi.status', 'posted')
            ->where('mi.issue_date', $date)
            ->whereNotNull('woi.child_part_id')
            ->groupBy('woi.child_part_id', 'mii.uom')
            ->selectRaw('woi.child_part_id AS part_id')
            ->selectRaw('mii.uom AS uom')
            ->selectRaw('SUM(mii.qty) AS issued_qty')
            ->get();

        $rows = [];

        foreach ($required as $row) {
            $key = $this->key((int) $row->part_id, UomCatalog::normalize((string) $row->uom));
            $rows[$key] ??= $this->emptyRow((int) $row->part_id, UomCatalog::normalize((string) $row->uom));
            $rows[$key]['required_qty'] += (float) $row->required_qty;
            $rows[$key]['wo_count'] += (int) $row->wo_count;
        }

        foreach ($issued as $row) {
            $key = $this->key((int) $row->part_id, UomCatalog::normalize((string) $row->uom));
            $rows[$key] ??= $this->emptyRow((int) $row->part_id, UomCatalog::normalize((string) $row->uom));
            $rows[$key]['issued_qty'] += (float) $row->issued_qty;
        }

        return collect($rows);
    }

    private function key(int $partId, ?string $uom): string
    {
        return $partId.'|'.($uom ?? '');
    }

    /** @return array{part_id:int, uom:string|null, required_qty:float, issued_qty:float, wo_count:int} */
    private function emptyRow(int $partId, ?string $uom): array
    {
        return ['part_id' => $partId, 'uom' => $uom, 'required_qty' => 0.0, 'issued_qty' => 0.0, 'wo_count' => 0];
    }

    /**
     * Baris dipertahankan bila masih ada sisa pada salah satu tanggal.
     *
     * @param  array<string, mixed>  $row
     */
    private function hasRemaining(array $row): bool
    {
        $dayRemaining = (float) $row['day_qty'] - (float) $row['day_issued_qty'];
        $nextRemaining = (float) $row['next_day_qty'] - (float) $row['next_day_issued_qty'];

        return $dayRemaining > 1e-9 || $nextRemaining > 1e-9;
    }
}
