<?php

namespace App\Services;

use App\Models\Bom;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\PartSubstitute;
use App\Models\ProductionPlan;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
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

    /**
     * Build demand map key=partKey => total qty yang dibutuhkan (dikonsumsi).
     * Berlaku untuk bom_items (create) maupun work_order_items snapshot (release).
     */
    public function buildRequirements(iterable $items, float $qty, string $fgPartNumber): array
    {
        $parentsOf = [];
        foreach ($items as $it) {
            $childKey = $this->childKeyOf($it);
            $parentKey = $this->parentKeyOf($it);
            if ($childKey === '' || $parentKey === '') {
                continue;
            }
            $parentsOf[$childKey][] = [$parentKey, (float) $it->child_qty];
        }

        $memo = [];

        $req = function (string $partKey) use (&$req, &$memo, $parentsOf, $fgPartNumber, $qty) {
            if (array_key_exists($partKey, $memo)) {
                return $memo[$partKey];
            }
            if ($partKey === $fgPartNumber) {
                $memo[$partKey] = $qty;

                return $qty;
            }

            $sum = 0.0;
            foreach ($parentsOf[$partKey] ?? [] as [$parentKey, $childQty]) {
                $sum += $req($parentKey) * $childQty;
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

        $planDate = $workOrder->planned_date?->toDateString() ?? now()->toDateString();

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
        foreach ($items as $item) {
            if ($item->parent_part_id === null || strtoupper((string) $item->source) === 'SUBCON') {
                continue;
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
        foreach ($bom->items as $it) {
            $cid = $it->child_part_id;
            if ($cid === null || isset($postedParentIds[$cid])) {
                continue;
            }
            $parentKey = $this->parentKeyOf($it);
            $childQty = (float) $it->child_qty;
            $qtyRequired = ($parentKey !== '' && isset($requirements[$parentKey]))
                ? $requirements[$parentKey] * $childQty
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

        $workOrder = DB::transaction(function () use ($bom, $qty, $plannedDate, $remarks, $creatorId, $requirements, &$warnings, $leafNeeds) {
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

            foreach ($bom->items as $it) {
                $parentKey = $this->parentKeyOf($it);
                $childQty = (float) $it->child_qty;
                $qtyRequired = ($parentKey !== '' && isset($requirements[$parentKey]))
                    ? $requirements[$parentKey] * $childQty
                    : 0.0;

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
        });

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

            // WO baru masuk papan Production Plan saat release.
            $this->enterProductionPlan($workOrder, (int) $actorId);

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
    }

    /**
     * Release WO dari scan label (mobile "issue out to production").
     *
     * Konsumsi tag stok yang di-scan (spesifik, bukan FIFO), catat dokumen
     * material issue, lalu posting output WIP/FG seperti release biasa.
     *
     * @param  array<int, array{work_order_item_id:int, scans: array<int, array{tag:string, qty:float, part_id?:int|null}>}>  $itemScans
     * @param  array{issue_date?:string|null, received_by?:string|null, idempotency_key?:string|null, notes?:string|null}  $meta
     * @return array{0: WorkOrder, 1: array<int, array<string, mixed>>, 2: MaterialIssue}
     */
    public function releaseWithScans(WorkOrder $workOrder, array $itemScans, array $meta, ?int $actorId = null): array
    {
        $idempotencyKey = $meta['idempotency_key'] ?? null;
        if ($idempotencyKey !== null) {
            $existing = MaterialIssue::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return [$workOrder->fresh(['items', 'part']), [], $existing];
            }
        }

        abort_if($workOrder->status !== 'planned', 422, __('WO hanya bisa di-release dari status planned.'));

        $items = $workOrder->items()->with(['parentPart', 'childPart', 'allocations'])->get();
        $workOrder->load('part');

        $postedParentIds = [];
        foreach ($items as $it) {
            if (strtoupper((string) $it->source) !== 'SUBCON' && $it->parent_part_id !== null) {
                $postedParentIds[$it->parent_part_id] = true;
            }
        }

        $itemsById = $items->keyBy('id');

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
                $total += $qty;
                $scans[] = ['tag' => $tag, 'part_id' => $partId, 'qty' => $qty];
            }

            $required = (float) $item->qty_required;
            if ($total > $required + 1e-9) {
                throw ValidationException::withMessages([
                    "items.$itemId" => __('Total scan (:total) melebihi kebutuhan (:required).', [
                        'total' => round($total, 4),
                        'required' => round($required, 4),
                    ]),
                ]);
            }

            $scansByItem[$itemId] = $scans;
        }

        $shortages = [];

        $issue = DB::transaction(function () use ($workOrder, $items, $scansByItem, $postedParentIds, $meta, $actorId, &$shortages) {
            $releasedAt = now();
            $issueDate = isset($meta['issue_date']) && $meta['issue_date']
                ? Carbon::parse($meta['issue_date'])->toDateString()
                : $releasedAt->toDateString();

            $issue = MaterialIssue::create([
                'issue_no' => MaterialIssue::generateIssueNo(),
                'work_order_id' => $workOrder->id,
                'issue_date' => $issueDate,
                'issued_by' => $actorId,
                'received_by' => $meta['received_by'] ?? null,
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
                        $alloc = $this->stockService->bookFromTag($scan['tag'], $scan['part_id'], $scan['qty'], $workOrder->id, $it->id, $actorId);
                        if ($alloc === null) {
                            throw ValidationException::withMessages([
                                'items' => __('Tag :tag tidak ditemukan atau stok tidak cukup.', ['tag' => $scan['tag']]),
                            ]);
                        }
                        if (abs((float) $alloc['take_qty'] - (float) $scan['qty']) > 1e-6) {
                            throw ValidationException::withMessages([
                                'items' => __('Stok tag :tag berubah. Silakan scan ulang.', ['tag' => $scan['tag']]),
                            ]);
                        }

                        $taken += (float) $alloc['take_qty'];
                        MaterialIssueItem::create([
                            'material_issue_id' => $issue->id,
                            'work_order_item_id' => $it->id,
                            'part_id' => $scan['part_id'] ?? $it->child_part_id,
                            'part_stock_id' => $alloc['part_stock_id'],
                            'tag' => $alloc['tag'],
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

            // WO baru masuk papan Production Plan saat release.
            $this->enterProductionPlan($workOrder, (int) $actorId);

            return $issue;
        });

        return [$workOrder->fresh(['items', 'part']), $shortages, $issue];
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
    }

    public function cancel(WorkOrder $workOrder, ?int $actorId = null): WorkOrder
    {
        abort_if($workOrder->status === 'completed', 422, __('WO completed tidak bisa dibatalkan.'));

        // Lepas booking material supaya stok bisa dipakai WO lain lagi.
        $this->stockService->releaseBookings($workOrder->id, null, $actorId);

        $workOrder->update(['status' => 'cancelled', 'updated_by' => $actorId]);

        return $workOrder->fresh();
    }

    /**
     * Kunci grup mesin: 3 karakter pertama nama mesin (mis. "TPL"), fallback id mesin.
     */
    private function machineGroupKey(WorkOrderItem $item): string
    {
        $name = strtoupper(trim((string) ($item->machine?->machine_name ?? '')));
        if ($name !== '') {
            return substr($name, 0, 3);
        }

        return $item->machine_id !== null ? 'id:'.$item->machine_id : 'none';
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
