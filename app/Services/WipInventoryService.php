<?php

namespace App\Services;

use App\Models\Part;
use App\Models\ProductionMaterialReceipt;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kartu stok material per mesin untuk halaman WIP Inventory.
 *
 * Dua model atribusi:
 * - Raw Material (input): baris milik mesin yang menerima transfer material
 *   dan memakainya. Receive = transfer APK, Issue = konsumsi BOM di mesin itu.
 * - WIP & FG (output): baris milik mesin yang MEMPRODUKSI barang tersebut.
 *   Receive = output Production Result, Issue = pemakaian WIP di proses
 *   berikutnya yang dilacak kembali ke mesin pembuatnya lewat asal stok.
 *
 * Stok Awal = seluruh receive sebelum periode − seluruh issue sebelum periode,
 * sehingga saldo menyambung antar periode.
 */
final class WipInventoryService
{
    public const CATEGORY_WIP_FG = 'wip_fg';

    public const CATEGORY_MATERIAL = 'material';

    /** Stok awal: seluruh penerimaan & konsumsi sebelum tanggal filter. */
    public function beginningStock(int $machineId, int $partId, string $date): float
    {
        $received = (float) ProductionMaterialReceipt::query()
            ->where('machine_id', $machineId)
            ->where('part_id', $partId)
            ->where('transfer_status', 'transferred')
            ->whereDate('received_at', '<', $date)
            ->sum('qty');

        $issued = $this->issuedQuery($machineId, $partId)
            ->whereDate('work_order_consumptions.created_at', '<', $date)
            ->sum('work_order_consumptions.qty');

        return round($received - (float) $issued, 4);
    }

    /** Penerimaan pada tanggal tersebut (dari scan APK). */
    public function receivedQty(int $machineId, int $partId, string $date): float
    {
        return round((float) ProductionMaterialReceipt::query()
            ->where('machine_id', $machineId)
            ->where('part_id', $partId)
            ->where('transfer_status', 'transferred')
            ->whereDate('received_at', $date)
            ->sum('qty'), 4);
    }

    /** Konsumsi BOM dari laporan hasil produksi pada tanggal tersebut. */
    public function issuedQty(int $machineId, int $partId, string $date): float
    {
        return round((float) $this->issuedQuery($machineId, $partId)
            ->whereDate('work_order_consumptions.created_at', $date)
            ->sum('work_order_consumptions.qty'), 4);
    }

    public function endingStock(int $machineId, int $partId, string $date): float
    {
        return round(
            $this->beginningStock($machineId, $partId, $date)
            + $this->receivedQty($machineId, $partId, $date)
            - $this->issuedQty($machineId, $partId, $date),
            4,
        );
    }

    /**
     * Semua baris kartu stok pada satu tanggal (kompatibilitas pemanggil lama).
     *
     * @return list<array<string, mixed>>
     */
    public function forDate(string $date, ?int $machineId = null): array
    {
        return $this->forRange($date, $date, $machineId);
    }

    /**
     * Kartu stok untuk satu rentang tanggal, dikelompokkan per mesin + part + UOM.
     *
     * @param  string|null  $category  self::CATEGORY_WIP_FG, self::CATEGORY_MATERIAL, atau null untuk semua
     * @return list<array<string, mixed>>
     */
    public function forRange(string $from, string $to, ?int $machineId = null, ?string $category = null): array
    {
        // WIP & FG memakai model output (mesin pembuat); raw material dan
        // pemanggil lama memakai model input (mesin penerima/pemakai).
        $buckets = $category === self::CATEGORY_WIP_FG
            ? $this->outputBuckets($machineId, $from, $to)
            : $this->inputBuckets($machineId, $from, $to);

        return $this->buildRows($buckets, $machineId, $category);
    }

    /** @return array<string, array<string, mixed>> */
    private function inputBuckets(?int $machineId, string $from, string $to): array
    {
        return $this->mergeBuckets(
            $this->receivedAggregate($machineId, $from, $to),
            $this->issuedAggregate($machineId, $from, $to),
            $this->beginningAggregate($machineId, $from),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function outputBuckets(?int $machineId, string $from, string $to): array
    {
        return $this->mergeBuckets(
            $this->outputReceivedAggregate($machineId, $from, $to),
            $this->outputIssuedAggregate($machineId, $from, $to),
            $this->outputBeginningAggregate($machineId, $from),
        );
    }

    /**
     * Gabungkan receive, issue, dan saldo awal menjadi satu bucket
     * machine|part|uom.
     *
     * @param  array<string, array<string, mixed>>  $received
     * @param  array<string, array<string, mixed>>  $issued
     * @param  array<string, float>  $beginning
     * @return array<string, array<string, mixed>>
     */
    private function mergeBuckets(array $received, array $issued, array $beginning): array
    {
        $buckets = [];

        foreach ($received as $key => $row) {
            $buckets[$key] = $row + [
                'beginning_qty' => 0.0,
                'issued_qty' => 0.0,
                'qty' => 0.0,
            ];
        }

        foreach ($issued as $key => $row) {
            if ($row['machine_id'] === null) {
                continue;
            }

            $buckets[$key] ??= [
                'machine_id' => $row['machine_id'],
                'part_id' => $row['part_id'],
                'uom' => $row['uom'],
                'invoice' => null,
                'supplier' => null,
                'qty' => 0.0,
                'beginning_qty' => 0.0,
                'issued_qty' => 0.0,
            ];

            // Nota bisa datang dari konsumsi walau tidak ada penerimaan di periode
            // ini (material masuk sebelumnya, dipakai sekarang).
            if ($buckets[$key]['invoice'] === null && $row['invoice'] !== null) {
                $buckets[$key]['invoice'] = $row['invoice'];
                $buckets[$key]['supplier'] = $row['supplier'];
            }

            $buckets[$key]['issued_qty'] += $row['qty'];
        }

        foreach ($beginning as $key => $qty) {
            if (abs($qty) < 1e-9) {
                continue;
            }

            if (! isset($buckets[$key])) {
                [$mid, $pid, $uom] = $this->splitKey($key);
                $buckets[$key] = [
                    'machine_id' => $mid,
                    'part_id' => $pid,
                    'uom' => $uom,
                    'invoice' => null,
                    'supplier' => null,
                    'qty' => 0.0,
                    'issued_qty' => 0.0,
                ];
            }

            $buckets[$key]['beginning_qty'] = $qty;
        }

        return $buckets;
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     * @return list<array<string, mixed>>
     */
    private function buildRows(array $buckets, ?int $machineId, ?string $category): array
    {
        if ($buckets === []) {
            return [];
        }

        $partIds = array_values(array_unique(array_column($buckets, 'part_id')));
        $machines = $this->machines($machineId);
        $parts = Part::query()
            ->whereIn('id', $partIds)
            ->with('partType:id,code')
            ->get(['id', 'part_number', 'part_name', 'model', 'size', 'part_type_id'])
            ->keyBy('id');

        $rows = [];

        foreach ($buckets as $bucket) {
            $pid = (int) $bucket['part_id'];
            $part = $parts[$pid] ?? null;
            $partType = strtoupper((string) $part?->partType?->code) ?: null;

            if (! $this->matchesCategory($partType, $category)) {
                continue;
            }

            $beginningQty = round((float) $bucket['beginning_qty'], 4);
            $receivedQty = round((float) $bucket['qty'], 4);
            $issuedQty = round((float) $bucket['issued_qty'], 4);

            $rows[] = [
                'machine_id' => (int) $bucket['machine_id'],
                'machine_name' => $machines[(int) $bucket['machine_id']]['machine_name'] ?? '—',
                'machine_code' => $machines[(int) $bucket['machine_id']]['machine_code'] ?? null,
                'part_id' => $pid,
                'part_number' => $part?->part_number,
                'part_name' => $part?->part_name,
                'model' => $part?->model,
                'size' => $part?->size,
                'part_type' => $partType,
                'invoice' => $bucket['invoice'],
                'supplier' => $bucket['supplier'],
                'uom' => $bucket['uom'],
                'beginning_qty' => $beginningQty,
                'received_qty' => $receivedQty,
                'issued_qty' => $issuedQty,
                'ending_qty' => round($beginningQty + $receivedQty - $issuedQty, 4),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['machine_name'], $a['part_number'], $a['uom']]
            <=> [$b['machine_name'], $b['part_number'], $b['uom']]);

        return $rows;
    }

    /**
     * Rincian per nota untuk satu kombinasi mesin + part pada satu rentang.
     *
     * @return array{
     *     machine: array{id: int, name: string, code: string|null},
     *     part: array{id: int, number: string|null, name: string|null, model: string|null, size: string|null, type: string|null},
     *     uom: string|null,
     *     from: string,
     *     to: string,
     *     beginning: list<array<string, mixed>>,
     *     received: list<array<string, mixed>>,
     *     issued: list<array<string, mixed>>,
     *     totals: array{beginning: float, received: float, issued: float, ending: float},
     * }
     */
    public function detail(int $machineId, int $partId, string $from, ?string $to = null): array
    {
        $to ??= $from;

        $machine = DB::table('machines')->where('id', $machineId)->first(['id', 'machine_name', 'machine_code']);
        $part = Part::query()
            ->with('partType:id,code')
            ->find($partId, ['id', 'part_number', 'part_name', 'model', 'size', 'uom_id', 'part_type_id']);
        $partType = strtoupper((string) $part?->partType?->code) ?: null;

        // WIP & FG memakai model output (mesin pembuat); material memakai model input.
        if (in_array($partType, ['WIP', 'FG'], true)) {
            $beginningRows = $this->detailOutputBeginning($machineId, $partId, $from);
            $receivedRows = $this->detailOutputReceived($machineId, $partId, $from, $to);
            $issuedRows = $this->detailOutputIssued($machineId, $partId, $from, $to);
        } else {
            $beginningRows = $this->detailBeginning($machineId, $partId, $from);
            $receivedRows = $this->detailReceived($machineId, $partId, $from, $to);
            $issuedRows = $this->detailIssued($machineId, $partId, $from, $to);
        }

        $sum = fn (array $rows): float => round(array_sum(array_column($rows, 'qty')), 4);

        return [
            'machine' => [
                'id' => (int) $machineId,
                'name' => $machine->machine_name ?? '—',
                'code' => $machine->machine_code ?? null,
            ],
            'part' => [
                'id' => (int) $partId,
                'number' => $part?->part_number,
                'name' => $part?->part_name,
                'model' => $part?->model,
                'size' => $part?->size,
                'type' => $partType,
            ],
            'uom' => $issuedRows[0]['uom'] ?? $receivedRows[0]['uom'] ?? $beginningRows[0]['uom'] ?? null,
            'from' => $from,
            'to' => $to,
            'beginning' => $beginningRows,
            'received' => $receivedRows,
            'issued' => $issuedRows,
            'totals' => [
                'beginning' => $sum($beginningRows),
                'received' => $sum($receivedRows),
                'issued' => $sum($issuedRows),
                'ending' => round($sum($beginningRows) + $sum($receivedRows) - $sum($issuedRows), 4),
            ],
        ];
    }

    /**
     * Penerimaan model input: transfer material ke mesin.
     *
     * @return array<string, array{machine_id: int, part_id: int, uom: string|null, qty: float, invoice: string|null, supplier: string|null}>
     */
    private function receivedAggregate(?int $machineId, ?string $from, ?string $to, ?string $before = null): array
    {
        return $this->materialReceivedQuery($machineId, $from, $to, $before)
            ->groupBy('r.machine_id', 'r.part_id', 'r.uom')
            ->selectRaw('r.machine_id, r.part_id, r.uom, SUM(r.qty) AS qty')
            ->selectRaw('MAX(COALESCE(mii.invoice, r.invoice)) AS invoice')
            ->selectRaw('MAX(COALESCE(mii.supplier, r.supplier)) AS supplier')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $this->bucketKey($row->machine_id, $row->part_id, $row->uom) => [
                    'machine_id' => (int) $row->machine_id,
                    'part_id' => (int) $row->part_id,
                    'uom' => $row->uom,
                    'qty' => (float) $row->qty,
                    'invoice' => $row->invoice,
                    'supplier' => $row->supplier,
                ],
            ])
            ->all();
    }

    /**
     * Output produksi (WIP & FG) per mesin PEMBUAT pada periode.
     *
     * @return array<string, array{machine_id: int, part_id: int, uom: string|null, qty: float, invoice: string|null, supplier: string|null}>
     */
    private function outputReceivedAggregate(?int $machineId, ?string $from, ?string $to, ?string $before = null): array
    {
        return $this->outputResultQuery($machineId, $from, $to, $before)
            ->leftJoinSub($this->mainMaterialInvoiceSub(), 'src', 'src.production_result_id', '=', 'pr.id')
            ->groupBy('pr.machine_id', 'pr.parent_part_id', 'pr.uom')
            ->selectRaw('pr.machine_id, pr.parent_part_id AS part_id, pr.uom, SUM(pr.qty_good) AS qty')
            ->selectRaw('MAX(src.invoice) AS invoice')
            ->selectRaw('MAX(src.supplier) AS supplier')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $this->bucketKey($row->machine_id, $row->part_id, $row->uom) => [
                    'machine_id' => (int) $row->machine_id,
                    'part_id' => (int) $row->part_id,
                    'uom' => $row->uom,
                    'qty' => (float) $row->qty,
                    'invoice' => $row->invoice,
                    'supplier' => $row->supplier,
                ],
            ])
            ->all();
    }

    /**
     * Pemakaian WIP pada periode, diatribusikan ke mesin PEMBUAT-nya melalui
     * asal stok yang dikonsumsi (part_stock -> production result -> machine).
     *
     * @return array<string, array{machine_id: int|null, part_id: int, uom: string|null, qty: float, invoice: string|null, supplier: string|null}>
     */
    private function outputIssuedAggregate(?int $machineId, ?string $from, ?string $to, ?string $before = null): array
    {
        $query = $this->outputIssueBaseQuery($machineId);

        if ($before !== null) {
            $query->whereDate('woc.created_at', '<', $before);
        } else {
            $query->whereDate('woc.created_at', '>=', (string) $from)
                ->whereDate('woc.created_at', '<=', (string) $to);
        }

        $rows = $query
            ->groupByRaw('COALESCE(pr_out.machine_id, woi.machine_id), woc.part_id, woc.uom')
            ->selectRaw('COALESCE(pr_out.machine_id, woi.machine_id) AS machine_id, woc.part_id, woc.uom, SUM(woc.qty) AS qty')
            ->selectRaw("STRING_AGG(DISTINCT woc.invoice_no, ' | ') AS invoice")
            ->selectRaw("STRING_AGG(DISTINCT woc.supplier_name, ' | ') AS supplier")
            ->get()
            ->mapWithKeys(fn ($row) => [
                $this->bucketKey($row->machine_id, $row->part_id, $row->uom) => [
                    'machine_id' => $row->machine_id !== null ? (int) $row->machine_id : null,
                    'part_id' => (int) $row->part_id,
                    'uom' => $row->uom,
                    'qty' => (float) $row->qty,
                    'invoice' => $row->invoice,
                    'supplier' => $row->supplier,
                ],
            ])
            ->all();

        // Barang jadi keluar dari lokasi mesin saat dipindah ke area pengiriman.
        foreach ($this->fgDeliveryIssuedAggregate($machineId, $from, $to, $before) as $key => $row) {
            if (isset($rows[$key])) {
                $rows[$key]['qty'] += $row['qty'];
                $rows[$key]['invoice'] = $this->joinLabels($rows[$key]['invoice'], $row['invoice']);
                $rows[$key]['supplier'] = $this->joinLabels($rows[$key]['supplier'], $row['supplier']);
            } else {
                $rows[$key] = $row;
            }
        }

        return $rows;
    }

    /**
     * Pemakaian barang jadi: qty yang sudah dipindah ke area pengiriman,
     * diatribusikan ke mesin pembuat tag tersebut.
     *
     * @return array<string, array{machine_id: int|null, part_id: int, uom: string|null, qty: float, invoice: string|null, supplier: string|null}>
     */
    private function fgDeliveryIssuedAggregate(?int $machineId, ?string $from, ?string $to, ?string $before = null): array
    {
        $query = DB::table('delivery_items as di')
            ->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
            ->leftJoin('production_results as pr_out', 'pr_out.output_part_stock_id', '=', 'di.part_stock_id')
            ->leftJoin('machines as m', fn ($join) => $join
                ->on(DB::raw('LOWER(m.machine_code)'), '=', DB::raw('LOWER(di.origin_location_code)')))
            ->join('parts as p', 'p.id', '=', 'di.part_id')
            ->join('part_types as pt', 'pt.id', '=', 'p.part_type_id')
            ->whereRaw('UPPER(pt.code) = ?', ['FG'])
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.picked_at')
            ->whereIn('d.status', ['picked', 'shipped'])
            ->when($machineId !== null, fn ($q) => $q->whereRaw(
                'COALESCE(pr_out.machine_id, m.id) = ?',
                [$machineId],
            ));

        if ($before !== null) {
            $query->whereDate('d.picked_at', '<', $before);
        } else {
            $query->whereDate('d.picked_at', '>=', (string) $from)
                ->whereDate('d.picked_at', '<=', (string) $to);
        }

        return $query
            ->groupByRaw('COALESCE(pr_out.machine_id, m.id), di.part_id, di.qty_unit')
            ->selectRaw('COALESCE(pr_out.machine_id, m.id) AS machine_id, di.part_id, di.qty_unit AS uom, SUM(di.qty) AS qty')
            ->selectRaw('STRING_AGG(DISTINCT d.delivery_no, \' | \') AS invoice')
            ->selectRaw('STRING_AGG(DISTINCT c.customer_name, \' | \') AS supplier')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $this->bucketKey($row->machine_id, $row->part_id, $row->uom) => [
                    'machine_id' => $row->machine_id !== null ? (int) $row->machine_id : null,
                    'part_id' => (int) $row->part_id,
                    'uom' => $row->uom,
                    'qty' => (float) $row->qty,
                    'invoice' => $row->invoice,
                    'supplier' => $row->supplier,
                ],
            ])
            ->all();
    }

    /** Gabungkan label nota/supplier dari dua sumber tanpa duplikasi. */
    private function joinLabels(?string $a, ?string $b): ?string
    {
        $parts = array_filter(array_map('trim', explode('|', (string) $a.'|'.(string) $b)));

        return $parts === [] ? null : implode(' | ', array_values(array_unique($parts)));
    }

    /**
     * Saldo awal model output: output sebelum periode − pemakaian WIP sebelum periode.
     *
     * @return array<string, float>
     */
    private function outputBeginningAggregate(?int $machineId, string $before): array
    {
        $map = [];

        foreach ($this->outputReceivedAggregate($machineId, null, null, $before) as $key => $row) {
            $map[$key] = ($map[$key] ?? 0.0) + $row['qty'];
        }

        foreach ($this->outputIssuedAggregate($machineId, null, null, $before) as $key => $row) {
            $map[$key] = ($map[$key] ?? 0.0) - $row['qty'];
        }

        return array_map(fn ($qty): float => round($qty, 4), $map);
    }

    /** Query output produksi WIP/FG yang belum di-reverse. */
    private function outputResultQuery(?int $machineId, ?string $from, ?string $to, ?string $before = null): Builder
    {
        $query = DB::table('production_results as pr')
            ->join('parts as p', 'p.id', '=', 'pr.parent_part_id')
            ->join('part_types as pt', 'pt.id', '=', 'p.part_type_id')
            ->whereRaw("UPPER(pt.code) IN ('WIP', 'FG')")
            ->whereNull('pr.deleted_at')
            ->whereNull('pr.reversed_by')
            ->whereNotNull('pr.machine_id')
            ->when($machineId !== null, fn ($q) => $q->where('pr.machine_id', $machineId));

        if ($before !== null) {
            $query->whereDate('pr.result_date', '<', $before);
        } else {
            $query->whereDate('pr.result_date', '>=', (string) $from)
                ->whereDate('pr.result_date', '<=', (string) $to);
        }

        return $query;
    }

    /**
     * Subquery nota material utama per production result (tanpa material
     * penunjang seperti OPP tape).
     */
    private function mainMaterialInvoiceSub(): Builder
    {
        return DB::table('work_order_consumptions as woc')
            ->leftJoin('work_order_items as woi', 'woi.id', '=', 'woc.work_order_item_id')
            ->leftJoin('parts as cp', 'cp.id', '=', 'woi.child_part_id')
            ->whereNull('woc.deleted_at')
            ->whereNull('woc.reversed_by')
            ->whereNotNull('woc.invoice_no')
            ->whereRaw("NOT (UPPER(COALESCE(cp.part_number, '')) LIKE 'OPT%' OR UPPER(COALESCE(cp.part_number, '')) LIKE 'OPO%' OR UPPER(COALESCE(woi.child_part_name, '')) LIKE '%OPP %' OR UPPER(COALESCE(woi.child_part_name, '')) = 'OPP')")
            ->groupBy('woc.production_result_id')
            ->selectRaw('woc.production_result_id')
            ->selectRaw("STRING_AGG(DISTINCT woc.invoice_no, ' | ') AS invoice")
            ->selectRaw("STRING_AGG(DISTINCT woc.supplier_name, ' | ') AS supplier");
    }

    /** Basis query konsumsi WIP dengan mesin pembuat hasil pelacakan stok. */
    private function outputIssueBaseQuery(?int $machineId = null): Builder
    {
        return DB::table('work_order_consumptions as woc')
            ->join('work_order_items as woi', 'woi.id', '=', 'woc.work_order_item_id')
            ->leftJoin('production_results as pr_out', 'pr_out.output_part_stock_id', '=', 'woc.part_stock_id')
            ->join('parts as p', 'p.id', '=', 'woc.part_id')
            ->join('part_types as pt', 'pt.id', '=', 'p.part_type_id')
            ->whereRaw("UPPER(pt.code) = 'WIP'")
            ->whereNull('woc.deleted_at')
            ->whereNull('woc.reversed_by')
            ->when($machineId !== null, fn ($q) => $q->whereRaw('COALESCE(pr_out.machine_id, woi.machine_id) = ?', [$machineId]));
    }

    /**
     * Pemakaian dalam periode (konsumsi BOM).
     *
     * @return array<string, array{machine_id: int|null, part_id: int, uom: string|null, qty: float, invoice: string|null, supplier: string|null}>
     */
    private function issuedAggregate(?int $machineId, ?string $from, ?string $to, ?string $before = null): array
    {
        $query = $this->issuedQuery($machineId);

        if ($before !== null) {
            $query->whereDate('work_order_consumptions.created_at', '<', $before);
        } else {
            $query->whereDate('work_order_consumptions.created_at', '>=', (string) $from)
                ->whereDate('work_order_consumptions.created_at', '<=', (string) $to);
        }

        return $query
            ->groupBy('woi.machine_id', 'work_order_consumptions.part_id', 'work_order_consumptions.uom')
            ->selectRaw('woi.machine_id, work_order_consumptions.part_id, work_order_consumptions.uom, SUM(work_order_consumptions.qty) AS qty')
            ->selectRaw("STRING_AGG(DISTINCT work_order_consumptions.invoice_no, ' | ') AS invoice")
            ->selectRaw("STRING_AGG(DISTINCT work_order_consumptions.supplier_name, ' | ') AS supplier")
            ->get()
            ->mapWithKeys(fn ($row) => [
                $this->bucketKey($row->machine_id, $row->part_id, $row->uom) => [
                    'machine_id' => $row->machine_id !== null ? (int) $row->machine_id : null,
                    'part_id' => (int) $row->part_id,
                    'uom' => $row->uom,
                    'qty' => (float) $row->qty,
                    'invoice' => $row->invoice,
                    'supplier' => $row->supplier,
                ],
            ])
            ->all();
    }

    /**
     * Saldo awal per mesin + part + UOM: penerimaan sebelum periode − pemakaian
     * sebelum periode.
     *
     * @return array<string, float>
     */
    private function beginningAggregate(?int $machineId, string $before): array
    {
        $map = [];

        foreach ($this->receivedAggregate($machineId, null, null, $before) as $key => $row) {
            $map[$key] = ($map[$key] ?? 0.0) + $row['qty'];
        }

        foreach ($this->issuedAggregate($machineId, null, null, $before) as $key => $row) {
            $map[$key] = ($map[$key] ?? 0.0) - $row['qty'];
        }

        return array_map(fn ($qty): float => round($qty, 4), $map);
    }

    /**
     * Stok awal per nota: penerimaan sebelum periode − pemakaian sebelum periode.
     *
     * @return list<array{invoice: string|null, supplier: string|null, uom: string|null, qty: float}>
     */
    private function detailBeginning(int $machineId, int $partId, string $before): array
    {
        $map = [];

        $material = $this->materialReceivedQuery($machineId, null, null, $before)
            ->where('r.part_id', $partId)
            ->groupBy('r.uom', 'r.invoice', 'r.supplier', 'mii.uom', 'mii.invoice', 'mii.supplier')
            ->selectRaw('COALESCE(mii.uom, r.uom) AS uom')
            ->selectRaw('COALESCE(mii.invoice, r.invoice) AS invoice')
            ->selectRaw('COALESCE(mii.supplier, r.supplier) AS supplier')
            ->selectRaw('SUM(r.qty) AS qty')
            ->get();

        foreach ($material as $row) {
            $this->addDetailRow($map, $row->invoice, $row->supplier, $row->uom, (float) $row->qty);
        }

        $issued = $this->issuedQuery($machineId, $partId)
            ->whereDate('work_order_consumptions.created_at', '<', $before)
            ->groupBy('work_order_consumptions.uom', 'work_order_consumptions.invoice_no', 'work_order_consumptions.supplier_name')
            ->selectRaw('work_order_consumptions.uom AS uom')
            ->selectRaw('work_order_consumptions.invoice_no AS invoice')
            ->selectRaw('work_order_consumptions.supplier_name AS supplier')
            ->selectRaw('SUM(work_order_consumptions.qty) AS qty')
            ->get();

        foreach ($issued as $row) {
            $this->addDetailRow($map, $row->invoice, $row->supplier, $row->uom, -1 * (float) $row->qty);
        }

        return $this->detailRows($map);
    }

    /**
     * @return list<array{invoice: string|null, supplier: string|null, uom: string|null, qty: float}>
     */
    private function detailReceived(int $machineId, int $partId, string $from, string $to): array
    {
        $map = [];

        $material = $this->materialReceivedQuery($machineId, $from, $to, null)
            ->where('r.part_id', $partId)
            ->groupBy('r.uom', 'r.invoice', 'r.supplier', 'mii.uom', 'mii.invoice', 'mii.supplier')
            ->selectRaw('COALESCE(mii.uom, r.uom) AS uom')
            ->selectRaw('COALESCE(mii.invoice, r.invoice) AS invoice')
            ->selectRaw('COALESCE(mii.supplier, r.supplier) AS supplier')
            ->selectRaw('SUM(r.qty) AS qty')
            ->get();

        foreach ($material as $row) {
            $this->addDetailRow($map, $row->invoice, $row->supplier, $row->uom, (float) $row->qty);
        }

        return $this->detailRows($map);
    }

    /**
     * @return list<array{invoice: string|null, supplier: string|null, uom: string|null, qty: float}>
     */
    private function detailIssued(int $machineId, int $partId, string $from, string $to): array
    {
        $map = [];

        $rows = $this->issuedQuery($machineId, $partId)
            ->whereDate('work_order_consumptions.created_at', '>=', $from)
            ->whereDate('work_order_consumptions.created_at', '<=', $to)
            ->groupBy('work_order_consumptions.uom', 'work_order_consumptions.invoice_no', 'work_order_consumptions.supplier_name')
            ->selectRaw('work_order_consumptions.uom AS uom')
            ->selectRaw('work_order_consumptions.invoice_no AS invoice')
            ->selectRaw('work_order_consumptions.supplier_name AS supplier')
            ->selectRaw('SUM(work_order_consumptions.qty) AS qty')
            ->get();

        foreach ($rows as $row) {
            $this->addDetailRow($map, $row->invoice, $row->supplier, $row->uom, (float) $row->qty);
        }

        return $this->detailRows($map);
    }

    /**
     * Output produksi per laporan pada periode (detail model output).
     *
     * @return list<array{invoice: string|null, supplier: string|null, uom: string|null, qty: float}>
     */
    private function detailOutputReceived(int $machineId, int $partId, string $from, string $to, ?string $before = null): array
    {
        $rows = $this->outputResultQuery(
            $machineId,
            $before === null ? $from : null,
            $before === null ? $to : null,
            $before,
        )
            ->where('pr.parent_part_id', $partId)
            ->leftJoinSub($this->mainMaterialInvoiceSub(), 'src', 'src.production_result_id', '=', 'pr.id')
            ->orderBy('pr.result_date')
            ->orderBy('pr.id')
            ->selectRaw('pr.uom AS uom, pr.qty_good AS qty, src.invoice AS invoice, src.supplier AS supplier')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $this->addDetailRow($map, $row->invoice, $row->supplier, $row->uom, (float) $row->qty);
        }

        return $this->detailRows($map);
    }

    /**
     * Pemakaian WIP pada periode, diatribusikan ke mesin pembuat.
     *
     * @return list<array{invoice: string|null, supplier: string|null, uom: string|null, qty: float}>
     */
    private function detailOutputIssued(int $machineId, int $partId, string $from, string $to): array
    {
        $map = [];

        foreach ($this->outputIssuedRows($machineId, $partId, $from, $to) as $row) {
            $this->addDetailRow($map, $row->invoice, $row->supplier, $row->uom, (float) $row->qty);
        }

        foreach ($this->detailFgDeliveryIssued($machineId, $partId, $from, $to) as $row) {
            $this->addDetailRow($map, $row->invoice, $row->supplier, $row->uom, (float) $row->qty);
        }

        return $this->detailRows($map);
    }

    /**
     * Barang jadi yang dipindah ke area pengiriman pada periode, per dokumen.
     *
     * @return Collection<int, object>
     */
    private function detailFgDeliveryIssued(int $machineId, int $partId, string $from, string $to, ?string $before = null)
    {
        $query = DB::table('delivery_items as di')
            ->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('production_results as pr_out', 'pr_out.output_part_stock_id', '=', 'di.part_stock_id')
            ->leftJoin('machines as m', fn ($join) => $join
                ->on(DB::raw('LOWER(m.machine_code)'), '=', DB::raw('LOWER(di.origin_location_code)')))
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.picked_at')
            ->whereIn('d.status', ['picked', 'shipped'])
            ->where('di.part_id', $partId)
            ->whereRaw('COALESCE(pr_out.machine_id, m.id) = ?', [$machineId]);

        if ($before !== null) {
            $query->whereDate('d.picked_at', '<', $before);
        } else {
            $query->whereDate('d.picked_at', '>=', $from)
                ->whereDate('d.picked_at', '<=', $to);
        }

        return $query
            ->groupBy('di.qty_unit', 'd.delivery_no', 'c.customer_name')
            ->selectRaw('di.qty_unit AS uom, d.delivery_no AS invoice, c.customer_name AS supplier, SUM(di.qty) AS qty')
            ->get();
    }

    /**
     * Saldo awal model output per nota.
     *
     * @return list<array{invoice: string|null, supplier: string|null, uom: string|null, qty: float}>
     */
    private function detailOutputBeginning(int $machineId, int $partId, string $before): array
    {
        $map = [];

        foreach ($this->detailOutputReceived($machineId, $partId, $before, $before, $before) as $row) {
            $this->addDetailRow($map, $row['invoice'], $row['supplier'], $row['uom'], $row['qty']);
        }

        foreach ($this->outputIssuedRows($machineId, $partId, null, null, $before) as $row) {
            $this->addDetailRow($map, $row->invoice, $row->supplier, $row->uom, -1 * (float) $row->qty);
        }

        foreach ($this->detailFgDeliveryIssued($machineId, $partId, $before, $before, $before) as $row) {
            $this->addDetailRow($map, $row->invoice, $row->supplier, $row->uom, -1 * (float) $row->qty);
        }

        return $this->detailRows($map);
    }

    /**
     * @return Collection<int, object>
     */
    private function outputIssuedRows(int $machineId, int $partId, ?string $from, ?string $to, ?string $before = null)
    {
        $query = $this->outputIssueBaseQuery($machineId)->where('woc.part_id', $partId);

        if ($before !== null) {
            $query->whereDate('woc.created_at', '<', $before);
        } else {
            $query->whereDate('woc.created_at', '>=', (string) $from)
                ->whereDate('woc.created_at', '<=', (string) $to);
        }

        return $query
            ->groupBy('woc.uom', 'woc.invoice_no', 'woc.supplier_name')
            ->selectRaw('woc.uom AS uom, woc.invoice_no AS invoice, woc.supplier_name AS supplier, SUM(woc.qty) AS qty')
            ->get();
    }

    /**
     * @param  array<string, array{invoice: string|null, supplier: string|null, uom: string|null, qty: float}>  $map
     */
    private function addDetailRow(array &$map, ?string $invoice, ?string $supplier, ?string $uom, float $qty): void
    {
        $key = ($invoice ?? '—').'|'.($supplier ?? '—').'|'.($uom ?? '—');

        $map[$key] ??= [
            'invoice' => $invoice,
            'supplier' => $supplier,
            'uom' => $uom,
            'qty' => 0.0,
        ];

        $map[$key]['qty'] = round($map[$key]['qty'] + $qty, 4);
    }

    /**
     * @param  array<string, array{invoice: string|null, supplier: string|null, uom: string|null, qty: float}>  $map
     * @return list<array{invoice: string|null, supplier: string|null, uom: string|null, qty: float}>
     */
    private function detailRows(array $map): array
    {
        return collect($map)
            ->filter(fn (array $row): bool => abs($row['qty']) > 1e-9)
            ->sortBy([['invoice', 'asc']])
            ->values()
            ->all();
    }

    private function materialReceivedQuery(?int $machineId, ?string $from, ?string $to, ?string $before = null): Builder
    {
        $query = DB::table('production_material_receipts as r')
            ->leftJoin('material_issue_items as mii', 'mii.id', '=', 'r.material_issue_item_id')
            ->where('r.transfer_status', 'transferred')
            ->when($machineId !== null, fn ($q) => $q->where('r.machine_id', $machineId));

        if ($before !== null) {
            $query->whereDate('r.received_at', '<', $before);
        } else {
            $query->whereDate('r.received_at', '>=', (string) $from)
                ->whereDate('r.received_at', '<=', (string) $to);
        }

        return $query;
    }

    private function matchesCategory(?string $partType, ?string $category): bool
    {
        if ($category === null) {
            return true;
        }

        $isWipOrFg = in_array($partType, ['WIP', 'FG'], true);

        return $category === self::CATEGORY_WIP_FG ? $isWipOrFg : ! $isWipOrFg;
    }

    private function bucketKey(int|string|null $machineId, int|string|null $partId, ?string $uom): string
    {
        return $machineId.'|'.$partId.'|'.($uom ?? '');
    }

    /** @return array{0: int, 1: int, 2: string|null} */
    private function splitKey(string $key): array
    {
        [$machineId, $partId, $uom] = explode('|', $key, 3);

        return [(int) $machineId, (int) $partId, $uom === '' ? null : $uom];
    }

    /** Basis query konsumsi BOM yang sudah terkait ke mesin item WO. */
    private function issuedQuery(?int $machineId = null, ?int $partId = null): Builder
    {
        return DB::table('work_order_consumptions')
            ->join('work_order_items as woi', 'woi.id', '=', 'work_order_consumptions.work_order_item_id')
            ->whereNotNull('woi.machine_id')
            ->when($machineId !== null, fn ($q) => $q->where('woi.machine_id', $machineId))
            ->when($partId !== null, fn ($q) => $q->where('work_order_consumptions.part_id', $partId))
            ->whereNull('work_order_consumptions.deleted_at')
            ->whereNull('work_order_consumptions.reversed_by');
    }

    /**
     * @return array<int, array{machine_name: string, machine_code: string|null}>
     */
    private function machines(?int $machineId): array
    {
        return DB::table('machines')
            ->when($machineId !== null, fn ($q) => $q->where('id', $machineId))
            ->get(['id', 'machine_name', 'machine_code'])
            ->mapWithKeys(fn ($m): array => [(int) $m->id => [
                'machine_name' => (string) $m->machine_name,
                'machine_code' => $m->machine_code,
            ]])
            ->all();
    }
}
