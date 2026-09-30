<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\MaterialIssueItem;
use App\Models\ProductionMaterialReceipt;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Penerimaan material oleh production di lantai.
 *
 * Setelah warehouse issue-out (scan tag → release WO), operator produksi
 * scan tag material yang sama + QR mesin tujuan untuk mencatat bahwa
 * material sudah sampai di mesin tersebut. Tidak ada input qty — qty
 * diambil dari dokumen issue.
 */
class ProductionReceiptService
{
    /**
     * Konfirmasi satu penerimaan: tag material masuk ke mesin tertentu.
     *
     * @param  string  $tag  nomor tag fisik hasil scan label material
     * @param  int  $machineId  id mesin hasil scan QR label mesin
     * @param  int|null  $userId  user yang menerima
     * @param  string|null  $notes  catatan opsional
     * @return ProductionMaterialReceipt
     *
     * @throws ValidationException bila tag belum di-issue, sudah diterima, atau mesin nonaktif
     */
    public function confirm(string $tag, int $machineId, ?int $userId = null, ?string $notes = null): ProductionMaterialReceipt
    {
        // Cari item issue yang cocok dengan tag ini — material harus sudah di-issue-out.
        $issueItem = MaterialIssueItem::query()
            ->whereRaw('LOWER(COALESCE(tag, \'\')) = ?', [mb_strtolower(trim($tag))])
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

        return ProductionMaterialReceipt::create([
            'material_issue_item_id' => $issueItem->id,
            'tag' => $issueItem->tag,
            'part_id' => $issueItem->part_id,
            'machine_id' => $machineId,
            'received_by' => $userId,
            'received_at' => now(),
            'notes' => $notes,
        ]);
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
