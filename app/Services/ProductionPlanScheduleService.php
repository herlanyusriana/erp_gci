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

            $steps = [];
            foreach ($sortedItems as $item) {
                if ($item->parent_part_id === null || strtoupper((string) $item->source) === 'SUBCON') {
                    continue;
                }
                if ($item->machine_id !== null && isset($subconLookup[(int) $item->machine_id])) {
                    continue;
                }
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

            $seenMachineForWo = [];
            foreach ($groups as $group) {
                if ($group['machine_id'] === null || isset($seenMachineForWo[$group['machine_id']])) {
                    continue;
                }
                $seenMachineForWo[$group['machine_id']] = true;

                $first = $group['steps'][0];
                $last = $group['steps'][count($group['steps']) - 1];

                $woIds[$wo->id] = true;
                if ($last->parent_part_id !== null) {
                    $wipIds[(int) $last->parent_part_id] = true;
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
                    'machine_id' => $group['machine_id'],
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
                    'wip_part_id' => $last->parent_part_id !== null ? (int) $last->parent_part_id : null,
                    'wip_part' => $last->parentPart ? [
                        'id' => (int) $last->parentPart->id,
                        'part_number' => $last->parentPart->part_number,
                        'part_name' => $last->parentPart->part_name,
                        'model' => $last->parentPart->model,
                    ] : null,
                    'step_sequence' => $first->sequence !== null ? (int) $first->sequence : null,
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
    public function resolveCycleTime(?Machine $machine, ?int $machineId, ?int $wipPartId, array $maps): array
    {
        if ($machineId === null || $wipPartId === null) {
            return ['cycle_time_seconds' => null, 'qdc_minutes' => null];
        }

        $exactKey = $machineId.'|'.$wipPartId;
        $groupKey = MachineRouting::groupKey($machine, $machineId).'|'.$wipPartId;
        $ct = $maps['exact']->get($exactKey) ?? $maps['group']->get($groupKey);

        return [
            'cycle_time_seconds' => $ct?->cycle_time_seconds !== null ? (float) $ct->cycle_time_seconds : null,
            'qdc_minutes' => $ct?->qdc_minutes !== null ? (float) $ct->qdc_minutes : null,
        ];
    }

    /**
     * Hitung Start – Finish Time untuk 1 mesin pada 1 tanggal kalender.
     * Mengikuti jadwal 3 shift pabrik di `ProductionPeriods` (mulai 07:30,
     * melompati jam istirahat). QDC Seq 1 hanya diterapkan jika `$initialQdc`
     * dicentang; Seq >= 2 otomatis menerapkan QDC saat ganti part (`wip_part_id`).
     *
     * @param  list<array{key: string|int, sequence: int, id: int, qty: float, wip_part_id: int|null, cycle_time_seconds: float|null, qdc_minutes: float|null}>  $entries
     * @return array<string|int, array{start_time: string|null, end_time: string|null, qdc_applied_minutes: float, duration_seconds: float|null, is_overload: bool}>
     */
    public function calculateDaySchedule(string $date, array $entries, bool $initialQdc): array
    {
        $intervals = $this->workingIntervalsForDate($date);
        if ($intervals === []) {
            return [];
        }

        usort($entries, fn (array $a, array $b): int => ($a['sequence'] <=> $b['sequence']) ?: ($a['id'] <=> $b['id']));

        $cursor = (float) $intervals[0][0];
        $lastEnd = (float) $intervals[count($intervals) - 1][1];
        $results = [];
        $timedIndex = 0;
        $prevWipPartId = null;

        foreach ($entries as $entry) {
            $key = $entry['key'];
            $qty = (float) $entry['qty'];
            $tt = $entry['cycle_time_seconds'];
            $qdc = $entry['qdc_minutes'];

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

            $qdcApplied = 0.0;
            if ($qdc !== null && $qdc > 0) {
                if ($timedIndex === 0) {
                    if ($initialQdc) {
                        $qdcApplied = (float) $qdc;
                    }
                } elseif ($entry['wip_part_id'] === null || $entry['wip_part_id'] !== $prevWipPartId) {
                    $qdcApplied = (float) $qdc;
                }
            }

            $cursor = $this->snapToWorking($cursor, $intervals);
            $startMinute = $cursor;

            $runMinutes = ($qty * $tt) / 60.0;
            [$cursor, $isOverload] = $this->advanceCursor($cursor, $qdcApplied + $runMinutes, $intervals);
            $endMinute = $cursor;

            if ($endMinute > $lastEnd + 1e-6) {
                $isOverload = true;
            }

            $results[$key] = [
                'start_time' => $this->formatClock($startMinute),
                'end_time' => $this->formatClock($endMinute),
                'qdc_applied_minutes' => round($qdcApplied, 2),
                'duration_seconds' => round(($qdcApplied * 60.0) + ($qty * $tt), 2),
                'is_overload' => $isOverload,
            ];

            $timedIndex++;
            $prevWipPartId = $entry['wip_part_id'];
        }

        return $results;
    }

    /**
     * Interval kerja (menit dari 00:00 tanggal tersebut) untuk 3 shift pabrik.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function workingIntervalsForDate(string $date): array
    {
        $carbon = Carbon::parse($date);
        $intervals = [];

        foreach (['1', '2', '3'] as $shift) {
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
     * Simpan rencana 3 hari (D, D+1, D+2) dan pengaturan QDC Awal untuk satu mesin.
     * Disimpan per tanggal kalender (`plan_date`) agar besok D+1 otomatis bergeser jadi D.
     *
     * @param  array{d: bool, d1: bool, d2: bool}  $initialQdc
     * @param  list<array{work_order_id: int, d_seq: int|null, d_qty: float|null, d1_seq: int|null, d1_qty: float|null, d2_seq: int|null, d2_qty: float|null}>  $rows
     */
    public function saveMachinePlan(Machine $machine, string $boardDate, array $initialQdc, array $rows, int $actorId): void
    {
        $dayDates = [
            'd' => Carbon::parse($boardDate)->toDateString(),
            'd1' => Carbon::parse($boardDate)->addDay()->toDateString(),
            'd2' => Carbon::parse($boardDate)->addDays(2)->toDateString(),
        ];

        $submittedWoIds = collect($rows)->pluck('work_order_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        $workOrders = WorkOrder::query()
            ->whereIn('id', $submittedWoIds)
            ->whereIn('status', ['planned', 'in_progress', 'completed'])
            ->with([
                'part:id,part_number,part_name,model',
                'items.machine:id,machine_code,machine_name',
                'items.process:id,process_name',
                'items.parentPart:id,part_number,part_name,model',
                'items.childPart:id,part_number,part_name,model',
            ])
            ->get();

        $slotsByWo = $this->workOrderMachineSlots($workOrders, $dayDates['d'], $this->subconMachineIds())
            ->where('machine_id', $machine->id)
            ->keyBy('work_order_id');

        $errors = [];
        foreach ($rows as $idx => $row) {
            $woId = (int) $row['work_order_id'];
            $slot = $slotsByWo->get($woId);
            $dQty = $this->positiveFloat($row['d_qty'] ?? null);
            $d1Qty = $this->positiveFloat($row['d1_qty'] ?? null);
            $d2Qty = $this->positiveFloat($row['d2_qty'] ?? null);
            $totalPlanned = ($dQty ?? 0.0) + ($d1Qty ?? 0.0) + ($d2Qty ?? 0.0);

            if ($slot === null) {
                if ($totalPlanned > 0) {
                    $errors["rows.{$idx}.work_order_id"] = __('WO tidak melewati mesin ini.');
                }

                continue;
            }

            if ($totalPlanned > (float) $slot['remaining_qty'] + 1e-6) {
                $errors["rows.{$idx}.qty"] = __('Total Qty Plan (:planned) melebihi sisa WO (:remain). Klik "Buat WO" untuk menambah WO baru.', [
                    'planned' => rtrim(rtrim(number_format($totalPlanned, 2, '.', ''), '0'), '.'),
                    'remain' => rtrim(rtrim(number_format((float) $slot['remaining_qty'], 2, '.', ''), '0'), '.'),
                ]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($machine, $dayDates, $initialQdc, $rows, $slotsByWo, $actorId): void {
            $plansByKey = [];
            foreach ($dayDates as $dayKey => $planDate) {
                $hasAnyQty = collect($rows)->contains(fn (array $r): bool => $this->positiveFloat($r[$dayKey.'_qty'] ?? null) !== null);
                $flag = (bool) ($initialQdc[$dayKey] ?? false);

                $plan = ProductionPlan::query()->whereDate('plan_date', $planDate)->first();
                if ($plan === null && ($hasAnyQty || $flag)) {
                    $plan = ProductionPlan::create([
                        'plan_date' => $planDate,
                        'created_by' => $actorId,
                    ]);
                }

                if ($plan !== null) {
                    $plansByKey[$dayKey] = $plan;
                    ProductionPlanMachineDay::updateOrCreate(
                        [
                            'production_plan_id' => $plan->id,
                            'machine_id' => $machine->id,
                        ],
                        ['initial_qdc' => $flag],
                    );
                }
            }

            foreach ($rows as $row) {
                $woId = (int) $row['work_order_id'];
                $slot = $slotsByWo->get($woId);

                foreach (['d', 'd1', 'd2'] as $dayKey) {
                    $plan = $plansByKey[$dayKey] ?? null;
                    if ($plan === null) {
                        continue;
                    }

                    $qty = $this->positiveFloat($row[$dayKey.'_qty'] ?? null);
                    $seqRaw = isset($row[$dayKey.'_seq']) && $row[$dayKey.'_seq'] !== '' ? (int) $row[$dayKey.'_seq'] : 0;
                    $seq = $seqRaw > 0 ? $seqRaw : 1;

                    $existing = ProductionPlanItem::query()
                        ->where('production_plan_id', $plan->id)
                        ->where('machine_id', $machine->id)
                        ->where('work_order_id', $woId)
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

                    $before = $existing ? [
                        'target_d' => $existing->target_d,
                        'sequence_d' => $existing->sequence_d,
                    ] : null;

                    $item = ProductionPlanItem::updateOrCreate(
                        [
                            'production_plan_id' => $plan->id,
                            'machine_id' => $machine->id,
                            'work_order_id' => $woId,
                        ],
                        [
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
                            'created_by' => $existing?->created_by ?? $actorId,
                            'updated_by' => $actorId,
                        ],
                    );

                    $after = [
                        'target_d' => $item->target_d,
                        'sequence_d' => $item->sequence_d,
                    ];

                    if ($before === null || $before['target_d'] !== $after['target_d'] || $before['sequence_d'] !== $after['sequence_d']) {
                        ProductionPlanHistory::create([
                            'production_plan_id' => $plan->id,
                            'production_plan_item_id' => $item->id,
                            'event' => 'targets_updated',
                            'before' => $before,
                            'after' => $after,
                            'user_id' => $actorId,
                        ]);
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
}
