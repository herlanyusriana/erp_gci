<?php

namespace App\Services;

use App\Events\MaterialIssuePosted;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\ConfigMaster;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PartSubstitute;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanHistory;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Support\DocumentNumber;
use App\Support\MachineRouting;
use App\Support\UomCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WoService
{
    public function __construct(protected ReceiveMaterialService $stockService) {}

    /**
     * Get the active BOM for a FG part, or null.
     */
    public function activeBomFor(int $partId): ?Bom
    {
        return Bom::query()
            ->where('part_id', $partId)
            ->where('is_active', true)
            ->with(['part', 'items.parentPart', 'items.childPart'])
            ->first();
    }

    private function childRatio(object $it): float
    {
        return (float) ($it->child_qty ?? 0);
    }

    /**
     * Build demand map key=partKey => total qty yang dibutuhkan (dikonsumsi).
     * Berlaku untuk bom_items (create) maupun work_order_items snapshot (release).
     */
    public function buildRequirements(iterable $items, float $qty, string $fgPartNumber): array
    {
        $parentsOf = [];
        $sharedGroups = [];
        $allParentKeys = [];
        $consumedAsChild = [];
        foreach ($items as $it) {
            $outputType = $it->output_type ?? BomItem::OUTPUT_NORMAL;
            if ($outputType === BomItem::OUTPUT_BYPRODUCT) {
                continue;
            }
            $childKey = $this->childKeyOf($it);
            $parentKey = $this->parentKeyOf($it);
            if ($childKey === '' || $parentKey === '') {
                continue;
            }
            $allParentKeys[$parentKey] = true;
            $consumedAsChild[$childKey] = true;
            if ($outputType === BomItem::OUTPUT_SHARED) {
                $groupKey = ($it->sequence ?? 0).'|'.$childKey;
                $sharedGroups[$childKey][$groupKey][] = [$parentKey, $this->childRatio($it)];

                continue;
            }
            $parentsOf[$childKey][] = [$parentKey, $this->childRatio($it)];
        }

        $memo = [];

        $req = function (string $partKey) use (&$req, &$memo, $parentsOf, $sharedGroups, $allParentKeys, $consumedAsChild, $fgPartNumber, $qty) {
            if (array_key_exists($partKey, $memo)) {
                return $memo[$partKey];
            }
            if ($partKey === $fgPartNumber || (isset($allParentKeys[$partKey]) && ! isset($consumedAsChild[$partKey]))) {
                $memo[$partKey] = $qty;

                return $qty;
            }

            $sum = 0.0;
            foreach ($parentsOf[$partKey] ?? [] as [$parentKey, $childQty]) {
                $sum += $req($parentKey) * $childQty;
            }
            foreach ($sharedGroups[$partKey] ?? [] as $entries) {
                $groupMax = 0.0;
                foreach ($entries as [$parentKey, $childQty]) {
                    $groupMax = max($groupMax, $req($parentKey) * $childQty);
                }
                $sum += $groupMax;
            }
            $memo[$partKey] = $sum;

            return $sum;
        };

        foreach ($items as $it) {
            $key = $this->childKeyOf($it);
            if ($key !== '') {
                $req($key);
            }
        }

        return $memo;
    }

    /**
     * Masukkan WO ke papan Production Plan — dipanggil saat RELEASE, bukan saat
     * WO masih `planned`. Dilewati bila barisnya sudah ada (ditempel manual).
     */
    private function enterProductionPlan(WorkOrder $workOrder, int $actorId): void
    {
        if ($workOrder->planItems()->exists()) {
            return;
        }

        $today = now()->toDateString();
        $plannedDate = $workOrder->planned_date?->toDateString();
        $planDate = ($plannedDate !== null && $plannedDate < $today) ? $plannedDate : $today;

        $this->populatePlanItems($workOrder, $planDate, $actorId);
    }

    /**
     * Isi Production Plan dengan step WO: step yang berurutan di mesin yang sama
     * digabung jadi SATU baris (satu operasi mesin).
     *
     * - `input_part_id`  = child step pertama grup (material yang masuk mesin)
     * - `wip_part_id`    = parent step terakhir grup (hasil yang keluar mesin)
     *
     * `target_d` sengaja dikosongkan: qty WO dibagi manual ke D/D1/D2 oleh
     * planner, sehingga kolom "Sisa Jumlah WO" mulai dari qty WO.
     *
     * Step = pasangan (sequence, parent_part_id) non-Subcon; beberapa baris BOM
     * dengan parent sama (mis. WIP + material free issue) digabung jadi satu step.
     *
     * @return int jumlah baris yang dibuat
     */
    public function populatePlanItems(WorkOrder $workOrder, string $planDate, int $actorId): int
    {
        $workOrder->loadMissing(['part', 'items.machine', 'items.parentPart', 'items.childPart']);

        $items = $this->sorted($workOrder->items);

        // Step unik (sequence + parent), urut sesuai BOM.
        $steps = [];
        $seenShared = [];
        foreach ($items as $item) {
            $outputType = $item->output_type ?? BomItem::OUTPUT_NORMAL;
            if ($item->parent_part_id === null || strtoupper((string) $item->source) === 'SUBCON' || $outputType === BomItem::OUTPUT_BYPRODUCT) {
                continue;
            }
            if ($outputType === BomItem::OUTPUT_SHARED && $item->child_part_id !== null) {
                $sharedKey = ($item->sequence ?? 0).'|'.$item->child_part_id;
                if (isset($seenShared[$sharedKey])) {
                    continue;
                }
                $seenShared[$sharedKey] = true;
            }
            $stepKey = ($item->sequence ?? 0).'|'.$item->parent_part_id;
            $steps[$stepKey] ??= $item;
        }

        // Gabung step berurutan yang grup mesinnya sama (3 karakter pertama nama mesin).
        $groups = [];
        foreach ($steps as $step) {
            $key = $this->machineGroupKey($step);
            $lastIndex = count($groups) - 1;
            if ($lastIndex >= 0 && $groups[$lastIndex]['key'] === $key) {
                $groups[$lastIndex]['steps'][] = $step;

                continue;
            }
            $groups[] = ['key' => $key, 'machine_id' => $step->machine_id, 'steps' => [$step]];
        }

        if ($groups === []) {
            return 0;
        }

        $plan = ProductionPlan::firstOrCreate(
            ['plan_date' => $planDate],
            ['created_by' => $actorId],
        );

        $sequenceByMachine = [];
        $created = 0;

        foreach ($groups as $group) {
            $first = $group['steps'][0];
            $last = $group['steps'][count($group['steps']) - 1];

            $machineKey = $group['key'];
            $sequence = ($sequenceByMachine[$machineKey] ?? 0) + 1;
            $sequenceByMachine[$machineKey] = $sequence;

            $plan->items()->create([
                'machine_id' => $group['machine_id'],
                'process_id' => $first->process_id,
                'work_order_id' => $workOrder->id,
                'fg_part_id' => $workOrder->part_id,
                'input_part_id' => $first->child_part_id,
                'wip_part_id' => $last->parent_part_id,
                'sequence' => $sequence,
                'step_sequence' => $first->sequence,
                'target_d' => null,
                'created_by' => $actorId,
            ]);

            $created++;
        }

        ProductionPlanHistory::create([
            'production_plan_id' => $plan->id,
            'event' => 'work_order_added',
            'after' => ['work_order_id' => $workOrder->id, 'wo_no' => $workOrder->wo_no, 'rows' => $created],
            'user_id' => $actorId,
        ]);

        return $created;
    }

    /**
     * Create WO (draft) + snapshot items + hitung qty_required + warning stok.
     * Return [workOrderId, warnings].
     */
    public function createWorkOrder(int $partId, float $qty, ?string $plannedDate, ?string $remarks, int $creatorId): array
    {
        $bom = $this->activeBomFor($partId);
        abort_unless($bom !== null, 422, __('Belum ada BOM aktif untuk part ini.'));

        $fgKey = $bom->part?->part_number ?? (string) $bom->part_id;
        $requirements = $this->buildRequirements($bom->items, $qty, $fgKey);

        $warnings = [];

        // Parent yang di-post internal = parent of non-Subcon rows (sama dgn release).
        // Ini menentukan material mana yang "leaf" (butuh stok lama), sehingga warning
        // tidak menyinggung WIP yang diproduksi sendiri di dalam WO.
        $postedParentIds = [];
        foreach ($bom->items as $it) {
            if (strtoupper((string) $it->source) !== 'SUBCON' && $it->parent_part_id !== null) {
                $postedParentIds[$it->parent_part_id] = true;
            }
        }

        // Aggregasi kebutuhan leaf per (child_part_id, uom).
        $leafNeeds = [];
        $seenSharedLeaf = [];
        foreach ($bom->items as $it) {
            $outputType = $it->output_type ?? BomItem::OUTPUT_NORMAL;
            if ($outputType === BomItem::OUTPUT_BYPRODUCT) {
                continue;
            }
            $cid = $it->child_part_id;
            if ($cid === null || isset($postedParentIds[$cid])) {
                continue;
            }
            if ($outputType === BomItem::OUTPUT_SHARED) {
                $sharedKey = ($it->sequence ?? 0).'|'.$cid;
                if (isset($seenSharedLeaf[$sharedKey])) {
                    continue;
                }
                $seenSharedLeaf[$sharedKey] = true;
            }
            $parentKey = $this->parentKeyOf($it);
            $ratio = $this->childRatio($it);
            $qtyRequired = ($parentKey !== '' && isset($requirements[$parentKey]))
                ? round($requirements[$parentKey] * $ratio, 6)
                : 0.0;
            if ($qtyRequired <= 0) {
                continue;
            }
            $uom = UomCatalog::normalize((string) $it->uom_rm);
            $key = $cid.'|'.($uom ?? '');
            if (! isset($leafNeeds[$key])) {
                $leafNeeds[$key] = ['id' => $cid, 'uom' => $uom, 'name' => $it->child_part_name, 'no' => $it->childPart?->part_number ?? '', 'need' => 0.0];
            }
            $leafNeeds[$key]['need'] += $qtyRequired;
        }

        $createWorkOrder = function () use ($bom, $qty, $plannedDate, $remarks, $creatorId, $requirements, &$warnings, $leafNeeds) {
            // Percobaan ulang harus mulai dari daftar warning yang bersih.
            $warnings = [];

            /** @var WorkOrder $workOrder */
            $workOrder = WorkOrder::create([
                'wo_no' => WorkOrder::generateWoNo(),
                'part_id' => $bom->part_id,
                'qty' => $qty,
                'status' => 'planned',
                'planned_date' => $plannedDate,
                'remarks' => $remarks,
                'created_by' => $creatorId,
            ]);

            $seenSharedItem = [];
            foreach ($bom->items as $it) {
                $outputType = $it->output_type ?? BomItem::OUTPUT_NORMAL;
                $parentKey = $this->parentKeyOf($it);
                $ratio = $this->childRatio($it);
                $qtyRequired = ($outputType !== BomItem::OUTPUT_BYPRODUCT && $parentKey !== '' && isset($requirements[$parentKey]))
                    ? round($requirements[$parentKey] * $ratio, 6)
                    : 0.0;

                if ($outputType === BomItem::OUTPUT_SHARED && $it->child_part_id !== null) {
                    $sharedKey = ($it->sequence ?? 0).'|'.$it->child_part_id;
                    if (isset($seenSharedItem[$sharedKey])) {
                        $qtyRequired = 0.0;
                    } else {
                        $seenSharedItem[$sharedKey] = true;
                    }
                }

                WorkOrderItem::create([
                    'work_order_id' => $workOrder->id,
                    'sequence' => $it->sequence,
                    'process_id' => $it->process_id,
                    'machine_id' => $it->machine_id,
                    'parent_part_id' => $it->parent_part_id,
                    'parent_part_name' => $it->parent_part_name,
                    'parent_qty' => $it->parent_qty,
                    'parent_uom' => $it->parent_uom,
                    'child_part_id' => $it->child_part_id,
                    'selected_part_id' => $it->child_part_id,
                    'child_part_name' => $it->child_part_name,
                    'size' => $it->size,
                    'child_qty' => $it->child_qty,
                    'uom_rm' => $it->uom_rm,
                    'special_code' => $it->special_code,
                    'source' => $it->source,
                    'output_type' => $outputType,
                    'qty_required' => $qtyRequired,
                    'qty_consumed' => 0,
                    'created_by' => $creatorId,
                ]);
            }

            // Warning stok hanya untuk material leaf (tidak diproduksi internal).
            foreach ($leafNeeds as $leaf) {
                $avail = $this->stockService->availableFifo($leaf['id'], $leaf['uom']);
                if ($avail + 1e-9 < $leaf['need']) {
                    $short = $leaf['need'] - $avail;
                    $warnings[] = __(':name (:number) kurang :short :unit (butuh :needed, stok :available)', [
                        'name' => $leaf['name'] ?? ('#'.$leaf['id']),
                        'number' => $leaf['no'],
                        'short' => sprintf('%.4f', $short),
                        'unit' => UomCatalog::normalize((string) $leaf['uom']) ?? '',
                        'needed' => sprintf('%.4f', $leaf['need']),
                        'available' => sprintf('%.4f', $avail),
                    ]);
                }
            }

            return $workOrder;
        };

        $workOrder = DocumentNumber::retry(
            fn () => DB::transaction($createWorkOrder),
            'work_orders_wo_no_unique',
        );

        $warnings = array_values(array_unique($warnings));

        return [$workOrder->id, $warnings];
    }

    /**
     * Release WO. Model: BOM linear (semua parent_qty=1) → produksi dibatasi oleh
     * material LEAF paling ketat. "Leaf" = child yang TIDAK diproduksi (di-post)
     * oleh WO ini: raw material Vendor/FREE_ISSUE, RM inline (source=Prod tapi
     * child bukan WIP internal), dan WIP balikan subkon (output source=Subcon tidak
     * di-post, jadi dibutuhkan dari stok terima sebelumnya).
     *
     * 1. Rasio layak r = min(1, min(avail_leaf / need_leaf)), need di-agregasi per
     *    (child_part_id, uom) agar baris duplikat / leaf bersama tidak double-count.
     * 2. Proses item urut sequence (bottom-up): consume child FIFO r×need, lalu post
     *    output parent (non-Subcon) r×demand — SEKALI per parent (join rows dedupe).
     * 3. Stok leaf kurang → WO tetap release (r < 1), catat shortage material pengikat.
     *
     * Return [workOrder, shortages].
     */
    public function releaseWorkOrder(WorkOrder $workOrder, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($workOrder, $actorId) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            abort_if($workOrder->status !== 'planned', 422, __('WO hanya bisa di-release dari status planned.'));

            $items = $workOrder->items()->with(['parentPart', 'childPart', 'allocations.part'])->get();
            $workOrder->load('part');

            // Parent yang diproduksi internal di WO ini (WIP). Output-nya TIDAK
            // di-post saat release — diproduksi lewat Production Result per step.
            // source=Subcon juga tidak di-post (output balik via Receive manual).
            $postedParentIds = [];
            foreach ($items as $it) {
                if (strtoupper((string) $it->source) !== 'SUBCON' && $it->parent_part_id !== null) {
                    $postedParentIds[$it->parent_part_id] = true;
                }
            }

            // Material yang DI-ISSUE saat release: hanya RM (child bukan WIP internal).
            // WIP dikonsumsi nanti saat step-nya dilaporkan lewat Production Result.
            // RM di-BOOKING saat release; stok fisik baru berkurang saat Production Result.
            $sources = [];
            foreach ($items as $it) {
                $planned = [];
                foreach ($it->allocations as $a) {
                    $partId = (int) $a->part_id;
                    if (isset($postedParentIds[$partId])) {
                        continue;
                    }
                    $planned[$partId] = ($planned[$partId] ?? 0.0) + (float) $a->qty;
                }

                if ($planned === []) {
                    $fallbackId = $it->selected_part_id ?: $it->child_part_id;
                    if ($fallbackId !== null && ! isset($postedParentIds[$fallbackId])) {
                        $planned[(int) $fallbackId] = (float) $it->qty_required;
                    }
                }

                $sources[$it->id] = $planned;
            }

            // Leaf constraints: child yang tidak diproduksi internal → butuh stok lama.
            // Aggregasi per (part_id, uom); dengan alokasi, need = qty alokasi.
            $leafNeeds = [];
            $leafPartIds = [];
            foreach ($items as $it) {
                if ((float) $it->qty_required <= 0) {
                    continue;
                }
                foreach ($sources[$it->id] as $partId => $plannedQty) {
                    if ($plannedQty <= 0 || isset($postedParentIds[$partId])) {
                        continue; // diproduksi dalam WO ini
                    }
                    $leafPartIds[$partId] = true;
                    $key = $partId.'|'.(UomCatalog::normalize((string) $it->uom_rm) ?? '');
                    if (! isset($leafNeeds[$key])) {
                        $leafNeeds[$key] = [
                            'part_id' => $partId,
                            'uom' => UomCatalog::normalize((string) $it->uom_rm),
                            'name' => $it->child_part_name,
                            'need' => 0.0,
                        ];
                    }
                    $leafNeeds[$key]['need'] += $plannedQty;
                }
            }

            // Nama + nomor part untuk pelaporan shortage, satu query untuk semua.
            $leafParts = Part::query()
                ->whereIn('id', array_keys($leafPartIds))
                ->get(['id', 'part_number', 'part_name'])
                ->keyBy('id');
            foreach ($leafNeeds as $key => $leaf) {
                $part = $leafParts[$leaf['part_id']] ?? null;
                $leafNeeds[$key]['part_no'] = $part?->part_number ?? '';
                $leafNeeds[$key]['name'] = $part?->part_name ?? $leaf['name'];
            }

            // Rasio layak global: minimum dari kecukupan tiap leaf dan kelengkapan
            // alokasi tiap item (alokasi sebagian menurunkan output).
            $ratio = 1.0;
            foreach ($leafNeeds as $key => $leaf) {
                $avail = $this->stockService->availableFifo($leaf['part_id'], $leaf['uom']);
                $leafNeeds[$key]['avail'] = $avail;
                if ($leaf['need'] > 0) {
                    $ratio = min($ratio, $avail / $leaf['need']);
                }
            }
            foreach ($items as $it) {
                $required = (float) $it->qty_required;
                if ($required <= 0) {
                    continue;
                }
                $planned = $sources[$it->id] ?? [];
                if ($planned === []) {
                    continue; // tanpa sumber (mis. child_part null) → jangan paksa ratio 0
                }
                $ratio = min($ratio, array_sum($planned) / $required);
            }
            $ratio = max(0.0, min(1.0, $ratio));

            $shortages = [];

            $workOrder = DB::transaction(function () use ($workOrder, $items, $ratio, $actorId, $sources, &$shortages) {
                $releasedAt = now();

                foreach ($this->sorted($items) as $it) {
                    $need = (float) $it->qty_required;
                    $target = round($need * $ratio, 4);

                    // BOOKING tiap sumber (RM) sesuai porsi alokasinya, FIFO per part.
                    // Stok fisik TIDAK dikurangi di sini — baru saat Production Result.
                    $plannedTotal = array_sum($sources[$it->id] ?? []);
                    if ($target > 0 && $plannedTotal > 0) {
                        $scale = $target / $plannedTotal;

                        foreach ($sources[$it->id] as $partId => $plannedQty) {
                            $takeFromPart = round($plannedQty * $scale, 4);
                            if ($takeFromPart <= 0) {
                                continue;
                            }

                            $this->stockService->bookFifoByUom($partId, $takeFromPart, $it->uom_rm, $workOrder->id, $it->id, $actorId);
                        }
                    }
                }

                $workOrder->update([
                    'status' => 'in_progress',
                    'released_at' => $releasedAt,
                    'updated_by' => $actorId,
                ]);

                return $workOrder->fresh(['items', 'part']);
            });

            // Shortage report: material pengikat (binding) bila r < 1, plus sisa
            // yang belum dialokasikan pada tiap item.
            $eps = 1e-6;
            if ($ratio < 1 - $eps) {
                foreach ($leafNeeds as $leaf) {
                    if ($leaf['need'] <= 0) {
                        continue;
                    }
                    $rowRatio = $leaf['avail'] / $leaf['need'];
                    if ($rowRatio > $ratio + $eps) {
                        continue; // bukan constraint pengikat
                    }
                    $shortages[] = [
                        'child_part_name' => $leaf['name'],
                        'child_part_no' => $leaf['part_no'],
                        'uom' => $leaf['uom'],
                        'required' => round($leaf['need'], 4),
                        'available' => round($leaf['avail'], 4),
                        'consumed' => round($leaf['avail'], 4),
                        'short' => round($leaf['need'] - $leaf['avail'], 4),
                    ];
                }
            }

            // Sisa kebutuhan yang tidak dialokasikan ke part mana pun.
            foreach ($items as $it) {
                $required = (float) $it->qty_required;
                if ($required <= 0) {
                    continue;
                }
                $plannedTotal = array_sum($sources[$it->id] ?? []);
                if ($plannedTotal + $eps >= $required) {
                    continue;
                }
                $shortages[] = [
                    'child_part_name' => $it->child_part_name,
                    'child_part_no' => $it->childPart?->part_number ?? '',
                    'uom' => UomCatalog::normalize((string) $it->uom_rm),
                    'required' => round($required, 4),
                    'available' => round($plannedTotal, 4),
                    'consumed' => round($plannedTotal, 4),
                    'short' => round($required - $plannedTotal, 4),
                ];
            }

            return [$workOrder, $shortages];
        });
    }

    /**
     * Release WO dari scan label (mobile "issue out to production").
     *
     * Konsumsi tag stok yang di-scan (spesifik, bukan FIFO), catat dokumen
     * material issue, lalu posting output WIP/FG seperti release biasa.
     *
     * @param  array<int, array{work_order_item_id:int, scans: array<int, array{tag:string, qty:float, part_id?:int|null}>}>  $itemScans
     * @param  array{issue_date?:string|null, received_by?:string|null, location_code?:string|null, idempotency_key?:string|null, notes?:string|null}  $meta
     * @return array{0: WorkOrder, 1: array<int, array<string, mixed>>, 2: MaterialIssue}
     */
    public function releaseWithScans(WorkOrder $workOrder, array $itemScans, array $meta, ?int $actorId = null): array
    {
        $posted = false;
        $result = DB::transaction(function () use ($workOrder, $itemScans, $meta, $actorId, &$posted) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $idempotencyKey = $meta['idempotency_key'] ?? null;
            if ($idempotencyKey !== null) {
                $existing = MaterialIssue::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    abort_unless($existing->work_order_id === $workOrder->id, 422, __('Item WO tidak dikenal.'));

                    return [$workOrder->fresh(['items', 'part']), [], $existing];
                }
            }

            abort_if(in_array($workOrder->status, ['completed', 'cancelled'], true), 422, __('WO tidak bisa di-issue dalam status saat ini.'));

            $items = $workOrder->items()->with(['parentPart', 'childPart', 'allocations'])->lockForUpdate()->get();
            $workOrder->load('part');

            $postedParentIds = [];
            foreach ($items as $it) {
                if (strtoupper((string) $it->source) !== 'SUBCON' && $it->parent_part_id !== null) {
                    $postedParentIds[$it->parent_part_id] = true;
                }
            }

            $itemsById = $items->keyBy('id');
            $issuedByItem = MaterialIssueItem::query()->whereHas('materialIssue', fn ($query) => $query
                ->where('work_order_id', $workOrder->id)->where('status', 'posted'))
                ->selectRaw('work_order_item_id, SUM(qty) AS issued')->groupBy('work_order_item_id')->pluck('issued', 'work_order_item_id');

            // Semua item leaf sudah terpenuhi: hentikan di level WO supaya APK
            // bisa memberi pesan jelas, bukan gagal per item tanpa konteks.
            $leafItems = $items->reject(fn ($it) => $it->child_part_id !== null && isset($postedParentIds[$it->child_part_id]));
            $allCovered = $leafItems->isNotEmpty() && $leafItems->every(
                fn ($it) => (float) ($issuedByItem[$it->id] ?? 0) + 1e-9 >= (float) $it->qty_required,
            );

            if ($allCovered) {
                $issueNumbers = MaterialIssue::query()
                    ->where('work_order_id', $workOrder->id)
                    ->where('status', 'posted')
                    ->orderBy('id')
                    ->pluck('issue_no')
                    ->implode(', ');

                throw ValidationException::withMessages([
                    'items' => __('Semua material WO ini sudah di-issue (:issues).', ['issues' => $issueNumbers]),
                ]);
            }

            // Normalisasi + validasi scan per item.
            $scansByItem = [];
            foreach ($itemScans as $row) {
                $itemId = (int) ($row['work_order_item_id'] ?? 0);
                /** @var WorkOrderItem|null $item */
                $item = $itemsById->get($itemId);
                if ($item === null) {
                    throw ValidationException::withMessages(['items' => __('Item WO tidak dikenal.')]);
                }

                $allowed = $this->allowedPartIdsForItem($item);
                $scans = [];
                $total = 0.0;
                foreach (($row['scans'] ?? []) as $i => $scan) {
                    $tag = trim((string) ($scan['tag'] ?? ''));
                    if ($tag === '') {
                        continue;
                    }
                    $partId = isset($scan['part_id']) && $scan['part_id'] !== null ? (int) $scan['part_id'] : null;
                    if ($partId !== null && ! in_array($partId, $allowed, true)) {
                        throw ValidationException::withMessages([
                            "items.$itemId.scans.$i.tag" => __('Tag tidak sesuai material item ini.'),
                        ]);
                    }
                    $qty = (float) ($scan['qty'] ?? 0);
                    if ($qty <= 0) {
                        throw ValidationException::withMessages([
                            "items.$itemId.scans.$i.qty" => __('Qty scan harus lebih dari 0.'),
                        ]);
                    }
                    $packingNos = array_values(array_unique(array_filter(
                        array_map(fn ($p): string => trim((string) $p), (array) ($scan['packing_nos'] ?? [])),
                        fn (string $p): bool => $p !== '',
                    )));
                    $total += $qty;
                    $scans[] = ['tag' => $tag, 'part_id' => $partId, 'qty' => $qty, 'packing_nos' => $packingNos];
                }

                $qtyRequired = (float) $item->qty_required;
                $alreadyIssued = (float) ($issuedByItem[$itemId] ?? 0);
                $required = max(0, $qtyRequired - $alreadyIssued);

                // Satu kemasan fisik tidak bisa dipisah: tag boleh keluar penuh
                // walau melebihi sisa. Batas kumulatif 2x kebutuhan menahan
                // salah scan tanpa mengubah aturan booking.
                if ($alreadyIssued > 1e-9 && $required <= 1e-9) {
                    throw ValidationException::withMessages([
                        "items.$itemId" => __('Kebutuhan item ini sudah terpenuhi.'),
                    ]);
                }

                // Tanpa penanda `bundle_plan` klien lama tetap memakai batas 2x
                // supaya kemasan utuh masih bisa keluar. Dengan penanda `bundle_plan`:
                // - bila memilih kemasan fisik utuh (`packing_nos`), izinkan kelebihan
                //   maksimal 1 kemasan utuh (tanpa menumpuk kemasan ekstra yang tidak perlu);
                // - selain itu batas tegas: total issue tidak boleh melebihi kebutuhan bersih.
                $enforcesNet = ! empty($row['bundle_plan']);
                $netCap = $enforcesNet
                    ? app(MaterialIssueBundleService::class)->netRequirement($item, $workOrder)['net_requirement']
                    : $required;
                $maxAllowed = $enforcesNet ? $netCap : $required + $qtyRequired;

                if ($enforcesNet && $total > $maxAllowed + 1e-9) {
                    $allHavePackings = $scans !== [] && collect($scans)->every(fn (array $s): bool => $s['packing_nos'] !== []);
                    $wholePackingAllowed = false;

                    if ($allHavePackings && $netCap > 1e-9) {
                        $bundleService = app(MaterialIssueBundleService::class);
                        $selectedPackingQtys = [];
                        foreach ($scans as $scanEntry) {
                            $stocks = PartStock::query()
                                ->whereRaw('LOWER(COALESCE(tag, \'\')) = ?', [mb_strtolower($scanEntry['tag'])])
                                ->when($scanEntry['part_id'] !== null, fn ($q) => $q->where('part_id', $scanEntry['part_id']))
                                ->whereNotNull('receive_id')
                                ->get(['id', 'receive_id']);

                            $matchedQtys = [];
                            foreach ($stocks as $st) {
                                $allPackings = DB::table('incoming_receive_packings')
                                    ->where('receive_id', $st->receive_id)
                                    ->get(['packing_no', 'qty']);
                                $caps = [];
                                foreach ($allPackings as $p) {
                                    $caps[strtoupper(trim((string) $p->packing_no))] = (float) $p->qty;
                                }
                                $usage = $bundleService->packingUsage((int) $st->id, $caps);
                                foreach ($scanEntry['packing_nos'] as $pNo) {
                                    $key = strtoupper(trim($pNo));
                                    if (! isset($caps[$key])) {
                                        continue;
                                    }
                                    $rem = max(0.0, $caps[$key] - (float) ($usage[$key]['used_qty'] ?? 0.0));
                                    if ($rem > 1e-6) {
                                        $matchedQtys[] = $rem;
                                    }
                                }
                                if (count($matchedQtys) === count($scanEntry['packing_nos'])) {
                                    break;
                                }
                                $matchedQtys = [];
                            }

                            if (count($matchedQtys) === count($scanEntry['packing_nos'])) {
                                array_push($selectedPackingQtys, ...$matchedQtys);
                            }
                        }

                        if ($selectedPackingQtys !== []) {
                            $sumMatched = array_sum($selectedPackingQtys);
                            $minPacking = min($selectedPackingQtys);
                            if (abs($total - $sumMatched) <= 0.05 && ($total - $minPacking) < $netCap - 1e-9) {
                                $wholePackingAllowed = true;
                            }
                        }
                    }

                    if (! $wholePackingAllowed) {
                        throw ValidationException::withMessages([
                            "items.$itemId" => __('Total issue (:total) melebihi kebutuhan bersih (:max).', [
                                'total' => round($total, 4),
                                'max' => round($maxAllowed, 4),
                            ]),
                        ]);
                    }
                } elseif (! $enforcesNet && $total > $maxAllowed + 1e-9) {
                    throw ValidationException::withMessages([
                        "items.$itemId" => __('Total issue (:total) melebihi kebutuhan bersih (:max).', [
                            'total' => round($total, 4),
                            'max' => round($maxAllowed, 4),
                        ]),
                    ]);
                }

                $scansByItem[$itemId] = $scans;
            }

            $shortages = [];

            $createIssue = function () use ($workOrder, $items, $scansByItem, $postedParentIds, $meta, $actorId, &$shortages) {
                // Percobaan ulang harus mulai dari daftar shortage yang bersih.
                $shortages = [];

                $releasedAt = now();
                $issueDate = isset($meta['issue_date']) && $meta['issue_date']
                    ? Carbon::parse($meta['issue_date'])->toDateString()
                    : now((string) ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta'))->toDateString();

                $operatorName = isset($meta['operator_name']) && trim((string) $meta['operator_name']) !== ''
                    ? trim((string) $meta['operator_name'])
                    : null;

                $issue = MaterialIssue::create([
                    'issue_no' => MaterialIssue::generateIssueNo(),
                    'work_order_id' => $workOrder->id,
                    'issue_date' => $issueDate,
                    'issued_by' => $actorId,
                    'operator_name' => $operatorName,
                    'received_by' => $meta['received_by'] ?? null,
                    'location_code' => $meta['location_code'] ?? null,
                    'status' => 'posted',
                    'idempotency_key' => $meta['idempotency_key'] ?? null,
                    'notes' => $meta['notes'] ?? null,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                foreach ($this->sorted($items) as $it) {
                    $required = (float) $it->qty_required;
                    $isInternal = $it->child_part_id !== null && isset($postedParentIds[$it->child_part_id]);
                    $taken = 0.0;

                    if (! $isInternal) {
                        // Leaf: BOOKING tag yang di-scan (spesifik). Stok fisik tetap;
                        // pengurangan terjadi saat Production Result.
                        foreach ($scansByItem[$it->id] ?? [] as $scan) {
                            $alloc = $this->stockService->bookFromTag($scan['tag'], $scan['part_id'], $scan['qty'], $workOrder->id, $it->id, $actorId, $it->uom_rm);
                            if ($alloc === null) {
                                throw ValidationException::withMessages([
                                    'items' => __('Tag :tag tidak ditemukan atau stok tidak cukup.', ['tag' => $scan['tag']]),
                                ]);
                            }
                            if (! in_array($alloc['part_id'], $this->allowedPartIdsForItem($it), true)) {
                                throw ValidationException::withMessages(['items' => __('Tag tidak sesuai material item ini.')]);
                            }
                            if (abs((float) $alloc['take_qty'] - (float) $scan['qty']) > 1e-6) {
                                throw ValidationException::withMessages([
                                    'items' => __('Stok tag :tag berubah. Silakan scan ulang.', ['tag' => $scan['tag']]),
                                ]);
                            }

                            $packingNos = $scan['packing_nos'] ?? [];
                            if ($packingNos !== []) {
                                $stockRow = PartStock::query()->find($alloc['part_stock_id'], ['id', 'receive_id']);
                                $caps = [];
                                if ($stockRow?->receive_id !== null) {
                                    $packingRows = DB::table('incoming_receive_packings')
                                        ->where('receive_id', $stockRow->receive_id)
                                        ->get(['packing_no', 'qty']);
                                    foreach ($packingRows as $pRow) {
                                        $caps[strtoupper(trim((string) $pRow->packing_no))] = (float) $pRow->qty;
                                    }
                                }
                                $usage = app(MaterialIssueBundleService::class)->packingUsage((int) $alloc['part_stock_id'], $caps);

                                $totalSelectedRemaining = 0.0;
                                $allKnownInCaps = true;
                                foreach ($packingNos as $pNo) {
                                    $key = strtoupper(trim($pNo));
                                    $usedQty = (float) ($usage[$key]['used_qty'] ?? 0.0);
                                    if (isset($caps[$key])) {
                                        $rem = max(0.0, $caps[$key] - $usedQty);
                                        if ($rem <= 1e-6) {
                                            throw ValidationException::withMessages([
                                                'items' => __('Packing :packing pada tag :tag sudah habis di-issue.', [
                                                    'packing' => $pNo,
                                                    'tag' => $alloc['tag'],
                                                ]),
                                            ]);
                                        }
                                        $totalSelectedRemaining += $rem;
                                    } else {
                                        $allKnownInCaps = false;
                                        if ($usedQty > 1e-6) {
                                            throw ValidationException::withMessages([
                                                'items' => __('Packing :packing pada tag :tag sudah pernah di-issue.', [
                                                    'packing' => $pNo,
                                                    'tag' => $alloc['tag'],
                                                ]),
                                            ]);
                                        }
                                    }
                                }

                                if ($allKnownInCaps && (float) $alloc['take_qty'] > $totalSelectedRemaining + 0.05) {
                                    throw ValidationException::withMessages([
                                        'items' => __('Qty issue (:qty) melebihi sisa kapasitas packing terpilih (:max).', [
                                            'qty' => round((float) $alloc['take_qty'], 4),
                                            'max' => round($totalSelectedRemaining, 4),
                                        ]),
                                    ]);
                                }
                            }

                            $taken += (float) $alloc['take_qty'];
                            MaterialIssueItem::create([
                                'material_issue_id' => $issue->id,
                                'work_order_item_id' => $it->id,
                                'part_id' => $alloc['part_id'],
                                'part_stock_id' => $alloc['part_stock_id'],
                                'tag' => $alloc['tag'],
                                'packing_nos' => $packingNos !== [] ? $packingNos : null,
                                'invoice' => $alloc['invoice'],
                                'supplier' => $alloc['supplier'],
                                'qty' => (float) $alloc['take_qty'],
                                'uom' => $alloc['uom'],
                                'price' => $alloc['price'],
                            ]);
                        }
                        if ($taken + 1e-9 < $required) {
                            $shortages[] = [
                                'child_part_name' => $it->child_part_name,
                                'child_part_no' => $it->childPart?->part_number ?? '',
                                'uom' => UomCatalog::normalize((string) $it->uom_rm),
                                'required' => round($required, 4),
                                'available' => round($taken, 4),
                                'consumed' => round($taken, 4),
                                'short' => round($required - $taken, 4),
                            ];
                        }
                    }
                }

                $workOrder->update([
                    'status' => 'in_progress',
                    'released_at' => $releasedAt,
                    'updated_by' => $actorId,
                ]);

                return $issue;
            };

            $issue = DocumentNumber::retry(
                fn () => DB::transaction($createIssue),
                'material_issues_issue_no_unique',
            );

            $posted = true;

            return [$workOrder->fresh(['items', 'part']), $shortages, $issue];
        });
        if ($posted) {
            event(new MaterialIssuePosted(MaterialIssuePosted::payloadFromIssue($result[2])));
        }

        return $result;
    }

    /**
     * Part yang sah untuk sebuah item WO: alokasi, main material, dan substitute aktif.
     *
     * @return list<int>
     */
    public function allowedPartIdsForItem(WorkOrderItem $item): array
    {
        $ids = $item->allocations->pluck('part_id')->map(fn ($id) => (int) $id)->all();
        foreach ([$item->selected_part_id, $item->child_part_id] as $id) {
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }
        if ($item->child_part_id !== null) {
            $subs = PartSubstitute::query()
                ->where('part_id', $item->child_part_id)
                ->where('is_active', true)
                ->pluck('substitute_part_id')
                ->all();
            foreach ($subs as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function complete(WorkOrder $workOrder, ?int $actorId = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $actorId) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            abort_unless($workOrder->status === 'in_progress', 422, __('WO belum bisa di-complete.'));

            // Sisa booking yang belum dikonsumsi (mis. produksi kurang dari qty WO)
            // dilepas supaya stok bisa dipakai WO lain.
            $this->stockService->releaseBookings($workOrder->id, null, $actorId);

            $workOrder->update([
                'status' => 'completed',
                'completed_at' => now(),
                'updated_by' => $actorId,
            ]);

            return $workOrder->fresh();
        });
    }

    public function cancel(WorkOrder $workOrder, ?int $actorId = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $actorId) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            abort_if($workOrder->status === 'completed', 422, __('WO completed tidak bisa dibatalkan.'));

            // Lepas booking material supaya stok bisa dipakai WO lain lagi.
            $this->stockService->releaseBookings($workOrder->id, null, $actorId);

            $workOrder->update(['status' => 'cancelled', 'updated_by' => $actorId]);

            return $workOrder->fresh();
        });
    }

    /**
     * Kunci grup mesin: 3 karakter pertama nama mesin (mis. "TPL"), fallback id mesin.
     */
    private function machineGroupKey(WorkOrderItem $item): string
    {
        return MachineRouting::groupKey($item->machine, $item->machine_id);
    }

    private function sorted(Collection $items): Collection
    {
        return $items->sortBy(fn ($it) => [$it->sequence ?? 0, $it->id]);
    }

    private function childKeyOf($it): string
    {
        if ($it->child_part_id !== null) {
            $num = $it->childPart?->part_number;
            if ($num !== null && $num !== '') {
                return (string) $num;
            }

            return (string) $it->child_part_id;
        }

        return (string) ($it->child_part_name ?? '');
    }

    private function parentKeyOf($it): string
    {
        if ($it->parent_part_id !== null) {
            $num = $it->parentPart?->part_number;
            if ($num !== null && $num !== '') {
                return (string) $num;
            }

            return (string) $it->parent_part_id;
        }

        return (string) ($it->parent_part_name ?? '');
    }
}
