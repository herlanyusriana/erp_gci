<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\MachineCycleTime;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanHistory;
use App\Models\ProductionPlanItem;
use App\Models\ProductionPlanMachineDay;
use App\Models\ProductionResult;
use App\Models\WorkOrder;
use App\Support\MachineRouting;
use App\Support\ProductionPeriods;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionPlanScheduleService
{
    public function __construct(
        protected ReceiveMaterialService $receiveMaterialService,
    ) {}

    /**
     * ID mesin Subcon — papan Production Plan hanya untuk mesin internal.
     *
     * @return list<int>
     */
    public function subconMachineIds(): array
    {
        return Machine::query()
            ->whereRaw("UPPER(COALESCE(machine_code, '')) = 'SUBCON' OR LOWER(COALESCE(machine_name, '')) LIKE '%subcon%'")
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  Collection<int, Machine>|null  $activeMachines
     */
    public function rolloverTargetFor(Machine $sourceMachine, ?Collection $activeMachines = null): ?Machine
    {
        $sourceName = strtoupper(trim((string) $sourceMachine->machine_name));
        $candidateNames = Machine::ROLLOVER_TARGET_NAMES[$sourceName] ?? null;
        if ($candidateNames === null || $candidateNames === []) {
            return null;
        }

        if ($activeMachines !== null) {
            $byName = $activeMachines->keyBy(fn (Machine $m): string => strtoupper(trim((string) $m->machine_name)));
            foreach ($candidateNames as $candidateName) {
                $match = $byName->get($candidateName);
                if ($match instanceof Machine && (int) $match->id !== (int) $sourceMachine->id) {
                    return $match;
                }
            }

            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($candidateNames), '?'));
        $candidates = Machine::query()
            ->where('is_active', true)
            ->where('id', '!=', $sourceMachine->id)
            ->whereRaw("UPPER(TRIM(machine_name)) IN ({$placeholders})", $candidateNames)
            ->get(['id', 'machine_code', 'machine_name', 'sequence']);

        $byName = $candidates->keyBy(fn (Machine $m): string => strtoupper(trim((string) $m->machine_name)));
        foreach ($candidateNames as $candidateName) {
            $match = $byName->get($candidateName);
            if ($match instanceof Machine) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Machine>|null  $activeMachines
     */
    public function rolloverSourceFor(Machine $targetMachine, ?Collection $activeMachines = null): ?Machine
    {
        $targetName = strtoupper(trim((string) $targetMachine->machine_name));
        $sourceName = null;

        foreach (Machine::ROLLOVER_TARGET_NAMES as $candidateSource => $targetNames) {
            if (in_array($targetName, $targetNames, true)) {
                $sourceName = $candidateSource;
                break;
            }
        }

        if ($sourceName === null) {
            return null;
        }

        if ($activeMachines !== null) {
            $match = $activeMachines->first(
                fn (Machine $m): bool => strtoupper(trim((string) $m->machine_name)) === $sourceName
                    && (int) $m->id !== (int) $targetMachine->id
            );

            return $match instanceof Machine ? $match : null;
        }

        return Machine::query()
            ->where('is_active', true)
            ->where('id', '!=', $targetMachine->id)
            ->whereRaw('UPPER(TRIM(machine_name)) = ?', [$sourceName])
            ->first(['id', 'machine_code', 'machine_name', 'sequence']);
    }

    /**
     * Urai WO aktif menjadi slot per grup mesin (aturan pengelompokan sama
     * dengan routing BOM: 3 huruf awal nama mesin, lewati Subcon).
     *
     * @param  Collection<int, WorkOrder>  $workOrders
     * @param  list<int>  $subconMachineIds
     * @return Collection<int, array<string, mixed>>
     */
    public function workOrderMachineSlots(Collection $workOrders, string $asOfDate, array $subconMachineIds): Collection
    {
        if ($workOrders->isEmpty()) {
            return collect();
        }

        $subconLookup = array_flip($subconMachineIds);
        $rawSlots = [];
        $woIds = [];
        $wipIds = [];

        foreach ($workOrders as $wo) {
            $sortedItems = $wo->items->sortBy(fn ($it) => [$it->sequence ?? 0, $it->id]);

            $validItems = [];
            $steps = [];
            foreach ($sortedItems as $item) {
                if ($item->parent_part_id === null || strtoupper((string) $item->source) === 'SUBCON') {
                    continue;
                }
                if ($item->machine_id !== null && isset($subconLookup[(int) $item->machine_id])) {
                    continue;
                }
                $validItems[] = $item;
                $stepKey = ($item->sequence ?? 0).'|'.$item->parent_part_id;
                $steps[$stepKey] ??= $item;
            }

            $groups = [];
            foreach ($steps as $step) {
                $key = MachineRouting::groupKey($step->machine, $step->machine_id);
                $lastIndex = count($groups) - 1;
                if ($lastIndex >= 0 && $groups[$lastIndex]['key'] === $key) {
                    $groups[$lastIndex]['steps'][] = $step;

                    continue;
                }
                $groups[] = [
                    'key' => $key,
                    'machine_id' => $step->machine_id !== null ? (int) $step->machine_id : null,
                    'steps' => [$step],
                ];
            }

            $woBoardGroups = [];
            $seenMachineForWo = [];
            foreach ($groups as $group) {
                if ($group['machine_id'] === null || isset($seenMachineForWo[$group['machine_id']])) {
                    continue;
                }
                $seenMachineForWo[$group['machine_id']] = true;

                $first = $group['steps'][0];
                $last = $group['steps'][count($group['steps']) - 1];
                $stepParentPartIds = [];
                foreach ($group['steps'] as $stepItem) {
                    if ($stepItem->parent_part_id !== null) {
                        $stepParentPartIds[(int) $stepItem->parent_part_id] = true;
                    }
                }

                $woBoardGroups[] = [
                    'machine_id' => $group['machine_id'],
                    'machine_name' => (string) ($first->machine?->machine_name ?? ''),
                    'first' => $first,
                    'last' => $last,
                    'wip_part_id' => $last->parent_part_id !== null ? (int) $last->parent_part_id : null,
                    'step_parent_part_ids' => $stepParentPartIds,
                ];
            }

            $wipOwnerByPartId = [];
            foreach ($woBoardGroups as $bg) {
                if ($bg['wip_part_id'] !== null && ! isset($wipOwnerByPartId[$bg['wip_part_id']])) {
                    $wipOwnerByPartId[$bg['wip_part_id']] = [
                        'machine_id' => $bg['machine_id'],
                        'machine_name' => $bg['machine_name'],
                        'wip_part_id' => $bg['wip_part_id'],
                    ];
                }
            }

            foreach ($woBoardGroups as $bg) {
                $first = $bg['first'];
                $last = $bg['last'];

                $upstreamDeps = [];
                $seenDep = [];
                foreach ($validItems as $item) {
                    if ($item->child_part_id === null) {
                        continue;
                    }
                    if (! isset($bg['step_parent_part_ids'][(int) $item->parent_part_id])) {
                        continue;
                    }
                    $childPartId = (int) $item->child_part_id;
                    $owner = $wipOwnerByPartId[$childPartId] ?? null;
                    if ($owner === null || $owner['machine_id'] === $bg['machine_id']) {
                        continue;
                    }
                    $depKey = $owner['machine_id'].'|'.$owner['wip_part_id'];
                    if (isset($seenDep[$depKey])) {
                        continue;
                    }
                    $seenDep[$depKey] = true;
                    $rawChildQty = $item->child_qty !== null ? (float) $item->child_qty : 1.0;
                    $rawParentQty = $item->parent_qty !== null && (float) $item->parent_qty > 0 ? (float) $item->parent_qty : 1.0;
                    $effectiveChildQty = $rawChildQty > 0 ? ($rawChildQty / $rawParentQty) : 1.0;
                    $upstreamDeps[] = [
                        'machine_id' => $owner['machine_id'],
                        'machine_name' => $owner['machine_name'],
                        'wip_part_id' => $owner['wip_part_id'],
                        'child_qty' => $effectiveChildQty,
                    ];
                }

                $woIds[$wo->id] = true;
                if ($bg['wip_part_id'] !== null) {
                    $wipIds[$bg['wip_part_id']] = true;
                }

                $rawSlots[] = [
                    'work_order_id' => (int) $wo->id,
                    'wo_no' => $wo->wo_no,
                    'wo_status' => $wo->status,
                    'wo_qty' => (float) $wo->qty,
                    'planned_date' => $wo->planned_date?->toDateString(),
                    'fg_part_id' => (int) $wo->part_id,
                    'fg_part' => $wo->part ? [
                        'id' => (int) $wo->part->id,
                        'part_number' => $wo->part->part_number,
                        'part_name' => $wo->part->part_name,
                        'model' => $wo->part->model,
                    ] : null,
                    'machine_id' => $bg['machine_id'],
                    'machine' => $first->machine ? [
                        'id' => (int) $first->machine->id,
                        'machine_code' => $first->machine->machine_code,
                        'machine_name' => $first->machine->machine_name,
                    ] : null,
                    'process_id' => $first->process_id !== null ? (int) $first->process_id : null,
                    'process_name' => $first->process?->process_name,
                    'input_part_id' => $first->child_part_id !== null ? (int) $first->child_part_id : null,
                    'input_part' => $first->childPart ? [
                        'id' => (int) $first->childPart->id,
                        'part_number' => $first->childPart->part_number,
                        'part_name' => $first->childPart->part_name,
                        'model' => $first->childPart->model,
                    ] : null,
                    'wip_part_id' => $bg['wip_part_id'],
                    'wip_part' => $last->parentPart ? [
                        'id' => (int) $last->parentPart->id,
                        'part_number' => $last->parentPart->part_number,
                        'part_name' => $last->parentPart->part_name,
                        'model' => $last->parentPart->model,
                    ] : null,
                    'step_sequence' => $first->sequence !== null ? (int) $first->sequence : null,
                    'upstream_deps' => $upstreamDeps,
                    'has_upstream' => $upstreamDeps !== [],
                ];
            }
        }

        $producedByStep = ($woIds !== [] && $wipIds !== [])
            ? ProductionResult::query()
                ->whereDate('result_date', '<=', $asOfDate)
                ->whereIn('work_order_id', array_keys($woIds))
                ->whereIn('parent_part_id', array_keys($wipIds))
                ->selectRaw('work_order_id, parent_part_id, SUM(qty_good) as qty_good')
                ->groupBy('work_order_id', 'parent_part_id')
                ->get()
                ->keyBy(fn (ProductionResult $r): string => $r->work_order_id.'|'.$r->parent_part_id)
            : collect();

        return collect($rawSlots)->map(function (array $slot) use ($producedByStep): array {
            $produced = (float) ($producedByStep->get($slot['work_order_id'].'|'.($slot['wip_part_id'] ?? 0))?->qty_good ?? 0);
            $slot['produced_qty'] = round($produced, 4);
            $slot['remaining_qty'] = round(max(0.0, $slot['wo_qty'] - $produced), 4);

            return $slot;
        });
    }

    /**
     * Ambil stok fisik WIP tersedia (FIFO, dikurangi booking aktif) untuk semua
     * `wip_part_id` upstream yang dibutuhkan oleh slot-slot WO.
     *
     * @param  Collection<int, array<string, mixed>>  $allSlots
     * @return array<int, float>
     */
    public function availableWipStockMap(Collection $allSlots): array
    {
        $upstreamWipPartIds = [];
        foreach ($allSlots as $slot) {
            foreach ($slot['upstream_deps'] ?? [] as $dep) {
                $partId = (int) ($dep['wip_part_id'] ?? 0);
                if ($partId > 0) {
                    $upstreamWipPartIds[$partId] = true;
                }
            }
        }

        if ($upstreamWipPartIds === []) {
            return [];
        }

        return $this->receiveMaterialService->availableFifoBatch(array_keys($upstreamWipPartIds));
    }

    /**
     * Hitung batas suplai kumulatif (D, D+1, D+2) untuk setiap slot `"{$woId}|{$machineId}"`
     * dari stok fisik WIP tersedia + rencana mesin upstream per tanggal.
     *
     * @param  Collection<int, array<string, mixed>>  $allSlots
     * @param  array<int, array<int, array<string, ProductionPlanItem|float|int|null>>>  $plannedByMachineWo
     * @param  array<int, float>  $wipStockByPartId
     * @return array<string, array{has_upstream: bool, day_caps: array{d: float, d1: float, d2: float}, eligible_qty: float}>
     */
    public function computeSlotSupplyCaps(Collection $allSlots, array $plannedByMachineWo, array $wipStockByPartId): array
    {
        $slotsByKey = [];
        foreach ($allSlots as $slot) {
            $key = ((int) $slot['work_order_id']).'|'.((int) $slot['machine_id']);
            $slotsByKey[$key] = $slot;
        }

        $caps = [];
        foreach ($slotsByKey as $slotKey => $slot) {
            $woId = (int) $slot['work_order_id'];
            $remaining = round(max(0.0, (float) ($slot['remaining_qty'] ?? 0.0)), 4);
            $produced = max(0.0, (float) ($slot['produced_qty'] ?? 0.0));
            $upstreamDeps = $slot['upstream_deps'] ?? [];

            if ($upstreamDeps === []) {
                $caps[$slotKey] = [
                    'has_upstream' => false,
                    'day_caps' => [
                        'd' => $remaining,
                        'd1' => $remaining,
                        'd2' => $remaining,
                    ],
                    'eligible_qty' => $remaining,
                ];

                continue;
            }

            $capD = $remaining;
            $capD1 = $remaining;
            $capD2 = $remaining;

            foreach ($upstreamDeps as $dep) {
                $upMachineId = (int) $dep['machine_id'];
                $wipPartId = (int) $dep['wip_part_id'];
                $childQty = (float) ($dep['child_qty'] ?? 1.0);
                $ratio = $childQty > 0 ? $childQty : 1.0;

                $upSlot = $slotsByKey[$woId.'|'.$upMachineId] ?? null;
                $upProduced = max(0.0, (float) ($upSlot['produced_qty'] ?? 0.0));

                $unconsumedFromWo = max(0.0, ($upProduced / $ratio) - $produced);
                $stockUnits = max(0.0, (float) ($wipStockByPartId[$wipPartId] ?? 0.0)) / $ratio;
                $physicalWipReady = max($unconsumedFromWo, $stockUnits);

                $upD = $this->plannedDayQty($plannedByMachineWo, $upMachineId, $woId, 'd') / $ratio;
                $upD1 = $this->plannedDayQty($plannedByMachineWo, $upMachineId, $woId, 'd1') / $ratio;
                $upD2 = $this->plannedDayQty($plannedByMachineWo, $upMachineId, $woId, 'd2') / $ratio;

                $depD = min($remaining, round(max(0.0, $physicalWipReady + $upD), 4));
                $depD1 = min($remaining, round(max(0.0, $physicalWipReady + $upD + $upD1), 4));
                $depD2 = min($remaining, round(max(0.0, $physicalWipReady + $upD + $upD1 + $upD2), 4));

                $capD = min($capD, $depD);
                $capD1 = min($capD1, $depD1);
                $capD2 = min($capD2, $depD2);
            }

            $capD = round(max(0.0, $capD), 4);
            $capD1 = round(max(0.0, $capD1), 4);
            $capD2 = round(max(0.0, $capD2), 4);

            $caps[$slotKey] = [
                'has_upstream' => true,
                'day_caps' => [
                    'd' => $capD,
                    'd1' => $capD1,
                    'd2' => $capD2,
                ],
                'eligible_qty' => $capD2,
            ];
        }

        return $caps;
    }

    /**
     * Ambil peta lookup Tact Time & QDC untuk daftar `wip_part_id`.
     * Prioritas 1: exact (machine_id, part_id), Prioritas 2: grup mesin sama (3 huruf awal).
     *
     * @param  list<int>  $partIds
     * @return array{exact: Collection<string, MachineCycleTime>, group: Collection<string, MachineCycleTime>}
     */
    public function cycleTimeMaps(array $partIds): array
    {
        if ($partIds === []) {
            return ['exact' => collect(), 'group' => collect()];
        }

        $rows = MachineCycleTime::query()
            ->with('machine:id,machine_name')
            ->where('is_active', true)
            ->whereIn('part_id', array_values(array_unique($partIds)))
            ->get(['machine_id', 'part_id', 'cycle_time_seconds', 'qdc_minutes']);

        return [
            'exact' => $rows->keyBy(fn (MachineCycleTime $r): string => $r->machine_id.'|'.$r->part_id),
            'group' => $rows->keyBy(fn (MachineCycleTime $r): string => MachineRouting::groupKey($r->machine, $r->machine_id).'|'.$r->part_id),
        ];
    }

    /**
     * Resolve Tact Time & QDC satu pasangan (machine, wip_part_id).
     *
     * @param  array{exact: Collection<string, MachineCycleTime>, group: Collection<string, MachineCycleTime>}  $maps
     * @return array{cycle_time_seconds: float|null, qdc_minutes: float|null}
     */
    public function resolveCycleTime(
        ?Machine $machine,
        ?int $machineId,
        ?int $wipPartId,
        array $maps,
        ?Machine $originMachine = null,
        ?int $originMachineId = null,
    ): array {
        if ($machineId === null || $wipPartId === null) {
            return ['cycle_time_seconds' => null, 'qdc_minutes' => null];
        }

        $exactKey = $machineId.'|'.$wipPartId;
        $groupKey = MachineRouting::groupKey($machine, $machineId).'|'.$wipPartId;
        $ct = $maps['exact']->get($exactKey) ?? $maps['group']->get($groupKey);

        if ($ct === null && $originMachineId !== null && $originMachineId !== $machineId) {
            $originExactKey = $originMachineId.'|'.$wipPartId;
            $originGroupKey = MachineRouting::groupKey($originMachine, $originMachineId).'|'.$wipPartId;
            $ct = $maps['exact']->get($originExactKey) ?? $maps['group']->get($originGroupKey);
        }

        return [
            'cycle_time_seconds' => $ct?->cycle_time_seconds !== null ? (float) $ct->cycle_time_seconds : null,
            'qdc_minutes' => $ct?->qdc_minutes !== null ? (float) $ct->qdc_minutes : null,
        ];
    }

    /**
     * Hitung Start – Finish Time untuk 1 mesin pada 1 tanggal kalender.
     * Mengikuti jadwal shift pabrik di `ProductionPeriods` (1..$shiftCount shift,
     * mulai 07:30, melompati jam istirahat). QDC per baris mengikuti flag `apply_qdc`
     * jika disediakan, atau fallback ke `$initialQdc` (Seq 1) & ganti part (Seq >= 2).
     *
     * @param  list<array{key: string|int, sequence: int, id: int, qty: float, wip_part_id: int|null, cycle_time_seconds: float|null, qdc_minutes: float|null, apply_qdc?: bool|null}>  $entries
     * @return array<string|int, array{start_time: string|null, end_time: string|null, qdc_applied_minutes: float, duration_seconds: float|null, is_overload: bool}>
     */
    public function calculateDaySchedule(string $date, array $entries, bool $initialQdc = false, int $shiftCount = 3): array
    {
        $intervals = $this->workingIntervalsForDate($date, $shiftCount);
        if ($intervals === []) {
            return [];
        }

        usort($entries, fn (array $a, array $b): int => ($a['sequence'] <=> $b['sequence']) ?: ($a['id'] <=> $b['id']));

        $groupedBySeq = [];
        foreach ($entries as $entry) {
            $seq = (int) ($entry['sequence'] > 0 ? $entry['sequence'] : 1);
            $groupedBySeq[$seq][] = $entry;
        }
        ksort($groupedBySeq);

        $cursor = (float) $intervals[0][0];
        $lastEnd = (float) $intervals[count($intervals) - 1][1];
        $results = [];
        $timedGroupIndex = 0;
        $prevWipPartIds = [];

        foreach ($groupedBySeq as $seqEntries) {
            $timedEntries = [];
            foreach ($seqEntries as $entry) {
                $key = $entry['key'];
                $qty = (float) $entry['qty'];
                $tt = $entry['cycle_time_seconds'];

                if ($qty <= 0 || $tt === null || $tt <= 0) {
                    $results[$key] = [
                        'start_time' => null,
                        'end_time' => null,
                        'qdc_applied_minutes' => 0.0,
                        'duration_seconds' => null,
                        'is_overload' => false,
                    ];

                    continue;
                }

                $timedEntries[] = $entry;
            }

            if ($timedEntries === []) {
                continue;
            }

            // Entri pada Seq yang sama berjalan paralel dalam 1 mesin / 1 mold:
            // QDC dihitung 1x (MAX), semua mulai bersamaan, dan Seq berikutnya
            // mulai setelah yang paling lama selesai.
            $groupQdcApplied = 0.0;
            foreach ($timedEntries as $entry) {
                $qdc = $entry['qdc_minutes'];
                if ($qdc === null || $qdc <= 0) {
                    continue;
                }

                $shouldApply = false;
                if (array_key_exists('apply_qdc', $entry) && $entry['apply_qdc'] !== null) {
                    $shouldApply = (bool) $entry['apply_qdc'];
                } elseif ($timedGroupIndex === 0) {
                    $shouldApply = $initialQdc;
                } elseif ($entry['wip_part_id'] === null || ! in_array($entry['wip_part_id'], $prevWipPartIds, true)) {
                    $shouldApply = true;
                }

                if ($shouldApply) {
                    $groupQdcApplied = max($groupQdcApplied, (float) $qdc);
                }
            }

            $cursor = $this->snapToWorking($cursor, $intervals);
            $groupStartMinute = $cursor;
            $groupMaxEndMinute = $groupStartMinute;
            $currentWipPartIds = [];

            foreach ($timedEntries as $entry) {
                $key = $entry['key'];
                $qty = (float) $entry['qty'];
                $tt = (float) $entry['cycle_time_seconds'];

                $runMinutes = ($qty * $tt) / 60.0;
                [$entryEndMinute, $isOverload] = $this->advanceCursor($groupStartMinute, $groupQdcApplied + $runMinutes, $intervals);

                if ($entryEndMinute > $lastEnd + 1e-6) {
                    $isOverload = true;
                }

                if ($entryEndMinute > $groupMaxEndMinute) {
                    $groupMaxEndMinute = $entryEndMinute;
                }

                $results[$key] = [
                    'start_time' => $this->formatClock($groupStartMinute),
                    'end_time' => $this->formatClock($entryEndMinute),
                    'qdc_applied_minutes' => round($groupQdcApplied, 2),
                    'duration_seconds' => round(($groupQdcApplied * 60.0) + ($qty * $tt), 2),
                    'is_overload' => $isOverload,
                ];

                if ($entry['wip_part_id'] !== null) {
                    $currentWipPartIds[] = $entry['wip_part_id'];
                }
            }

            $cursor = $groupMaxEndMinute;
            $timedGroupIndex++;
            $prevWipPartIds = $currentWipPartIds;
        }

        return $results;
    }

    /**
     * Total menit kerja tersedia untuk 1..$shiftCount shift pada tanggal tertentu.
     */
    public function shiftCapacityMinutes(string $date, int $shiftCount = 3): float
    {
        $total = 0;
        foreach ($this->workingIntervalsForDate($date, $shiftCount) as [$start, $end]) {
            $total += max(0, $end - $start);
        }

        return (float) $total;
    }

    /**
     * Peta kapasitas menit untuk 1, 2, dan 3 shift pada satu tanggal kalender.
     *
     * @return array<int, float>
     */
    public function shiftCapacitiesForDate(string $date): array
    {
        return [
            1 => $this->shiftCapacityMinutes($date, 1),
            2 => $this->shiftCapacityMinutes($date, 2),
            3 => $this->shiftCapacityMinutes($date, 3),
        ];
    }

    /**
     * Interval kerja (menit dari 00:00 tanggal tersebut) untuk 1..$shiftCount shift pabrik.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function workingIntervalsForDate(string $date, int $shiftCount = 3): array
    {
        $carbon = Carbon::parse($date);
        $maxShift = max(1, min(3, $shiftCount));
        $intervals = [];

        foreach (['1', '2', '3'] as $shift) {
            if ((int) $shift > $maxShift) {
                break;
            }
            $cfg = ProductionPeriods::scheduleFor($shift, $carbon);
            if ($cfg === null) {
                continue;
            }

            $start = $this->clockToMinutes($cfg['start']);
            $end = $this->clockToMinutes($cfg['end']);
            if ($cfg['starts_next_day']) {
                $start += 1440;
                $end += 1440;
            }
            if ($end <= $start) {
                $end += 1440;
            }

            if ($cfg['break_start'] !== null && $cfg['break_end'] !== null) {
                $bStart = $this->clockToMinutes($cfg['break_start']);
                $bEnd = $this->clockToMinutes($cfg['break_end']);
                if ($cfg['starts_next_day']) {
                    $bStart += 1440;
                    $bEnd += 1440;
                } elseif ($bStart < $start) {
                    $bStart += 1440;
                }
                if ($bEnd <= $bStart) {
                    $bEnd += 1440;
                }

                if ($bStart > $start) {
                    $intervals[] = [$start, $bStart];
                }
                if ($end > $bEnd) {
                    $intervals[] = [$bEnd, $end];
                }
            } else {
                $intervals[] = [$start, $end];
            }
        }

        return $intervals;
    }

    /**
     * Simpan rencana 3 hari (D, D+1, D+2), jumlah shift per hari, dan QDC per baris untuk satu mesin.
     * Disimpan per tanggal kalender (`plan_date`) agar besok D+1 otomatis bergeser jadi D.
     *
     * @param  array{d: bool, d1: bool, d2: bool}  $initialQdc
     * @param  list<array{work_order_id: int, origin_machine_id?: int|null, d_seq: int|null, d_qty: float|null, d_qdc?: bool|null, d_machine_id?: int|null, d1_seq: int|null, d1_qty: float|null, d1_qdc?: bool|null, d1_machine_id?: int|null, d2_seq: int|null, d2_qty: float|null, d2_qdc?: bool|null, d2_machine_id?: int|null}>  $rows
     * @param  array{d: int, d1: int, d2: int}  $shiftCounts
     * @param  array{d: bool, d1: bool, d2: bool}|null  $syncDownstream
     */
    public function saveMachinePlan(
        Machine $machine,
        string $boardDate,
        array $initialQdc,
        array $rows,
        int $actorId,
        array $shiftCounts = ['d' => 3, 'd1' => 3, 'd2' => 3],
        ?array $syncDownstream = null,
    ): void {
        $dayDates = [
            'd' => Carbon::parse($boardDate)->toDateString(),
            'd1' => Carbon::parse($boardDate)->addDay()->toDateString(),
            'd2' => Carbon::parse($boardDate)->addDays(2)->toDateString(),
        ];

        $subconMachineIds = $this->subconMachineIds();
        $activeMachines = Machine::query()
            ->where('is_active', true)
            ->whereNotIn('id', $subconMachineIds)
            ->pluck('id')
            ->flip()
            ->all();

        $rolloverTarget = $syncDownstream !== null ? $this->rolloverTargetFor($machine) : null;
        if ($rolloverTarget !== null && ! isset($activeMachines[(int) $rolloverTarget->id])) {
            $rolloverTarget = null;
        }
        $shouldSyncDay = [
            'd' => $rolloverTarget !== null && (bool) ($syncDownstream['d'] ?? false),
            'd1' => $rolloverTarget !== null && (bool) ($syncDownstream['d1'] ?? false),
            'd2' => $rolloverTarget !== null && (bool) ($syncDownstream['d2'] ?? false),
        ];
        $hasAnySyncDay = $shouldSyncDay['d'] || $shouldSyncDay['d1'] || $shouldSyncDay['d2'];

        $submittedWoIds = collect($rows)->pluck('work_order_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        $workOrders = $submittedWoIds !== []
            ? WorkOrder::query()
                ->whereIn('id', $submittedWoIds)
                ->whereIn('status', ['planned', 'in_progress', 'completed'])
                ->with([
                    'part:id,part_number,part_name,model',
                    'items.machine:id,machine_code,machine_name',
                    'items.process:id,process_name',
                    'items.parentPart:id,part_number,part_name,model',
                    'items.childPart:id,part_number,part_name,model',
                ])
                ->get()
            : collect();

        $allSlots = $this->workOrderMachineSlots($workOrders, $dayDates['d'], $subconMachineIds);
        $slotsByWoOrigin = $allSlots->keyBy(fn (array $slot): string => ((int) $slot['work_order_id']).'|'.((int) $slot['machine_id']));
        $wipStockByPartId = $this->availableWipStockMap($allSlots);

        $existingPlans = $submittedWoIds !== []
            ? ProductionPlan::query()
                ->whereIn(DB::raw('CAST(plan_date AS DATE)'), array_values($dayDates))
                ->get(['id', 'plan_date'])
            : collect();

        $planKeyByDate = array_flip($dayDates);
        $dayKeyByPlanId = [];
        foreach ($existingPlans as $ep) {
            $dStr = $ep->plan_date->toDateString();
            if (isset($planKeyByDate[$dStr])) {
                $dayKeyByPlanId[(int) $ep->id] = $planKeyByDate[$dStr];
            }
        }

        $existingPlannedQty = [];
        if ($submittedWoIds !== [] && $dayKeyByPlanId !== []) {
            $existingItems = ProductionPlanItem::query()
                ->whereIn('production_plan_id', array_keys($dayKeyByPlanId))
                ->whereIn('work_order_id', $submittedWoIds)
                ->whereNotNull('machine_id')
                ->where('target_d', '>', 0)
                ->get(['production_plan_id', 'machine_id', 'origin_machine_id', 'work_order_id', 'target_d']);

            foreach ($existingItems as $ei) {
                $dayKey = $dayKeyByPlanId[(int) $ei->production_plan_id] ?? null;
                $originId = $ei->slotOriginMachineId();
                if ($dayKey === null || $originId === null) {
                    continue;
                }
                $wId = (int) $ei->work_order_id;
                $existingPlannedQty[$originId][$wId][$dayKey] = ($existingPlannedQty[$originId][$wId][$dayKey] ?? 0.0) + (float) $ei->target_d;
            }
        }

        $simulatedPlannedQty = $existingPlannedQty;
        $syncedTargetOps = [];
        foreach ($rows as $row) {
            $woId = (int) $row['work_order_id'];
            $originMachineId = isset($row['origin_machine_id']) && $row['origin_machine_id'] !== null && $row['origin_machine_id'] !== ''
                ? (int) $row['origin_machine_id']
                : (int) $machine->id;
            $simulatedPlannedQty[$originMachineId][$woId] = [
                'd' => $this->positiveFloat($row['d_qty'] ?? null) ?? 0.0,
                'd1' => $this->positiveFloat($row['d1_qty'] ?? null) ?? 0.0,
                'd2' => $this->positiveFloat($row['d2_qty'] ?? null) ?? 0.0,
            ];

            if (! $hasAnySyncDay || $rolloverTarget === null || $originMachineId !== (int) $machine->id) {
                continue;
            }

            $targetMachineId = (int) $rolloverTarget->id;
            $targetSlot = $slotsByWoOrigin->get($woId.'|'.$targetMachineId);
            if ($targetSlot === null) {
                continue;
            }

            $unsyncedTargetQty = 0.0;
            foreach (['d', 'd1', 'd2'] as $dayKey) {
                if (! $shouldSyncDay[$dayKey]) {
                    $unsyncedTargetQty += $this->plannedDayQty($existingPlannedQty, $targetMachineId, $woId, $dayKey);
                }
            }
            $remainingBudgetForSyncedDays = round(max(0.0, (float) $targetSlot['remaining_qty'] - $unsyncedTargetQty), 4);

            $simulatedTargetDays = [
                'd' => $this->plannedDayQty($existingPlannedQty, $targetMachineId, $woId, 'd'),
                'd1' => $this->plannedDayQty($existingPlannedQty, $targetMachineId, $woId, 'd1'),
                'd2' => $this->plannedDayQty($existingPlannedQty, $targetMachineId, $woId, 'd2'),
            ];

            foreach (['d', 'd1', 'd2'] as $dayKey) {
                if (! $shouldSyncDay[$dayKey]) {
                    continue;
                }

                $qty = $this->positiveFloat($row[$dayKey.'_qty'] ?? null);
                $rawExecTarget = $row[$dayKey.'_machine_id'] ?? null;
                $execMachineId = $rawExecTarget !== null && $rawExecTarget !== ''
                    ? (int) $rawExecTarget
                    : (int) $machine->id;

                if ($qty !== null && $execMachineId === (int) $machine->id) {
                    $syncedQty = round(min($qty, max(0.0, $remainingBudgetForSyncedDays)), 4);
                    if ($syncedQty > 0.0) {
                        $remainingBudgetForSyncedDays = round(max(0.0, $remainingBudgetForSyncedDays - $syncedQty), 4);
                        $seqRaw = isset($row[$dayKey.'_seq']) && $row[$dayKey.'_seq'] !== '' ? (int) $row[$dayKey.'_seq'] : 0;
                        $seq = $seqRaw > 0 ? $seqRaw : 1;
                        $applyQdc = array_key_exists($dayKey.'_qdc', $row) && $row[$dayKey.'_qdc'] !== null
                            ? (bool) $row[$dayKey.'_qdc']
                            : ($seq === 1 ? (bool) ($initialQdc[$dayKey] ?? false) : true);

                        $syncedTargetOps[$woId][$dayKey] = [
                            'qty' => $syncedQty,
                            'seq' => $seq,
                            'apply_qdc' => $applyQdc,
                            'slot' => $targetSlot,
                        ];
                        $simulatedTargetDays[$dayKey] = $syncedQty;
                    } else {
                        $syncedTargetOps[$woId][$dayKey] = [
                            'qty' => null,
                            'seq' => 1,
                            'apply_qdc' => false,
                            'slot' => $targetSlot,
                        ];
                        $simulatedTargetDays[$dayKey] = 0.0;
                    }
                } else {
                    $syncedTargetOps[$woId][$dayKey] = [
                        'qty' => null,
                        'seq' => 1,
                        'apply_qdc' => false,
                        'slot' => $targetSlot,
                    ];
                    $simulatedTargetDays[$dayKey] = 0.0;
                }
            }

            $simulatedPlannedQty[$targetMachineId][$woId] = $simulatedTargetDays;
        }
        $nextSupplyCaps = $this->computeSlotSupplyCaps($allSlots, $simulatedPlannedQty, $wipStockByPartId);
        $allSlotsByWo = $allSlots->groupBy('work_order_id');

        $errors = [];
        foreach ($rows as $idx => $row) {
            $woId = (int) $row['work_order_id'];
            $originMachineId = isset($row['origin_machine_id']) && $row['origin_machine_id'] !== null && $row['origin_machine_id'] !== ''
                ? (int) $row['origin_machine_id']
                : (int) $machine->id;
            $slot = $slotsByWoOrigin->get($woId.'|'.$originMachineId);
            $dQty = $this->positiveFloat($row['d_qty'] ?? null);
            $d1Qty = $this->positiveFloat($row['d1_qty'] ?? null);
            $d2Qty = $this->positiveFloat($row['d2_qty'] ?? null);
            $dVal = $dQty ?? 0.0;
            $d1Val = $d1Qty ?? 0.0;
            $d2Val = $d2Qty ?? 0.0;
            $totalPlanned = $dVal + $d1Val + $d2Val;

            if ($slot === null) {
                if ($totalPlanned > 0) {
                    $errors["rows.{$idx}.work_order_id"] = __('WO tidak melewati mesin ini.');
                }

                continue;
            }

            $hasInvalidTargetMachine = false;
            foreach (['d' => $dQty, 'd1' => $d1Qty, 'd2' => $d2Qty] as $dayKey => $dayQty) {
                if ($dayQty === null) {
                    continue;
                }
                $rawTarget = $row[$dayKey.'_machine_id'] ?? null;
                $targetMachineId = $rawTarget !== null && $rawTarget !== ''
                    ? (int) $rawTarget
                    : (int) $machine->id;
                if (! isset($activeMachines[$targetMachineId])) {
                    $errors["rows.{$idx}.{$dayKey}_machine_id"] = __('Mesin pengganti tidak valid atau tidak aktif.');
                    $hasInvalidTargetMachine = true;
                }
            }
            if ($hasInvalidTargetMachine) {
                continue;
            }

            if ($totalPlanned > (float) $slot['remaining_qty'] + 1e-6) {
                $errors["rows.{$idx}.qty"] = __('Total Qty Plan (:planned) melebihi sisa WO (:remain). Klik "Buat WO" untuk menambah WO baru.', [
                    'planned' => $this->formatQty($totalPlanned),
                    'remain' => $this->formatQty((float) $slot['remaining_qty']),
                ]);

                continue;
            }

            $capInfo = $nextSupplyCaps[$woId.'|'.$originMachineId] ?? null;
            if ($capInfo !== null && $capInfo['has_upstream']) {
                $caps = $capInfo['day_caps'];
                if ($dVal > $caps['d'] + 1e-6) {
                    $errors["rows.{$idx}.qty"] = __('Qty Plan tanggal :date (:planned) melebihi suplai proses sebelumnya / stok WIP (:max). Rencanakan proses sebelumnya terlebih dahulu.', [
                        'date' => $dayDates['d'],
                        'planned' => $this->formatQty($dVal),
                        'max' => $this->formatQty($caps['d']),
                    ]);

                    continue;
                }
                if ($dVal + $d1Val > $caps['d1'] + 1e-6) {
                    $errors["rows.{$idx}.qty"] = __('Akumulasi Qty Plan s.d. tanggal :date (:planned) melebihi suplai proses sebelumnya / stok WIP (:max). Rencanakan proses sebelumnya terlebih dahulu.', [
                        'date' => $dayDates['d1'],
                        'planned' => $this->formatQty($dVal + $d1Val),
                        'max' => $this->formatQty($caps['d1']),
                    ]);

                    continue;
                }
                if ($totalPlanned > $caps['d2'] + 1e-6) {
                    $errors["rows.{$idx}.qty"] = __('Akumulasi Qty Plan s.d. tanggal :date (:planned) melebihi suplai proses sebelumnya / stok WIP (:max). Rencanakan proses sebelumnya terlebih dahulu.', [
                        'date' => $dayDates['d2'],
                        'planned' => $this->formatQty($totalPlanned),
                        'max' => $this->formatQty($caps['d2']),
                    ]);

                    continue;
                }
            }

            $changedMachineIds = [$originMachineId];
            if ($rolloverTarget !== null && isset($syncedTargetOps[$woId])) {
                $changedMachineIds[] = (int) $rolloverTarget->id;
            }

            foreach ($allSlotsByWo->get($woId, collect()) as $downSlot) {
                $dependsOnChanged = false;
                foreach ($downSlot['upstream_deps'] ?? [] as $dep) {
                    if (in_array((int) $dep['machine_id'], $changedMachineIds, true)) {
                        $dependsOnChanged = true;
                        break;
                    }
                }
                if (! $dependsOnChanged) {
                    continue;
                }

                $downMachineId = (int) $downSlot['machine_id'];
                $downD = $this->plannedDayQty($simulatedPlannedQty, $downMachineId, $woId, 'd');
                $downD1 = $this->plannedDayQty($simulatedPlannedQty, $downMachineId, $woId, 'd1');
                $downD2 = $this->plannedDayQty($simulatedPlannedQty, $downMachineId, $woId, 'd2');

                if ($downD + $downD1 + $downD2 <= 0.0) {
                    continue;
                }

                $newDownCaps = $nextSupplyCaps[$woId.'|'.$downMachineId]['day_caps'] ?? null;
                if ($newDownCaps === null) {
                    continue;
                }

                $downMachineName = (string) ($downSlot['machine']['machine_name'] ?? ('#'.$downMachineId));
                $violatedDate = null;
                $violatedPlanned = 0.0;
                $violatedMax = 0.0;

                if ($downD > $newDownCaps['d'] + 1e-6) {
                    $violatedDate = $dayDates['d'];
                    $violatedPlanned = $downD;
                    $violatedMax = $newDownCaps['d'];
                } elseif ($downD + $downD1 > $newDownCaps['d1'] + 1e-6) {
                    $violatedDate = $dayDates['d1'];
                    $violatedPlanned = $downD + $downD1;
                    $violatedMax = $newDownCaps['d1'];
                } elseif ($downD + $downD1 + $downD2 > $newDownCaps['d2'] + 1e-6) {
                    $violatedDate = $dayDates['d2'];
                    $violatedPlanned = $downD + $downD1 + $downD2;
                    $violatedMax = $newDownCaps['d2'];
                }

                if ($violatedDate !== null) {
                    $errors["rows.{$idx}.qty"] = __('Tidak dapat mengurangi rencana: mesin proses berikutnya :machine sudah merencanakan akumulasi :planned s.d. tanggal :date (suplai baru hanya :max). Kurangi rencana :machine terlebih dahulu.', [
                        'machine' => $downMachineName,
                        'planned' => $this->formatQty($violatedPlanned),
                        'date' => $violatedDate,
                        'max' => $this->formatQty($violatedMax),
                    ]);

                    break;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use (
            $machine,
            $dayDates,
            $initialQdc,
            $shiftCounts,
            $syncDownstream,
            $rolloverTarget,
            $shouldSyncDay,
            $syncedTargetOps,
            $rows,
            $slotsByWoOrigin,
            $actorId,
        ): void {
            $plansByKey = [];
            foreach ($dayDates as $dayKey => $planDate) {
                $hasAnyQty = collect($rows)->contains(fn (array $r): bool => $this->positiveFloat($r[$dayKey.'_qty'] ?? null) !== null);
                $flag = (bool) ($initialQdc[$dayKey] ?? false);
                $shifts = max(1, min(3, (int) ($shiftCounts[$dayKey] ?? 3)));
                $isExplicitlyUnchecked = $syncDownstream !== null
                    && $rolloverTarget !== null
                    && ! (bool) ($syncDownstream[$dayKey] ?? true);

                $plan = ProductionPlan::query()->whereDate('plan_date', $planDate)->first();
                if ($plan === null && ($hasAnyQty || $flag || $shifts !== 3 || $isExplicitlyUnchecked)) {
                    $plan = ProductionPlan::create([
                        'plan_date' => $planDate,
                        'created_by' => $actorId,
                    ]);
                }

                if ($plan !== null) {
                    $plansByKey[$dayKey] = $plan;
                    $machineDayAttributes = [
                        'shift_count' => $shifts,
                        'initial_qdc' => $flag,
                    ];
                    if ($syncDownstream !== null) {
                        $machineDayAttributes['sync_downstream'] = (bool) ($syncDownstream[$dayKey] ?? false);
                    }
                    ProductionPlanMachineDay::updateOrCreate(
                        [
                            'production_plan_id' => $plan->id,
                            'machine_id' => $machine->id,
                        ],
                        $machineDayAttributes,
                    );

                    if ($shouldSyncDay[$dayKey] && $rolloverTarget !== null) {
                        ProductionPlanMachineDay::updateOrCreate(
                            [
                                'production_plan_id' => $plan->id,
                                'machine_id' => $rolloverTarget->id,
                            ],
                            [
                                'shift_count' => $shifts,
                            ],
                        );
                    }
                }
            }

            foreach ($rows as $row) {
                $woId = (int) $row['work_order_id'];
                $originMachineId = isset($row['origin_machine_id']) && $row['origin_machine_id'] !== null && $row['origin_machine_id'] !== ''
                    ? (int) $row['origin_machine_id']
                    : (int) $machine->id;
                $slot = $slotsByWoOrigin->get($woId.'|'.$originMachineId);

                foreach (['d', 'd1', 'd2'] as $dayKey) {
                    $plan = $plansByKey[$dayKey] ?? null;
                    if ($plan === null) {
                        continue;
                    }

                    $qty = $this->positiveFloat($row[$dayKey.'_qty'] ?? null);
                    $seqRaw = isset($row[$dayKey.'_seq']) && $row[$dayKey.'_seq'] !== '' ? (int) $row[$dayKey.'_seq'] : 0;
                    $seq = $seqRaw > 0 ? $seqRaw : 1;
                    $applyQdc = array_key_exists($dayKey.'_qdc', $row) && $row[$dayKey.'_qdc'] !== null
                        ? (bool) $row[$dayKey.'_qdc']
                        : ($seq === 1 ? (bool) ($initialQdc[$dayKey] ?? false) : true);

                    $existing = ProductionPlanItem::query()
                        ->where('production_plan_id', $plan->id)
                        ->where('work_order_id', $woId)
                        ->where(function ($q) use ($originMachineId): void {
                            $q->where('origin_machine_id', $originMachineId)
                                ->orWhere(function ($q2) use ($originMachineId): void {
                                    $q2->whereNull('origin_machine_id')
                                        ->where('machine_id', $originMachineId);
                                });
                        })
                        ->first();

                    if ($qty === null) {
                        if ($existing !== null) {
                            $before = [
                                'target_d' => $existing->target_d,
                                'sequence_d' => $existing->sequence_d,
                                'machine_id' => $existing->machine_id,
                                'work_order_id' => $existing->work_order_id,
                            ];
                            ProductionPlanHistory::create([
                                'production_plan_id' => $plan->id,
                                'production_plan_item_id' => $existing->id,
                                'event' => 'item_detached',
                                'before' => $before,
                                'after' => null,
                                'user_id' => $actorId,
                            ]);
                            $existing->delete();
                        }

                        continue;
                    }

                    if ($slot === null) {
                        continue;
                    }

                    $rawTarget = $row[$dayKey.'_machine_id'] ?? null;
                    $targetMachineId = $rawTarget !== null && $rawTarget !== ''
                        ? (int) $rawTarget
                        : (int) $machine->id;
                    $storedOriginId = $targetMachineId !== $originMachineId ? $originMachineId : null;

                    $before = $existing ? [
                        'target_d' => $existing->target_d,
                        'sequence_d' => $existing->sequence_d,
                        'machine_id' => $existing->machine_id,
                    ] : null;

                    $attributes = [
                        'machine_id' => $targetMachineId,
                        'origin_machine_id' => $storedOriginId,
                        'process_id' => $slot['process_id'],
                        'fg_part_id' => $slot['fg_part_id'],
                        'input_part_id' => $slot['input_part_id'],
                        'wip_part_id' => $slot['wip_part_id'],
                        'step_sequence' => $slot['step_sequence'],
                        'sequence' => $seq,
                        'sequence_d' => $seq,
                        'sequence_d1' => 0,
                        'sequence_d2' => 0,
                        'target_d' => $qty,
                        'target_d1' => null,
                        'target_d2' => null,
                        'apply_qdc' => $applyQdc,
                        'updated_by' => $actorId,
                    ];

                    if ($existing !== null) {
                        $existing->update($attributes);
                        $item = $existing->refresh();
                    } else {
                        $item = ProductionPlanItem::create([
                            'production_plan_id' => $plan->id,
                            'work_order_id' => $woId,
                            ...$attributes,
                            'created_by' => $actorId,
                        ]);
                    }

                    if ($targetMachineId !== (int) $machine->id) {
                        ProductionPlanMachineDay::firstOrCreate(
                            [
                                'production_plan_id' => $plan->id,
                                'machine_id' => $targetMachineId,
                            ],
                            [
                                'shift_count' => 3,
                                'initial_qdc' => false,
                            ],
                        );
                    }

                    $machineChanged = $before !== null && (int) $before['machine_id'] !== (int) $item->machine_id;
                    $after = [
                        'target_d' => $item->target_d,
                        'sequence_d' => $item->sequence_d,
                        ...($machineChanged || $before === null ? ['machine_id' => $item->machine_id] : []),
                    ];
                    $beforePayload = $before === null
                        ? null
                        : ($machineChanged
                            ? $before
                            : ['target_d' => $before['target_d'], 'sequence_d' => $before['sequence_d']]);

                    if ($before === null
                        || $before['target_d'] !== $after['target_d']
                        || $before['sequence_d'] !== $after['sequence_d']
                        || $machineChanged
                    ) {
                        ProductionPlanHistory::create([
                            'production_plan_id' => $plan->id,
                            'production_plan_item_id' => $item->id,
                            'event' => 'targets_updated',
                            'before' => $beforePayload,
                            'after' => $after,
                            'user_id' => $actorId,
                        ]);
                    }
                }
            }

            if ($rolloverTarget !== null && $syncedTargetOps !== []) {
                $targetMachineId = (int) $rolloverTarget->id;

                foreach (['d', 'd1', 'd2'] as $dayKey) {
                    if (! $shouldSyncDay[$dayKey]) {
                        continue;
                    }
                    $plan = $plansByKey[$dayKey] ?? null;
                    if ($plan === null) {
                        continue;
                    }

                    foreach ($syncedTargetOps as $woId => $opsByDay) {
                        if (! array_key_exists($dayKey, $opsByDay)) {
                            continue;
                        }
                        $op = $opsByDay[$dayKey];
                        $targetSlot = $op['slot'];

                        $existingTarget = ProductionPlanItem::query()
                            ->where('production_plan_id', $plan->id)
                            ->where('work_order_id', $woId)
                            ->where(function ($q) use ($targetMachineId): void {
                                $q->where('origin_machine_id', $targetMachineId)
                                    ->orWhere(function ($q2) use ($targetMachineId): void {
                                        $q2->whereNull('origin_machine_id')
                                            ->where('machine_id', $targetMachineId);
                                    });
                            })
                            ->first();

                        if ($op['qty'] === null) {
                            if ($existingTarget !== null) {
                                $before = [
                                    'target_d' => $existingTarget->target_d,
                                    'sequence_d' => $existingTarget->sequence_d,
                                    'machine_id' => $existingTarget->machine_id,
                                    'work_order_id' => $existingTarget->work_order_id,
                                ];
                                ProductionPlanHistory::create([
                                    'production_plan_id' => $plan->id,
                                    'production_plan_item_id' => $existingTarget->id,
                                    'event' => 'item_detached',
                                    'before' => $before,
                                    'after' => null,
                                    'user_id' => $actorId,
                                ]);
                                $existingTarget->delete();
                            }

                            continue;
                        }

                        $before = $existingTarget ? [
                            'target_d' => $existingTarget->target_d,
                            'sequence_d' => $existingTarget->sequence_d,
                            'machine_id' => $existingTarget->machine_id,
                        ] : null;

                        $attributes = [
                            'machine_id' => $targetMachineId,
                            'origin_machine_id' => null,
                            'process_id' => $targetSlot['process_id'],
                            'fg_part_id' => $targetSlot['fg_part_id'],
                            'input_part_id' => $targetSlot['input_part_id'],
                            'wip_part_id' => $targetSlot['wip_part_id'],
                            'step_sequence' => $targetSlot['step_sequence'],
                            'sequence' => $op['seq'],
                            'sequence_d' => $op['seq'],
                            'sequence_d1' => 0,
                            'sequence_d2' => 0,
                            'target_d' => $op['qty'],
                            'target_d1' => null,
                            'target_d2' => null,
                            'apply_qdc' => $op['apply_qdc'],
                            'updated_by' => $actorId,
                        ];

                        if ($existingTarget !== null) {
                            $existingTarget->update($attributes);
                            $targetItem = $existingTarget->refresh();
                        } else {
                            $targetItem = ProductionPlanItem::create([
                                'production_plan_id' => $plan->id,
                                'work_order_id' => $woId,
                                ...$attributes,
                                'created_by' => $actorId,
                            ]);
                        }

                        $machineChanged = $before !== null && (int) $before['machine_id'] !== (int) $targetItem->machine_id;
                        $after = [
                            'target_d' => $targetItem->target_d,
                            'sequence_d' => $targetItem->sequence_d,
                            ...($machineChanged || $before === null ? ['machine_id' => $targetItem->machine_id] : []),
                        ];
                        $beforePayload = $before === null
                            ? null
                            : ($machineChanged
                                ? $before
                                : ['target_d' => $before['target_d'], 'sequence_d' => $before['sequence_d']]);

                        if ($before === null
                            || $before['target_d'] !== $after['target_d']
                            || $before['sequence_d'] !== $after['sequence_d']
                            || $machineChanged
                        ) {
                            ProductionPlanHistory::create([
                                'production_plan_id' => $plan->id,
                                'production_plan_item_id' => $targetItem->id,
                                'event' => 'targets_updated',
                                'before' => $beforePayload,
                                'after' => $after,
                                'user_id' => $actorId,
                            ]);
                        }
                    }
                }
            }
        });
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private function snapToWorking(float $cursor, array $intervals): float
    {
        foreach ($intervals as [$start, $end]) {
            if ($cursor <= $start) {
                return (float) $start;
            }
            if ($cursor < $end - 1e-9) {
                return $cursor;
            }
        }

        return $cursor;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return array{0: float, 1: bool}
     */
    private function advanceCursor(float $cursor, float $minutes, array $intervals): array
    {
        $remaining = $minutes;
        $lastEnd = (float) $intervals[count($intervals) - 1][1];

        foreach ($intervals as [$start, $end]) {
            if ($end <= $cursor + 1e-9) {
                continue;
            }
            if ($cursor < $start) {
                $cursor = (float) $start;
            }
            $avail = $end - $cursor;
            if ($remaining <= $avail + 1e-9) {
                return [$cursor + $remaining, false];
            }
            $remaining -= $avail;
            $cursor = (float) $end;
        }

        if ($remaining > 1e-9) {
            return [max($cursor, $lastEnd) + $remaining, true];
        }

        return [$cursor, $cursor > $lastEnd + 1e-6];
    }

    private function clockToMinutes(string $clock): int
    {
        [$h, $m] = array_map('intval', explode(':', $clock));

        return ($h * 60) + $m;
    }

    private function formatClock(float $minutes): string
    {
        $rounded = (int) round($minutes);
        $mod = (($rounded % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($mod, 60), $mod % 60);
    }

    private function positiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $num = (float) $value;

        return $num > 0 ? $num : null;
    }

    /**
     * @param  array<int, array<int, array<string, ProductionPlanItem|float|int|null>>>  $plannedByMachineWo
     */
    private function plannedDayQty(array $plannedByMachineWo, int $machineId, int $woId, string $dayKey): float
    {
        $entry = $plannedByMachineWo[$machineId][$woId][$dayKey] ?? null;
        if ($entry instanceof ProductionPlanItem) {
            return max(0.0, (float) $entry->target_d);
        }
        if (is_numeric($entry)) {
            return max(0.0, (float) $entry);
        }

        return 0.0;
    }

    private function formatQty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
