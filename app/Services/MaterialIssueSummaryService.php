<?php

namespace App\Services;

use App\Models\MaterialIssueItem;
use App\Models\WorkOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan Issue Out **berbasis Work Order**, bukan per slip.
 *
 * Satu WO bisa punya banyak dokumen issue (release per material, issue susulan
 * OPP, dsb). Halaman monitoring ingin satu baris per WO dengan:
 *  - Item      : akumulasi part number yang keluar
 *  - Status    : qty issued / qty required per satuan
 *  - Last date : tanggal slip terakhir WO tersebut
 *
 * Detail per slip (Slip No, tag, invoice, supplier, qty, uom) dipakai modal.
 */
final class MaterialIssueSummaryService
{
    /**
     * Satu baris per WO.
     *
     * @return list<array<string, mixed>>
     */
    public function byWorkOrder(?string $dateFrom = null, ?string $dateTo = null, ?string $search = null): array
    {
        $issues = DB::table('material_issues')
            ->where('material_issues.status', 'posted')
            ->when($dateFrom, fn ($q, $d) => $q->whereDate('material_issues.issue_date', '>=', $d))
            ->when($dateTo, fn ($q, $d) => $q->whereDate('material_issues.issue_date', '<=', $d))
            ->when($search, function ($q, $term) {
                $like = '%'.$term.'%';
                $q->where(function ($inner) use ($like) {
                    // Gunakan subquery eksplisit: query builder ini mengagregasi
                    // per work_order_id, jadi `whereHas` tidak bisa dipakai.
                    $inner->where('issue_no', 'ilike', $like)
                        ->orWhereIn('work_order_id', DB::table('work_orders')
                            ->where('wo_no', 'ilike', $like)
                            ->select('id'));
                });
            })
            ->selectRaw('work_order_id, COUNT(*) AS slip_count')
            ->selectRaw('MIN(issue_date) AS first_issued_date')
            ->selectRaw('MAX(issue_date) AS last_issued_date')
            ->groupBy('work_order_id')
            ->get();

        if ($issues->isEmpty()) {
            return [];
        }

        $workOrderIds = $issues->pluck('work_order_id')->all();

        $workOrders = DB::table('work_orders')
            ->leftJoin('parts', 'parts.id', '=', 'work_orders.part_id')
            ->whereIn('work_orders.id', $workOrderIds)
            ->get([
                'work_orders.id',
                'work_orders.wo_no',
                'work_orders.qty',
                'work_orders.status',
                'parts.part_number',
                'parts.part_name',
            ])
            ->keyBy('id');

        $issuedByWo = $this->issuedByWorkOrder($workOrderIds, $dateFrom, $dateTo);
        $requiredByWo = $this->requiredByWorkOrder($workOrderIds);
        $materialsByWo = $this->materialsByWorkOrder($workOrderIds, $dateFrom, $dateTo);

        $rows = [];

        foreach ($issues as $issue) {
            $woId = (int) $issue->work_order_id;
            $wo = $workOrders[$woId] ?? null;
            if ($wo === null) {
                continue;
            }

            $issued = $issuedByWo[$woId] ?? collect();
            $required = $requiredByWo[$woId] ?? collect();
            $uoms = $issued->pluck('uom')->merge($required->pluck('uom'))->filter()->unique()->values();

            $statusByUom = $uoms->map(function (string $uom) use ($issued, $required): array {
                return [
                    'uom' => $uom,
                    'issued_qty' => round((float) ($issued->firstWhere('uom', $uom)->qty ?? 0), 4),
                    'required_qty' => round((float) ($required->firstWhere('uom', $uom)->qty ?? 0), 4),
                ];
            })->values()->all();

            $rows[] = [
                'work_order_id' => $woId,
                'wo_no' => $wo->wo_no,
                'wo_status' => $wo->status,
                'fg_part_number' => $wo->part_number,
                'fg_part_name' => $wo->part_name,
                'slip_count' => (int) $issue->slip_count,
                'first_issued_date' => $issue->first_issued_date,
                'last_issued_date' => $issue->last_issued_date,
                'materials' => $materialsByWo[$woId] ?? [],
                'status_by_uom' => $statusByUom,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp((string) $b['last_issued_date'], (string) $a['last_issued_date']));

        return $rows;
    }

    /**
     * Satu baris ringkasan untuk satu WO (dipakai realtime monitoring agar
     * event issue hanya menyegarkan WO terkait, bukan seluruh tabel).
     *
     * @return array<string, mixed>|null
     */
    public function rowForWorkOrder(int $workOrderId, ?string $dateFrom = null, ?string $dateTo = null): ?array
    {
        $rows = $this->byWorkOrder($dateFrom, $dateTo);

        foreach ($rows as $row) {
            if ($row['work_order_id'] === $workOrderId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Detail per slip untuk modal satu WO.
     *
     * @return list<array<string, mixed>>
     */
    public function detailsForWorkOrder(int $workOrderId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return MaterialIssueItem::query()
            ->join('material_issues', 'material_issues.id', '=', 'material_issue_items.material_issue_id')
            ->leftJoin('parts', 'parts.id', '=', 'material_issue_items.part_id')
            ->where('material_issues.work_order_id', $workOrderId)
            ->where('material_issues.status', 'posted')
            ->when($dateFrom, fn ($q, $d) => $q->whereDate('material_issues.issue_date', '>=', $d))
            ->when($dateTo, fn ($q, $d) => $q->whereDate('material_issues.issue_date', '<=', $d))
            ->orderBy('material_issues.issue_date')
            ->orderBy('material_issues.id')
            ->orderBy('material_issue_items.id')
            ->get([
                'material_issues.issue_no',
                'material_issues.issue_date',
                'material_issue_items.part_id',
                'material_issue_items.tag',
                'material_issue_items.qty',
                'material_issue_items.uom',
                'material_issue_items.invoice',
                'material_issue_items.supplier',
                'parts.part_number',
                'parts.part_name',
            ])
            ->map(fn ($row): array => [
                'slip_no' => $row->issue_no,
                'issue_date' => $row->issue_date,
                'part_id' => (int) $row->part_id,
                'part_number' => $row->part_number,
                'part_name' => $row->part_name,
                'tag' => $row->tag,
                'qty' => round((float) $row->qty, 4),
                'uom' => $row->uom,
                'invoice' => $row->invoice,
                'supplier' => $row->supplier,
            ])
            ->values()
            ->all();
    }

    /**
     * Qty ter-issue per (WO, uom) dalam rentang tanggal.
     *
     * @param  list<int>  $workOrderIds
     * @return array<int, Collection<int, object>>
     */
    private function issuedByWorkOrder(array $workOrderIds, ?string $dateFrom, ?string $dateTo): array
    {
        return DB::table('material_issue_items')
            ->join('material_issues', 'material_issues.id', '=', 'material_issue_items.material_issue_id')
            ->whereIn('material_issues.work_order_id', $workOrderIds)
            ->where('material_issues.status', 'posted')
            ->when($dateFrom, fn ($q, $d) => $q->whereDate('material_issues.issue_date', '>=', $d))
            ->when($dateTo, fn ($q, $d) => $q->whereDate('material_issues.issue_date', '<=', $d))
            ->groupBy('material_issues.work_order_id', 'material_issue_items.uom')
            ->selectRaw('material_issues.work_order_id, material_issue_items.uom, SUM(material_issue_items.qty) AS qty')
            ->get()
            ->groupBy('work_order_id')
            ->map(fn (Collection $rows): Collection => $rows->values())
            ->all();
    }

    /**
     * Qty dibutuhkan per (WO, uom) dari snapshot item WO.
     *
     * @param  list<int>  $workOrderIds
     * @return array<int, Collection<int, object>>
     */
    private function requiredByWorkOrder(array $workOrderIds): array
    {
        return WorkOrderItem::query()
            ->whereIn('work_order_id', $workOrderIds)
            ->groupBy('work_order_id', 'uom_rm')
            ->selectRaw('work_order_id, uom_rm AS uom, SUM(qty_required) AS qty')
            ->get()
            ->groupBy('work_order_id')
            ->map(fn (Collection $rows): Collection => $rows->values())
            ->all();
    }

    /**
     * Akumulasi material (part) per WO untuk kolom "Item".
     *
     * @param  list<int>  $workOrderIds
     * @return array<int, list<array{part_id:int, part_number:string|null, part_name:string|null, qty:float, uom:string|null}>>
     */
    private function materialsByWorkOrder(array $workOrderIds, ?string $dateFrom, ?string $dateTo): array
    {
        return DB::table('material_issue_items')
            ->join('material_issues', 'material_issues.id', '=', 'material_issue_items.material_issue_id')
            ->leftJoin('parts', 'parts.id', '=', 'material_issue_items.part_id')
            ->whereIn('material_issues.work_order_id', $workOrderIds)
            ->where('material_issues.status', 'posted')
            ->when($dateFrom, fn ($q, $d) => $q->whereDate('material_issues.issue_date', '>=', $d))
            ->when($dateTo, fn ($q, $d) => $q->whereDate('material_issues.issue_date', '<=', $d))
            ->groupBy('material_issues.work_order_id', 'material_issue_items.part_id', 'material_issue_items.uom', 'parts.part_number', 'parts.part_name')
            ->selectRaw('material_issues.work_order_id, material_issue_items.part_id, material_issue_items.uom, parts.part_number, parts.part_name, SUM(material_issue_items.qty) AS qty')
            ->get()
            ->groupBy('work_order_id')
            ->map(fn (Collection $rows): array => $rows->map(fn ($row): array => [
                'part_id' => (int) $row->part_id,
                'part_number' => $row->part_number,
                'part_name' => $row->part_name,
                'qty' => round((float) $row->qty, 4),
                'uom' => $row->uom,
            ])->values()->all())
            ->all();
    }
}
