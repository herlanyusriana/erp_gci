<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\ConfigMaster;
use App\Models\DeliveryScan;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionMaterialReceipt;
use App\Models\ProductionResult;
use App\Models\WorkOrder;
use App\Models\WorkOrderConsumption;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderMaterialBooking;
use App\Support\MachineRouting;
use App\Support\UomCatalog;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionResultService
{
    public function __construct(protected ReceiveMaterialService $stockService) {}

    public function report(WorkOrder $workOrder, int $parentPartId, array $data, ?int $actorId = null): ProductionResult
    {
        return DB::transaction(function () use ($workOrder, $parentPartId, $data, $actorId) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $idempotencyKey = $data['idempotency_key'] ?? null;
            if ($idempotencyKey !== null) {
                // APK mengirim ulang laporan setelah timeout. Kembalikan baris
                // yang sudah tersimpan — termasuk yang sudah dibatalkan
                // (soft delete) — supaya unique index tidak meledak dan output
                // tidak terpotong dua kali.
                $existing = ProductionResult::withTrashed()
                    ->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    abort_unless($existing->work_order_id === $workOrder->id, 422, __('Laporan hasil tidak dikenal.'));

                    return $existing;
                }
            }
            abort_if($workOrder->status !== 'in_progress', 422, __('WO belum di-release.'));
            $rows = $workOrder->items()->with([
                'machine:id,machine_code,machine_name',
                'parentPart:id,part_number,part_name',
                'childPart:id,part_number,part_name',
            ])
                ->where('parent_part_id', $parentPartId)->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['parent_part_id' => __('Step ini tidak ada di WO.')]);
            }
            if ($rows->every(fn (WorkOrderItem $r): bool => ($r->output_type ?? BomItem::OUTPUT_NORMAL) === BomItem::OUTPUT_BYPRODUCT)) {
                throw ValidationException::withMessages(['parent_part_id' => __('Hasil sampingan otomatis terbuat dari proses utamanya.')]);
            }
            $qtyGood = (float) ($data['qty_good'] ?? 0);
            $qtyReject = (float) ($data['qty_reject'] ?? 0);
            if (! is_finite($qtyGood) || $qtyGood < 0) {
                throw ValidationException::withMessages(['qty_good' => __('Qty good tidak boleh negatif.')]);
            }
            if (! is_finite($qtyReject) || $qtyReject < 0) {
                throw ValidationException::withMessages(['qty_reject' => __('Qty reject tidak boleh negatif.')]);
            }
            // Hasil reject saja tetap laporan yang sah (mesin menghasilkan scrap
            // penuh); yang tidak masuk akal adalah keduanya nol.
            if ($qtyGood <= 0 && $qtyReject <= 0) {
                throw ValidationException::withMessages(['qty_good' => __('Isi qty good atau qty reject.')]);
            }
            try {
                $goodDecimal = BigDecimal::of($data['qty_good'])->toScale(4);
                $rejectDecimal = BigDecimal::of($data['qty_reject'] ?? 0)->toScale(4);
            } catch (MathException) {
                throw ValidationException::withMessages(['qty_good' => __('Qty hasil maksimal empat angka desimal.')]);
            }
            $woQty = BigDecimal::of($workOrder->getRawOriginal('qty'));
            // Plafon per laporan: reject ikut menggerakkan konsumsi material,
            // jadi salah ketik satu nol harus tertahan di sini.
            if ($goodDecimal->plus($rejectDecimal)->isGreaterThan($woQty)) {
                throw ValidationException::withMessages([
                    'qty_reject' => __('Qty good + reject satu laporan melebihi qty WO (:qty).', ['qty' => $workOrder->qty]),
                ]);
            }
            $produced = BigDecimal::of(ProductionResult::query()->where('work_order_id', $workOrder->id)
                ->where('parent_part_id', $parentPartId)->sum('qty_good'));
            if ($produced->plus($goodDecimal)->isGreaterThan($woQty)) {
                throw ValidationException::withMessages([
                    'qty_good' => __('Total hasil step ini melebihi qty WO (:qty).', ['qty' => $workOrder->qty]),
                ]);
            }
            $first = $rows->first();
            $machineId = $data['machine_id'] ?? $first->machine_id;
            $machine = $machineId !== null ? Machine::query()->lockForUpdate()->find($machineId) : null;
            foreach ($rows as $row) {
                if ($machineId !== null || $row->machine_id !== null) {
                    MachineRouting::assertEligible($machine, $row);
                }
                if (! is_finite((float) $row->qty_consumed) || $row->qty_consumed < 0) {
                    throw ValidationException::withMessages(['qty_good' => __('Saldo konsumsi tidak valid. Rekonsiliasi diperlukan.')]);
                }
            }
            $internalParents = $workOrder->items()->pluck('parent_part_id')->filter()->unique()->all();
            $reportedAt = ! empty($data['result_date']) ? Carbon::parse($data['result_date']) : now();
            $uom = $parentPartId === (int) $workOrder->part_id
                ? UomCatalog::PIECE
                : (UomCatalog::normalize((string) $first->parent_uom) ?? UomCatalog::PIECE);
            // Periode jam: laporan yang diisi setelah periodenya lewat ditandai
            // susulan, agar supervisor bisa membedakannya dari laporan tepat waktu.
            $periodStart = $data['period_start'] ?? null;
            $periodEnd = $data['period_end'] ?? null;
            $backfilled = false;

            if ($periodStart !== null && $periodEnd !== null) {
                $zone = (string) ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta');

                // Bandingkan di zona pabrik: jam periode dibaca sebagai jam lokal,
                // bukan jam UTC. Laporan dianggap susulan bila waktu lapor melewati
                // akhir periode pada tanggal laporan itu.
                $reportedLocal = $reportedAt->copy()->setTimezone($zone);
                $endOfPeriod = $reportedLocal->copy()->setTimeFromTimeString($periodEnd);

                $backfilled = $reportedLocal->greaterThan($endOfPeriod);
            }

            $operatorName = isset($data['operator_name']) && trim((string) $data['operator_name']) !== ''
                ? trim((string) $data['operator_name'])
                : null;

            $result = ProductionResult::create([
                'work_order_id' => $workOrder->id, 'parent_part_id' => $parentPartId,
                'process_id' => $first->process_id, 'machine_id' => $machineId,
                'result_date' => $reportedAt->toDateString(), 'shift' => $data['shift'] ?? null,
                'period_start' => $periodStart, 'period_end' => $periodEnd,
                'is_backfilled' => $backfilled, 'label_status' => 'pending',
                'qty_good' => (string) $goodDecimal, 'qty_reject' => (string) $rejectDecimal, 'uom' => $uom,
                'reported_by' => $actorId, 'operator_name' => $operatorName, 'notes' => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actorId, 'updated_by' => $actorId,
            ]);

            // Kumpulkan baris input efektif: bila step ini adalah bagian dari grup `shared`
            // (1 stroke -> 2+ hasil), konsumsi harus menggunakan baris pemegang qty_required
            // (sekali saja, baik operator melapor hasil pertama maupun pasangannya).
            $consumptionRows = collect();
            $seenSharedConsume = [];
            foreach ($rows as $row) {
                $outputType = $row->output_type ?? BomItem::OUTPUT_NORMAL;
                if ($outputType === BomItem::OUTPUT_BYPRODUCT) {
                    continue;
                }
                if ($outputType === BomItem::OUTPUT_SHARED && $row->child_part_id !== null) {
                    $sharedKey = ($row->sequence ?? 0).'|'.$row->child_part_id;
                    if (isset($seenSharedConsume[$sharedKey])) {
                        continue;
                    }
                    $seenSharedConsume[$sharedKey] = true;
                    $holder = $workOrder->items()
                        ->with(['machine:id,machine_code,machine_name', 'parentPart:id,part_number,part_name', 'childPart:id,part_number,part_name'])
                        ->where('sequence', $row->sequence)
                        ->where('child_part_id', $row->child_part_id)
                        ->where('output_type', BomItem::OUTPUT_SHARED)
                        ->orderByDesc('qty_required')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();
                    $consumptionRows->push($holder ?? $row);

                    continue;
                }
                $consumptionRows->push($row);
            }

            foreach ($consumptionRows as $row) {
                $childId = $row->child_part_id;
                $need = $this->ratioDecimal($row)
                    ->multipliedBy($goodDecimal->plus($rejectDecimal))
                    ->toScale(10, RoundingMode::HALF_UP);
                if ($childId === null || $need->isLessThanOrEqualTo(0)) {
                    continue;
                }
                if (in_array($childId, $internalParents, true)) {
                    // Khusus TPL DONGSHIN dan TPL KUKIL:
                    // Step perantara (WIP1, WIP2, dst.) tidak dilaporkan terpisah dan tidak
                    // memegang stok fisik. Bila yang dilaporkan adalah step akhir TPL dan
                    // stok perantara tidak ada, langsung konsumsi Raw Material di stasiun 1 TPL.
                    if ($machine !== null) {
                        $tplChainMachineId = (int) ($row->machine_id ?? $machine->id);
                        $isTplStep = $row->machine !== null
                            ? MachineRouting::isTplPressLine($row->machine)
                            : MachineRouting::isTplPressLine($machine);

                        if ($isTplStep
                            && $this->isFinalTplStep($workOrder, $tplChainMachineId, $row)
                            && $this->isIntermediateTplChild($workOrder, $tplChainMachineId, (int) $childId)
                            && $this->stockService->availableFifo((int) $childId) < $need->toFloat()
                        ) {
                            $this->consumeTplRootInputs($workOrder, $row, $machine, $tplChainMachineId, $result, $goodDecimal->plus($rejectDecimal));

                            continue;
                        }
                    }

                    $taken = BigDecimal::zero();
                    // Untuk WIP internal, jangan filter UOM dengan uom_rm BOM (karena di BOM
                    // sering tertulis SHEET padahal WIP fisiknya ber-UOM PCS).
                    // part_id WIP sudah spesifik dan unik untuk part tersebut.
                    foreach ($this->stockService->consumeFifoByUom((int) $childId, (string) $need, null, $workOrder->id) as $allocation) {
                        $taken = $taken->plus($allocation['take_qty']);

                        // Wariskan nota & supplier asal dari konsumsi yang menghasilkan WIP ini
                        $sourceInv = WorkOrderConsumption::query()
                            ->whereHas('productionResult', fn ($q) => $q->where('output_part_stock_id', $allocation['part_stock_id']))
                            ->whereNotNull('invoice_no')
                            ->first();

                        WorkOrderConsumption::create([
                            'production_result_id' => $result->id,
                            'work_order_id' => $workOrder->id, 'work_order_item_id' => $row->id,
                            'part_stock_id' => $allocation['part_stock_id'], 'part_id' => $childId,
                            'qty' => $allocation['take_qty'], 'uom' => $allocation['uom'],
                            'invoice_no' => $sourceInv?->invoice_no,
                            'supplier_name' => $sourceInv?->supplier_name,
                            'source_tag' => $sourceInv?->source_tag ?? ($allocation['tag'] ?? null),
                        ]);
                    }
                    if ($taken->isLessThan($need)) {
                        $wipNumber = Part::find($childId)?->part_number ?? 'WIP';
                        $needStr = rtrim(rtrim((string) $need, '0'), '.');
                        $availStr = rtrim(rtrim((string) $taken, '0'), '.');
                        throw ValidationException::withMessages([
                            'qty_good' => __('Stok :wip belum mencukupi. Butuh :need :uom (stok tersedia :avail :uom). Selesaikan dan laporkan proses sebelumnya terlebih dahulu.', [
                                'wip' => $wipNumber,
                                'need' => $needStr,
                                'avail' => $availStr,
                                'uom' => $row->uom_rm ?? 'PCS',
                            ]),
                        ]);
                    }
                } else {
                    $receiptEntries = $machineId !== null
                        ? $this->consumableMachineReceipts($workOrder, $row, (int) $machineId, UomCatalog::normalize((string) $row->uom_rm))
                        : collect();
                    $available = BigDecimal::zero();
                    foreach ($receiptEntries as $entry) {
                        $receipt = $entry['receipt'];
                        $receipt->assertValidBalance();
                        $balance = BigDecimal::of($receipt->getRawOriginal('qty'))->minus($receipt->getRawOriginal('qty_consumed'));
                        $usable = $entry['limit'] !== null && $entry['limit']->isLessThan($balance) ? $entry['limit'] : $balance;
                        if ($usable->isGreaterThan(0)) {
                            $available = $available->plus($usable);
                        }
                    }
                    if ($machineId === null || $available->isLessThan($need)) {
                        if ($machineId !== null && $rows->count() > 1 && ReleaseContextService::isAuxiliaryItem($row)) {
                            $need = $available;
                        } else {
                            $matNumber = $row->childPart?->part_number ?? $row->child_part_name ?? 'material';
                            $uomStr = UomCatalog::normalize((string) $row->uom_rm) ?? $row->uom_rm ?? '';
                            $needStr = rtrim(rtrim((string) $need, '0'), '.');
                            $availStr = rtrim(rtrim((string) $available, '0'), '.');
                            throw ValidationException::withMessages([
                                'qty_good' => __('Material :part belum diterima di mesin ini. Butuh :need :uom (di mesin baru ada :avail :uom). Silakan terima material terlebih dahulu di menu Terima Material.', [
                                    'part' => $matNumber,
                                    'need' => $needStr,
                                    'avail' => $availStr,
                                    'uom' => $uomStr,
                                ]),
                            ]);
                        }
                    }
                    if ($need->isLessThanOrEqualTo(0)) {
                        continue;
                    }
                    $remaining = $need;
                    foreach ($receiptEntries as $entry) {
                        if ($remaining->isZero()) {
                            break;
                        }
                        $receipt = $entry['receipt'];
                        $balance = BigDecimal::of($receipt->getRawOriginal('qty'))->minus($receipt->getRawOriginal('qty_consumed'));
                        $usable = $entry['limit'] !== null && $entry['limit']->isLessThan($balance) ? $entry['limit'] : $balance;
                        if ($usable->isLessThanOrEqualTo(0)) {
                            continue;
                        }
                        $take = $remaining->isLessThan($usable) ? $remaining : $usable;
                        if ($take->isZero()) {
                            continue;
                        }
                        $receipt->increment('qty_consumed', (string) $take);
                        WorkOrderConsumption::create([
                            'production_result_id' => $result->id, 'production_material_receipt_id' => $receipt->id,
                            'work_order_id' => $workOrder->id, 'work_order_item_id' => $row->id,
                            'part_stock_id' => $receipt->part_stock_id, 'part_id' => $receipt->part_id,
                            'qty' => (string) $take, 'uom' => $receipt->uom,
                            // Simpan asal bahan supaya FG bisa ditelusuri ke notanya.
                            'invoice_no' => $receipt->invoice,
                            'supplier_name' => $receipt->supplier,
                            'source_tag' => $receipt->tag,
                        ]);
                        $remaining = $remaining->minus($take);
                    }
                }
                $row->increment('qty_consumed', (string) $need);
            }

            // Laporan reject saja tidak menghasilkan stok: jangan bikin baris
            // output ber-qty 0 yang hanya jadi sampah di daftar stok.
            if (! $goodDecimal->isZero()) {
                $tag = $workOrder->wo_no.'#'.($first->parentPart?->part_number ?? $parentPartId).'#R'.$result->id;
                $output = $this->stockService->postProductionStock(
                    $parentPartId, $tag, (string) $goodDecimal, $uom, $reportedAt,
                    // Lokasi barang jadi sementara: mesin yang memproduksinya.
                    $machine?->machine_code,
                );
                $result->update(['output_part_stock_id' => $output->id]);

                $this->postCompanionOutputs($workOrder, $rows, $result, $goodDecimal, $uom, $reportedAt, $machine, $actorId, $operatorName);
            }

            return $result;
        });
    }

    /**
     * Terbitkan stok WIP/FG tambahan untuk hasil bersama (`shared`) pada stroke
     * yang sama maupun hasil sampingan (`byproduct`) pada sequence yang sama.
     *
     * @param  Collection<int, WorkOrderItem>  $reportedRows
     */
    private function postCompanionOutputs(
        WorkOrder $workOrder,
        Collection $reportedRows,
        ProductionResult $primaryResult,
        BigDecimal $goodDecimal,
        string $primaryUom,
        Carbon $reportedAt,
        ?Machine $machine,
        ?int $actorId,
        ?string $operatorName,
    ): void {
        $companions = collect();

        foreach ($reportedRows as $row) {
            if (($row->output_type ?? BomItem::OUTPUT_NORMAL) === BomItem::OUTPUT_SHARED && $row->child_part_id !== null) {
                $sharedSiblings = $workOrder->items()
                    ->with('parentPart:id,part_number,part_name')
                    ->where('sequence', $row->sequence)
                    ->where('child_part_id', $row->child_part_id)
                    ->where('output_type', BomItem::OUTPUT_SHARED)
                    ->where('parent_part_id', '!=', $primaryResult->parent_part_id)
                    ->whereNotNull('parent_part_id')
                    ->get();
                $companions = $companions->concat($sharedSiblings);
            }

            if ($row->sequence !== null) {
                $byproducts = $workOrder->items()
                    ->with('parentPart:id,part_number,part_name')
                    ->where('sequence', $row->sequence)
                    ->where('output_type', BomItem::OUTPUT_BYPRODUCT)
                    ->where('parent_part_id', '!=', $primaryResult->parent_part_id)
                    ->whereNotNull('parent_part_id')
                    ->get();
                $companions = $companions->concat($byproducts);
            }
        }

        $companions = $companions->unique('parent_part_id')->values();

        foreach ($companions as $comp) {
            $compPartId = (int) $comp->parent_part_id;
            $compType = $comp->output_type ?? BomItem::OUTPUT_NORMAL;
            $compQty = $compType === BomItem::OUTPUT_BYPRODUCT
                ? $goodDecimal->multipliedBy(BigDecimal::of($comp->getRawOriginal('parent_qty') ?? 1))->toScale(4, RoundingMode::HALF_UP)
                : $goodDecimal;

            if ($compQty->isLessThanOrEqualTo(0)) {
                continue;
            }

            $compUom = UomCatalog::normalize((string) $comp->parent_uom) ?? $primaryUom;
            $compTag = $workOrder->wo_no.'#'.($comp->parentPart?->part_number ?? $compPartId).'#R'.$primaryResult->id;

            $compStock = $this->stockService->postProductionStock(
                $compPartId,
                $compTag,
                (string) $compQty,
                $compUom,
                $reportedAt,
                $machine?->machine_code,
            );

            ProductionResult::create([
                'work_order_id' => $workOrder->id,
                'parent_result_id' => $primaryResult->id,
                'parent_part_id' => $compPartId,
                'process_id' => $comp->process_id ?? $primaryResult->process_id,
                'machine_id' => $primaryResult->machine_id,
                'result_date' => $reportedAt->toDateString(),
                'shift' => $primaryResult->shift,
                'period_start' => $primaryResult->period_start,
                'period_end' => $primaryResult->period_end,
                'is_backfilled' => $primaryResult->is_backfilled,
                'label_status' => 'pending',
                'qty_good' => (string) $compQty,
                'qty_reject' => '0',
                'uom' => $compUom,
                'reported_by' => $actorId,
                'operator_name' => $operatorName,
                'notes' => $primaryResult->notes,
                'output_part_stock_id' => $compStock->id,
                'idempotency_key' => null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        }
    }

    public function reverse(WorkOrder $workOrder, ProductionResult $result, ?int $actorId = null): void
    {
        DB::transaction(function () use ($workOrder, $result, $actorId) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $result = ProductionResult::withTrashed()->lockForUpdate()->findOrFail($result->id);
            if ($result->work_order_id !== $workOrder->id) {
                throw ValidationException::withMessages(['result' => __('Hasil tidak termasuk WO ini.')]);
            }
            if ($result->trashed()) {
                return;
            }
            if ($result->parent_result_id !== null) {
                throw ValidationException::withMessages(['result' => __('Batalkan laporan hasil utama untuk membatalkan hasil bersama/sampingan ini.')]);
            }
            // Laporan reject saja tidak punya baris output; konsumsinya tetap
            // harus dikembalikan di bawah.
            $hadOutput = BigDecimal::of($result->getRawOriginal('qty_good'))->isGreaterThan(0);
            if ($result->output_part_stock_id === null && $hadOutput) {
                throw ValidationException::withMessages(['result' => __('Hasil produksi lama perlu rekonsiliasi sebelum dibatalkan.')]);
            }

            $companions = ProductionResult::query()
                ->where('parent_result_id', $result->id)
                ->lockForUpdate()
                ->get();

            foreach ($companions as $companion) {
                $this->reverseOutputStock($companion);
                $companion->update(['reversed_by' => $actorId]);
                $companion->delete();
            }

            $this->reverseOutputStock($result);
            $consumptions = WorkOrderConsumption::where('production_result_id', $result->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($consumptions as $consumption) {
                $item = WorkOrderItem::query()->lockForUpdate()->findOrFail($consumption->work_order_item_id);
                $itemConsumed = BigDecimal::of($item->getRawOriginal('qty_consumed'));
                $consumeQty = BigDecimal::of($consumption->getRawOriginal('qty'));
                if ($consumeQty->isLessThanOrEqualTo(0) || $itemConsumed->isLessThan($consumeQty)) {
                    throw ValidationException::withMessages(['result' => __('Saldo konsumsi tidak valid. Rekonsiliasi diperlukan.')]);
                }
                if ($consumption->production_material_receipt_id !== null) {
                    $receipt = ProductionMaterialReceipt::query()->lockForUpdate()->findOrFail($consumption->production_material_receipt_id);
                    $receipt->assertValidBalance();
                    $receiptConsumed = BigDecimal::of($receipt->getRawOriginal('qty_consumed'));
                    if ($receiptConsumed->isLessThan($consumeQty)) {
                        throw ValidationException::withMessages(['result' => __('Saldo konsumsi tidak valid. Rekonsiliasi diperlukan.')]);
                    }
                    ProductionMaterialReceipt::query()->whereKey($receipt->id)->update(['qty_consumed' => (string) $receiptConsumed->minus($consumeQty)]);
                } else {
                    $stock = PartStock::withTrashed()->lockForUpdate()->find($consumption->part_stock_id);
                    if ($stock === null) {
                        throw ValidationException::withMessages(['result' => __('Saldo konsumsi tidak valid. Rekonsiliasi diperlukan.')]);
                    }
                    PartStock::withTrashed()->whereKey($stock->id)->update(['qty' => (string) BigDecimal::of($stock->trashed() ? 0 : $stock->getRawOriginal('qty'))->plus($consumeQty)]);
                    if ($stock->trashed()) {
                        $stock->restore();
                    }
                }
                WorkOrderItem::query()->whereKey($item->id)->update(['qty_consumed' => (string) $itemConsumed->minus($consumeQty)]);
                $consumption->update(['reversed_by' => $actorId]);
                $consumption->delete();
            }
            $result->update(['reversed_by' => $actorId]);
            $result->delete();
        });
    }

    private function reverseOutputStock(ProductionResult $result): void
    {
        if ($result->output_part_stock_id === null) {
            return;
        }
        $output = PartStock::query()->lockForUpdate()->find($result->output_part_stock_id);
        if ($output === null || BigDecimal::of($output->getRawOriginal('qty'))->isLessThan($result->getRawOriginal('qty_good'))
            || WorkOrderConsumption::where('part_stock_id', $output->id)->exists()
            || WorkOrderMaterialBooking::where('part_stock_id', $output->id)->where('status', 'booked')->exists()
            || DeliveryScan::where('part_stock_id', $output->id)->exists()) {
            throw ValidationException::withMessages(['result' => __('Output hasil ini sudah digunakan atau dibooking. Batalkan proses berikutnya terlebih dahulu.')]);
        }
        $outputRemaining = BigDecimal::of($output->getRawOriginal('qty'))->minus($result->getRawOriginal('qty_good'));
        PartStock::query()->whereKey($output->id)->update(['qty' => (string) $outputRemaining]);
        if ($outputRemaining->isZero()) {
            $output->delete();
        }
    }

    public function progress(WorkOrder $workOrder): array
    {
        return ProductionResult::query()
            ->where('work_order_id', $workOrder->id)
            ->with('parentPart:id,part_number')
            ->get()
            ->groupBy('parent_part_id')
            ->map(fn ($group) => [
                'parent_part_id' => (int) $group->first()->parent_part_id,
                'part_number' => $group->first()->parentPart?->part_number,
                'produced' => (float) $group->sum('qty_good'),
                'reject' => (float) $group->sum('qty_reject'),
            ])
            ->values()
            ->all();
    }

    public function fgProduced(WorkOrder $workOrder): float
    {
        return (float) ProductionResult::query()
            ->where('work_order_id', $workOrder->id)
            ->where('parent_part_id', $workOrder->part_id)
            ->sum('qty_good');
    }

    /**
     * Cek apakah item merupakan step akhir pada mesin TPL (tidak menjadi child
     * bagi step lain di mesin TPL yang sama).
     */
    private function isFinalTplStep(WorkOrder $workOrder, int $tplMachineId, WorkOrderItem $item): bool
    {
        return ! $workOrder->items()
            ->where('machine_id', $tplMachineId)
            ->where('child_part_id', $item->parent_part_id)
            ->exists();
    }

    /**
     * Cek apakah childPartId merupakan step perantara di mesin TPL yang sama.
     */
    private function isIntermediateTplChild(WorkOrder $workOrder, int $tplMachineId, int $childPartId): bool
    {
        return $workOrder->items()
            ->where('machine_id', $tplMachineId)
            ->where('parent_part_id', $childPartId)
            ->exists();
    }

    /**
     * Konsumsi raw material di stasiun awal mesin TPL (Tandem Press Line) secara langsung.
     */
    private function consumeTplRootInputs(WorkOrder $workOrder, WorkOrderItem $finalItem, Machine $machine, int $tplMachineId, ProductionResult $result, BigDecimal $totalQty): void
    {
        $inputs = $this->resolveTplInputs($workOrder, $finalItem, $tplMachineId, $totalQty);

        foreach ($inputs as $input) {
            $rootItem = $input['item'];
            $need = $input['need'];

            if ($need->isLessThanOrEqualTo(0)) {
                continue;
            }

            $receiptEntries = $this->consumableMachineReceipts(
                $workOrder,
                $rootItem,
                (int) $machine->id,
                UomCatalog::normalize((string) $rootItem->uom_rm),
            );

            $available = BigDecimal::zero();
            foreach ($receiptEntries as $entry) {
                $receipt = $entry['receipt'];
                $receipt->assertValidBalance();
                $balance = BigDecimal::of($receipt->getRawOriginal('qty'))->minus($receipt->getRawOriginal('qty_consumed'));
                $usable = $entry['limit'] !== null && $entry['limit']->isLessThan($balance) ? $entry['limit'] : $balance;
                if ($usable->isGreaterThan(0)) {
                    $available = $available->plus($usable);
                }
            }

            if ($available->isLessThan($need)) {
                $matNumber = $rootItem->childPart?->part_number ?? $rootItem->child_part_name ?? 'material';
                $uomStr = UomCatalog::normalize((string) $rootItem->uom_rm) ?? $rootItem->uom_rm ?? '';
                $needStr = rtrim(rtrim((string) $need, '0'), '.');
                $availStr = rtrim(rtrim((string) $available, '0'), '.');
                throw ValidationException::withMessages([
                    'qty_good' => __('Material :part belum diterima di mesin ini. Butuh :need :uom (di mesin baru ada :avail :uom). Silakan terima material terlebih dahulu di menu Terima Material.', [
                        'part' => $matNumber,
                        'need' => $needStr,
                        'avail' => $availStr,
                        'uom' => $uomStr,
                    ]),
                ]);
            }

            $remaining = $need;
            foreach ($receiptEntries as $entry) {
                if ($remaining->isZero()) {
                    break;
                }
                $receipt = $entry['receipt'];
                $balance = BigDecimal::of($receipt->getRawOriginal('qty'))->minus($receipt->getRawOriginal('qty_consumed'));
                $usable = $entry['limit'] !== null && $entry['limit']->isLessThan($balance) ? $entry['limit'] : $balance;
                if ($usable->isLessThanOrEqualTo(0)) {
                    continue;
                }
                $take = $remaining->isLessThan($usable) ? $remaining : $usable;
                if ($take->isZero()) {
                    continue;
                }
                $receipt->increment('qty_consumed', (string) $take);
                WorkOrderConsumption::create([
                    'production_result_id' => $result->id,
                    'production_material_receipt_id' => $receipt->id,
                    'work_order_id' => $workOrder->id,
                    'work_order_item_id' => $rootItem->id,
                    'part_stock_id' => $receipt->part_stock_id,
                    'part_id' => $receipt->part_id,
                    'qty' => (string) $take,
                    'uom' => $receipt->uom,
                    // Simpan asal bahan supaya FG bisa ditelusuri ke notanya.
                    'invoice_no' => $receipt->invoice,
                    'supplier_name' => $receipt->supplier,
                    'source_tag' => $receipt->tag,
                ]);
                $remaining = $remaining->minus($take);
            }

            $rootItem->increment('qty_consumed', (string) $need);
        }
    }

    /**
     * Cari baris-baris input awal untuk step di mesin TPL (Tandem Press Line).
     *
     * @return list<array{item: WorkOrderItem, need: BigDecimal}>
     */
    private function resolveTplInputs(WorkOrder $workOrder, WorkOrderItem $finalItem, int $tplMachineId, BigDecimal $totalQty): array
    {
        $tplItems = $workOrder->items()
            ->where('machine_id', $tplMachineId)
            ->with(['parentPart', 'childPart'])
            ->get()
            ->keyBy('parent_part_id');

        $inputs = [];
        $visited = [];

        $trace = function (WorkOrderItem $item, BigDecimal $ratio) use (&$trace, &$inputs, &$visited, $tplItems, $totalQty) {
            $childId = $item->child_part_id;
            if ($childId === null) {
                return;
            }

            $itemQty = $this->ratioDecimal($item);
            $currentRatio = $ratio->multipliedBy($itemQty);

            // Jika child_part_id merupakan parent step lain di mesin TPL yang sama, telusuri ke hulu
            if ($tplItems->has($childId) && ! in_array($childId, $visited, true)) {
                $visited[] = $childId;
                $upstreamItem = $tplItems->get($childId);
                $trace($upstreamItem, $currentRatio);
            } else {
                // Sampai di input awal (Raw Material) di stasiun 1 TPL
                $inputs[] = [
                    'item' => $item,
                    'need' => $currentRatio->multipliedBy($totalQty)->toScale(10, RoundingMode::HALF_UP),
                ];
            }
        };

        $trace($finalItem, BigDecimal::one());

        return $inputs;
    }

    /**
     * Ambil daftar penerimaan material yang boleh dikonsumsi oleh WO ini:
     * 1. Milik WO sendiri di mesin ini (FIFO, tanpa batas surplus).
     * 2. Hanya SURPLUS milik WO lain (mesin sama dulu, lalu lintas mesin):
     *    - Bila WO lain berstatus completed/cancelled -> seluruh sisa balance.
     *    - Bila WO lain masih aktif -> hanya kelebihan di atas sisa kebutuhannya
     *      (`total_balance_grup - max(0, otherItem->qty_required - otherItem->qty_consumed)`),
     *      sehingga hak material WO lain yang belum dipakai tidak pernah terambil.
     *
     * @return Collection<int, array{receipt: ProductionMaterialReceipt, limit: BigDecimal|null}>
     */
    private function consumableMachineReceipts(WorkOrder $workOrder, WorkOrderItem $item, int $machineId, ?string $uom): Collection
    {
        $ownReceipts = ProductionMaterialReceipt::query()
            ->where('work_order_id', $workOrder->id)
            ->where('work_order_item_id', $item->id)
            ->where('machine_id', $machineId)
            ->where('transfer_status', 'transferred')
            ->when($uom !== null, fn ($q) => $q->where('uom', $uom))
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(fn (ProductionMaterialReceipt $r): array => ['receipt' => $r, 'limit' => null]);

        $item->loadMissing('allocations');
        $allowedPartIds = app(WoService::class)->allowedPartIdsForItem($item);

        if ($allowedPartIds === []) {
            return $ownReceipts;
        }

        $sharedCandidates = ProductionMaterialReceipt::query()
            ->with(['workOrder:id,status', 'workOrderItem:id,qty_required,qty_consumed'])
            ->where('work_order_id', '!=', $workOrder->id)
            ->where('transfer_status', 'transferred')
            ->whereIn('part_id', $allowedPartIds)
            ->when($uom !== null, fn ($q) => $q->where('uom', $uom))
            ->whereColumn('qty', '>', 'qty_consumed')
            ->orderByRaw('CASE WHEN machine_id = ? THEN 0 ELSE 1 END', [$machineId])
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($sharedCandidates->isEmpty()) {
            return $ownReceipts;
        }

        $groupSurplus = [];
        foreach ($sharedCandidates->groupBy(fn (ProductionMaterialReceipt $r): string => $r->work_order_id.'|'.($r->work_order_item_id ?? 0)) as $key => $group) {
            $first = $group->first();
            $totalBalance = BigDecimal::zero();
            foreach ($group as $r) {
                $totalBalance = $totalBalance->plus(BigDecimal::of($r->getRawOriginal('qty'))->minus($r->getRawOriginal('qty_consumed')));
            }

            $otherStatus = $first?->workOrder?->status;
            $otherItem = $first?->workOrderItem;

            if (in_array($otherStatus, ['completed', 'cancelled'], true) || $otherItem === null) {
                $groupSurplus[$key] = $totalBalance;

                continue;
            }

            $otherNeed = BigDecimal::of($otherItem->getRawOriginal('qty_required') ?? 0)
                ->minus(BigDecimal::of($otherItem->getRawOriginal('qty_consumed') ?? 0));
            if ($otherNeed->isLessThan(0)) {
                $otherNeed = BigDecimal::zero();
            }

            $surplus = $totalBalance->minus($otherNeed);
            $groupSurplus[$key] = $surplus->isGreaterThan(0) ? $surplus : BigDecimal::zero();
        }

        $sharedEntries = collect();
        foreach ($sharedCandidates as $candidate) {
            $key = $candidate->work_order_id.'|'.($candidate->work_order_item_id ?? 0);
            $remainingSurplus = $groupSurplus[$key] ?? BigDecimal::zero();
            if ($remainingSurplus->isLessThanOrEqualTo(0)) {
                continue;
            }
            $balance = BigDecimal::of($candidate->getRawOriginal('qty'))->minus($candidate->getRawOriginal('qty_consumed'));
            if ($balance->isLessThanOrEqualTo(0)) {
                continue;
            }
            $cap = $balance->isLessThan($remainingSurplus) ? $balance : $remainingSurplus;
            $groupSurplus[$key] = $remainingSurplus->minus($cap);
            $sharedEntries->push(['receipt' => $candidate, 'limit' => $cap]);
        }

        return $ownReceipts->concat($sharedEntries);
    }

    private function ratioDecimal(WorkOrderItem $item): BigDecimal
    {
        return BigDecimal::of($item->getRawOriginal('child_qty') ?? 0);
    }
}
