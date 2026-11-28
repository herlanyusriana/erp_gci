<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bersihkan baris item kedatangan yang belum pernah di-receive (receives = 0)
 * pada dokumen Arrival ber-PO yang sudah memiliki minimal 1 item lain ber-receive.
 *
 * Tanpa `--force`, perintah hanya menampilkan rencana (dry run).
 */
class CleanUnreceivedArrivalItems extends Command
{
    protected $signature = 'incoming:clean-unreceived-arrival-items
        {--po= : Filter nomor PO tertentu (contoh: PO/1026/00313)}
        {--arrival= : Filter nomor Arrival tertentu (contoh: ARV-26110001)}
        {--local-only : Hanya proses Arrival bertipe lokal}
        {--force : Benar-benar soft-delete item (tanpa ini hanya dry run)}';

    protected $description = 'Bersihkan baris item Arrival ber-PO yang belum di-receive pada Arrival yang sudah sebagian diterima';

    public function handle(): int
    {
        $poNo = trim((string) $this->option('po'));
        $arrivalNo = trim((string) $this->option('arrival'));
        $localOnly = (bool) $this->option('local-only');
        $force = (bool) $this->option('force');

        $candidates = IncomingArrivalItem::query()
            ->with([
                'arrival.purchaseOrder:id,po_no',
                'arrival.supplier:id,supplier_name',
                'part:id,part_number,part_name,model',
            ])
            ->whereDoesntHave('receives')
            ->whereHas('arrival', function ($arrivalQuery) use ($poNo, $arrivalNo, $localOnly): void {
                $arrivalQuery
                    ->whereNotNull('purchase_order_id')
                    ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))
                    ->whereHas('items', fn ($itemQuery) => $itemQuery->whereHas('receives'))
                    ->when($localOnly, fn ($q) => $q->where(fn ($loc) => $loc
                        ->where('arrival_type', IncomingArrival::TYPE_LOCAL)
                        ->orWhere('is_local', true)))
                    ->when($arrivalNo !== '', fn ($q) => $q->whereRaw('LOWER(arrival_no) = ?', [strtolower($arrivalNo)]))
                    ->when($poNo !== '', fn ($q) => $q->whereHas(
                        'purchaseOrder',
                        fn ($pq) => $pq->whereRaw('LOWER(po_no) = ?', [strtolower($poNo)]),
                    ));
            })
            ->orderBy('arrival_id')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Tidak ada item Arrival belum di-receive yang memenuhi kriteria pembersihan.');

            return self::SUCCESS;
        }

        $rows = $candidates->map(fn (IncomingArrivalItem $item): array => [
            $item->arrival?->purchaseOrder?->po_no ?? '—',
            $item->arrival?->arrival_no ?? '—',
            $item->arrival?->supplier?->supplier_name ?? '—',
            $item->part?->part_number ?? '—',
            $item->part?->part_name ?? '—',
            $item->part?->model ?: '—',
            rtrim(rtrim(number_format((float) $item->qty_goods, 4, '.', ''), '0'), '.'),
            $item->unit_goods ?? '—',
        ])->all();

        $this->table(
            ['PO No', 'Arrival No', 'Supplier', 'Part No', 'Part Name', 'Model', 'Qty', 'Unit'],
            $rows,
        );

        $affectedPos = $candidates
            ->pluck('arrival.purchaseOrder.po_no')
            ->filter()
            ->unique()
            ->count();

        if (! $force) {
            $this->warn("DRY RUN: Ditemukan {$candidates->count()} baris item pada {$affectedPos} PO. Tidak ada data yang dihapus.");
            $this->line('Jalankan ulang dengan --force untuk melakukan soft-delete baris di atas.');

            return self::SUCCESS;
        }

        $deletedCount = DB::transaction(function () use ($candidates): int {
            $ids = $candidates->pluck('id')->all();

            return IncomingArrivalItem::query()
                ->whereIn('id', $ids)
                ->whereDoesntHave('receives')
                ->delete();
        });

        $this->info("Selesai: {$deletedCount} baris item berhasil di-soft-delete pada {$affectedPos} PO. Sisa PO otomatis pulih.");

        return self::SUCCESS;
    }
}
