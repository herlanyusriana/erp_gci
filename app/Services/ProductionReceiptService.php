<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\MaterialIssueItem;
use App\Models\PartStock;
use App\Models\ProductionMaterialReceipt;
use App\Models\WorkOrderMaterialBooking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penerimaan material oleh production di lantai.
 *
 * Setelah warehouse issue-out (scan tag → release WO), operator produksi
 * scan tag material yang sama + QR mesin tujuan. Saat konfirmasi, **stok
 * gudang benar-benar berkurang** — booking dilepas, `part_stocks.qty` turun.
 */
class ProductionReceiptService
{
    /**
     * Konfirmasi satu penerimaan: tag material masuk ke mesin tertentu.
     * Stok gudang berkurang saat ini dipanggil.
     *
     * @throws ValidationException
     */
    public function confirm(string $tag, int $machineId, ?int $userId = null, ?string $notes = null): ProductionMaterialReceipt
    {
        $tag = strtoupper(trim($tag));

        // Cari item issue yang cocok dengan tag ini — material harus sudah di-issue-out.
        $issueItem = MaterialIssueItem::query()
            ->whereRaw('LOWER(COALESCE(tag, \'\')) = ?', [mb_strtolower($tag)])
            ->latest('id')
            ->first();

        if ($issueItem === null) {
            throw ValidationException::withMessages([
                'tag' => __('Tag :tag belum di-issue-out.', ['tag' => $tag]),
            ]);
        }

        // Mesin harus aktif.
        $machine = Machine::find($machineId);
        if ($machine === null || ! $machine->is_active) {
            throw ValidationException::withMessages([
                'machine_id' => __('Mesin tidak ditemukan atau tidak aktif.'),
            ]);
        }

        // Satu tag hanya bisa diterima sekali per mesin.
        $alreadyReceived = ProductionMaterialReceipt::query()
            ->where('material_issue_item_id', $issueItem->id)
            ->where('machine_id', $machineId)
            ->exists();

        if ($alreadyReceived) {
            throw ValidationException::withMessages([
                'tag' => __('Tag :tag sudah pernah diterima di mesin ini.', ['tag' => $tag]),
            ]);
        }

        // Simpan receipt + kurangi stok gudang dalam satu transaksi.
        $receipt = DB::transaction(function () use ($issueItem, $tag, $machineId, $userId, $notes) {
            $receipt = ProductionMaterialReceipt::create([
                'material_issue_item_id' => $issueItem->id,
                'tag' => $issueItem->tag,
                'part_id' => $issueItem->part_id,
                'machine_id' => $machineId,
                'received_by' => $userId,
                'received_at' => now(),
                'notes' => $notes,
            ]);

            // Kurangi stok gudang: cari baris stok berdasarkan tag ini,
            // lalu kurangi booking-nya.
            $stock = PartStock::query()
                ->whereRaw('LOWER(COALESCE(tag, \'\')) = ?', [mb_strtolower($tag)])
                ->lockForUpdate()
                ->first();

            if ($stock !== null) {
                $booking = WorkOrderMaterialBooking::query()
                    ->where('part_stock_id', $stock->id)
                    ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED)
                    ->lockForUpdate()
                    ->first();

                if ($booking !== null) {
                    // Kurangi stok fisik.
                    $newQty = (float) $stock->qty - (float) $booking->qty;
                    if ($newQty <= 1e-9) {
                        $stock->delete();
                    } else {
                        $stock->update(['qty' => $newQty]);
                    }

                    // Tandai booking sebagai consumed.
                    $booking->update([
                        'status' => WorkOrderMaterialBooking::STATUS_CONSUMED,
                        'consumed_at' => now(),
                        'updated_by' => $userId,
                        'updated_at' => now(),
                    ]);
                }
            }

            return $receipt;
        });

        return $receipt;
    }

    /**
     * Daftar penerimaan untuk sebuah mesin pada tanggal tertentu.
     *
     * @return list<ProductionMaterialReceipt>
     */
    public function receiptsByMachine(int $machineId, ?string $date = null): array
    {
        $targetDate = $date ?? Carbon::now((string) config('app.timezone'))->toDateString();

        return ProductionMaterialReceipt::query()
            ->where('machine_id', $machineId)
            ->whereDate('received_at', $targetDate)
            ->with(['part:id,part_number,part_name,model,size', 'receiver:id,name'])
            ->orderByDesc('received_at')
            ->get()
            ->all();
    }
}
