<?php

namespace App\Services;

use App\Models\Part;
use App\Models\ProductionMaterialReceipt;
use App\Models\WorkOrderConsumption;
use Illuminate\Support\Facades\DB;

/**
 * Kartu stok WIP per mesin untuk halaman WIP Inventory.
 *
 * Beginning Stock = saldo kumulatif (received − issued) sebelum tanggal filter,
 * sehingga angka berjalan menyambung antar hari, bukan reset harian.
 *
 * Issued Qty diambil dari konsumsi BOM saat laporan hasil produksi
 * (`work_order_consumptions`), bukan dari dokumen issue.
 */
final class WipInventoryService
{
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
     * Semua baris kartu stok WIP pada satu tanggal, dikelompokkan per mesin + part.
     *
     * @return list<array<string, mixed>>
     */
    public function forDate(string $date, ?int $machineId = null): array
    {
        $movements = $this->movementsForDate($date, $machineId);
        if ($movements === []) {
            return [];
        }

        $partIds = array_values(array_unique(array_column($movements, 'part_id')));
        $machines = $this->machines($machineId);
        $parts = Part::query()
            ->whereIn('id', $partIds)
            ->with('partType:id,code')
            ->get(['id', 'part_number', 'part_name', 'model', 'size', 'part_type_id'])
            ->keyBy('id');

        $rows = [];

        foreach ($movements as $movement) {
            $mid = (int) $movement['machine_id'];
            $pid = (int) $movement['part_id'];
            $part = $parts[$pid] ?? null;

            $rows[] = [
                'machine_id' => $mid,
                'machine_name' => $machines[$mid]['machine_name'] ?? '—',
                'machine_code' => $machines[$mid]['machine_code'] ?? null,
                'part_id' => $pid,
                'part_number' => $part?->part_number,
                'part_name' => $part?->part_name,
                'model' => $part?->model,
                'size' => $part?->size,
                // WIP = barang setengah jadi; MATERIAL = raw material murni.
                'part_type' => strtoupper((string) $part?->partType?->code) ?: null,
                'invoice' => $movement['invoice'],
                'supplier' => $movement['supplier'],
                'uom' => $movement['uom'],
                'beginning_qty' => round((float) $movement['beginning_qty'], 4),
                'received_qty' => round((float) $movement['received_qty'], 4),
                'issued_qty' => round((float) $movement['issued_qty'], 4),
                'ending_qty' => round(
                    (float) $movement['beginning_qty']
                    + (float) $movement['received_qty']
                    - (float) $movement['issued_qty'],
                    4,
                ),
            ];
        }

        // Urutkan per kategori dulu agar WIP dan raw material tidak tercampur.
        usort($rows, fn (array $a, array $b): int => [$a['part_type'] ?? '', $a['machine_name'], $a['part_number']]
            <=> [$b['part_type'] ?? '', $b['machine_name'], $b['part_number']]);

        return $rows;
    }

    /**
     * Pergerakan (mesin, part) pada tanggal itu: penerimaan hari itu DAN
     * konsumsi hari itu, beserta saldo awalnya dihitung sekali per pasangan.
     *
     * @return list<array<string, mixed>>
     */
    private function movementsForDate(string $date, ?int $machineId): array
    {
        $received = DB::table('production_material_receipts as r')
            ->leftJoin('material_issue_items as mii', 'mii.id', '=', 'r.material_issue_item_id')
            ->where('r.transfer_status', 'transferred')
            ->whereDate('r.received_at', $date)
            ->when($machineId !== null, fn ($q) => $q->where('r.machine_id', $machineId))
            ->groupBy('r.machine_id', 'r.part_id', 'r.uom')
            ->selectRaw('r.machine_id, r.part_id, r.uom, SUM(r.qty) AS qty')
            ->selectRaw('MAX(COALESCE(mii.invoice, r.invoice)) AS invoice')
            ->selectRaw('MAX(COALESCE(mii.supplier, r.supplier)) AS supplier')
            ->get();

        $issued = $this->issuedQuery($machineId)
            ->whereDate('work_order_consumptions.created_at', $date)
            ->groupBy('woi.machine_id', 'work_order_consumptions.part_id', 'work_order_consumptions.uom')
            ->selectRaw('woi.machine_id, work_order_consumptions.part_id, work_order_consumptions.uom, SUM(work_order_consumptions.qty) AS qty')
            ->selectRaw('STRING_AGG(DISTINCT work_order_consumptions.invoice_no, \' | \') AS invoice')
            ->selectRaw('STRING_AGG(DISTINCT work_order_consumptions.supplier_name, \' | \') AS supplier')
            ->get();

        $buckets = [];

        foreach ($received as $row) {
            $key = $row->machine_id.'|'.$row->part_id.'|'.($row->uom ?? '');
            $buckets[$key] ??= [
                'machine_id' => (int) $row->machine_id,
                'part_id' => (int) $row->part_id,
                'uom' => $row->uom,
                'invoice' => $row->invoice,
                'supplier' => $row->supplier,
                'received_qty' => 0.0,
                'issued_qty' => 0.0,
            ];
            $buckets[$key]['received_qty'] += (float) $row->qty;
        }

        foreach ($issued as $row) {
            if ($row->machine_id === null) {
                continue;
            }
            $key = $row->machine_id.'|'.$row->part_id.'|'.($row->uom ?? '');
            $buckets[$key] ??= [
                'machine_id' => (int) $row->machine_id,
                'part_id' => (int) $row->part_id,
                'uom' => $row->uom,
                'invoice' => $row->invoice,
                'supplier' => $row->supplier,
                'received_qty' => 0.0,
                'issued_qty' => 0.0,
            ];

            // Nota bisa datang dari konsumsi walau tidak ada penerimaan di hari
            // itu (material masuk kemarin, dipakai hari ini). Jangan dipaksa null.
            if ($buckets[$key]['invoice'] === null && $row->invoice !== null) {
                $buckets[$key]['invoice'] = $row->invoice;
                $buckets[$key]['supplier'] = $row->supplier;
            }

            $buckets[$key]['issued_qty'] += (float) $row->qty;
        }

        $beginning = $this->beginningMap($date, $machineId);

        $rows = [];
        foreach ($buckets as $bucket) {
            $key = $bucket['machine_id'].'|'.$bucket['part_id'];
            $bucket['beginning_qty'] = $beginning[$key] ?? 0.0;
            $rows[] = $bucket;
        }

        return $rows;
    }

    /** Saldo awal semua pasangan (mesin, part) dalam satu query per sisi. */
    private function beginningMap(string $date, ?int $machineId): array
    {
        $received = DB::table('production_material_receipts')
            ->where('transfer_status', 'transferred')
            ->whereDate('received_at', '<', $date)
            ->when($machineId !== null, fn ($q) => $q->where('machine_id', $machineId))
            ->groupBy('machine_id', 'part_id')
            ->selectRaw('machine_id, part_id, SUM(qty) AS qty')
            ->get();

        $issued = $this->issuedQuery($machineId)
            ->whereDate('work_order_consumptions.created_at', '<', $date)
            ->groupBy('woi.machine_id', 'work_order_consumptions.part_id')
            ->selectRaw('woi.machine_id, work_order_consumptions.part_id, SUM(work_order_consumptions.qty) AS qty')
            ->get();

        $map = [];
        foreach ($received as $row) {
            $map[$row->machine_id.'|'.$row->part_id] = (float) $row->qty;
        }
        foreach ($issued as $row) {
            if ($row->machine_id === null) {
                continue;
            }
            $key = $row->machine_id.'|'.$row->part_id;
            $map[$key] = ($map[$key] ?? 0.0) - (float) $row->qty;
        }

        return $map;
    }

    /** Basis query konsumsi BOM yang sudah terkait ke mesin item WO. */
    private function issuedQuery(?int $machineId = null, ?int $partId = null)
    {
        return WorkOrderConsumption::query()
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

    /**
     * Rincian per nota untuk satu kombinasi mesin + part pada satu tanggal.
     *
     * Tiga bagian dipisah karena sumbernya berbeda: stok awal (penerimaan
     * sebelum tanggal), penerimaan hari itu, dan pemakaian hari itu. Kalau
     * digabung, barang yang masuk kemarin lalu dipakai hari ini terhitung dua
     * kali dan angkanya menyesatkan.
     *
     * @return array{
     *     machine: array{id: int, name: string, code: string|null},
     *     part: array{id: int, number: string|null, name: string|null, model: string|null, size: string|null},
     *     uom: string|null,
     *     date: string,
     *     beginning: list<array<string, mixed>>,
     *     received: list<array<string, mixed>>,
     *     issued: list<array<string, mixed>>,
     *     totals: array{beginning: float, received: float, issued: float, ending: float},
     * }
     */
    public function detail(int $machineId, int $partId, string $date): array
    {
        $machine = DB::table('machines')->where('id', $machineId)->first(['id', 'machine_name', 'machine_code']);
        $part = Part::query()->find($partId, ['id', 'part_number', 'part_name', 'model', 'size', 'uom_id']);

        $beginning = DB::table('production_material_receipts as r')
            ->leftJoin('material_issue_items as mii', 'mii.id', '=', 'r.material_issue_item_id')
            ->where('r.transfer_status', 'transferred')
            ->where('r.machine_id', $machineId)
            ->where('r.part_id', $partId)
            ->whereDate('r.received_at', '<', $date)
            ->groupBy('r.uom', 'r.invoice', 'r.supplier', 'mii.uom', 'mii.invoice', 'mii.supplier')
            ->selectRaw('COALESCE(mii.uom, r.uom) AS uom')
            ->selectRaw('COALESCE(mii.invoice, r.invoice) AS invoice')
            ->selectRaw('COALESCE(mii.supplier, r.supplier) AS supplier')
            ->selectRaw('SUM(r.qty) AS qty')
            ->get();

        $received = DB::table('production_material_receipts as r')
            ->leftJoin('material_issue_items as mii', 'mii.id', '=', 'r.material_issue_item_id')
            ->where('r.transfer_status', 'transferred')
            ->where('r.machine_id', $machineId)
            ->where('r.part_id', $partId)
            ->whereDate('r.received_at', $date)
            ->groupBy('r.uom', 'r.invoice', 'r.supplier', 'mii.uom', 'mii.invoice', 'mii.supplier')
            ->selectRaw('COALESCE(mii.uom, r.uom) AS uom')
            ->selectRaw('COALESCE(mii.invoice, r.invoice) AS invoice')
            ->selectRaw('COALESCE(mii.supplier, r.supplier) AS supplier')
            ->selectRaw('SUM(r.qty) AS qty')
            ->get();

        $issued = $this->issuedQuery()
            ->where('woi.machine_id', $machineId)
            ->where('work_order_consumptions.part_id', $partId)
            ->whereDate('work_order_consumptions.created_at', $date)
            ->groupBy('work_order_consumptions.uom', 'work_order_consumptions.invoice_no', 'work_order_consumptions.supplier_name')
            ->selectRaw('work_order_consumptions.uom AS uom')
            ->selectRaw('work_order_consumptions.invoice_no AS invoice')
            ->selectRaw('work_order_consumptions.supplier_name AS supplier')
            ->selectRaw('SUM(work_order_consumptions.qty) AS qty')
            ->get();

        $map = fn ($rows): array => collect($rows)->map(fn ($row): array => [
            'invoice' => $row->invoice,
            'supplier' => $row->supplier,
            'uom' => $row->uom,
            'qty' => round((float) $row->qty, 4),
        ])->sortBy([['invoice', 'asc']])->values()->all();

        $beginningRows = $map($beginning);
        $receivedRows = $map($received);
        $issuedRows = $map($issued);

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
            ],
            'uom' => $issuedRows[0]['uom'] ?? $receivedRows[0]['uom'] ?? $beginningRows[0]['uom'] ?? null,
            'date' => $date,
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
}
