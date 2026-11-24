<?php

namespace App\Http\Controllers;

use App\Models\BomItem;
use App\Models\Machine;
use App\Models\Part;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanHistory;
use App\Models\ProductionPlanItem;
use App\Models\ProductionPlanMachineDay;
use App\Models\WorkOrder;
use App\Services\ProductionPlanScheduleService;
use App\Services\WoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductionPlanController extends Controller
{
    public function __construct(
        protected WoService $woService,
        protected ProductionPlanScheduleService $scheduleService,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ProductionPlan::class);

        $date = $request->input('date') ?: now()->toDateString();
        $dayDates = [
            'd' => Carbon::parse($date)->toDateString(),
            'd1' => Carbon::parse($date)->addDay()->toDateString(),
            'd2' => Carbon::parse($date)->addDays(2)->toDateString(),
        ];

        $subconMachineIds = $this->scheduleService->subconMachineIds();

        $machines = Machine::query()
            ->where('is_active', true)
            ->whereNotIn('id', $subconMachineIds)
            ->orderByRaw('sequence ASC NULLS LAST')
            ->orderBy('machine_name')
            ->get(['id', 'machine_code', 'machine_name', 'sequence']);

        $machinesById = $machines->keyBy('id');

        // Ambil rencana 3 hari kalender (D, D+1, D+2) saja — hari baru mulai kosong sampai di-Add Plan.
        $plansByDate = ProductionPlan::query()
            ->whereIn(DB::raw('CAST(plan_date AS DATE)'), array_values($dayDates))
            ->get()
            ->keyBy(fn (ProductionPlan $p): string => $p->plan_date->toDateString());

        $planIds = $plansByDate->pluck('id')->all();

        $machineDays = $planIds !== []
            ? ProductionPlanMachineDay::query()
                ->whereIn('production_plan_id', $planIds)
                ->get()
                ->keyBy(fn (ProductionPlanMachineDay $md): string => $md->production_plan_id.'|'.$md->machine_id)
            : collect();

        $planItems = $planIds !== []
            ? ProductionPlanItem::query()
                ->whereIn('production_plan_id', $planIds)
                ->whereNotNull('machine_id')
                ->whereNotNull('work_order_id')
                ->where('target_d', '>', 0)
                ->whereNotIn('machine_id', $subconMachineIds)
                ->with([
                    'plan:id,plan_date',
                    'machine:id,machine_code,machine_name',
                    'process:id,process_name',
                    'workOrder:id,wo_no,part_id,qty,status,planned_date',
                    'workOrder.part:id,part_number,part_name,model',
                    'fgPart:id,part_number,part_name,model',
                    'inputPart:id,part_number,part_name,model',
                    'wipPart:id,part_number,part_name,model',
                ])
                ->orderBy('sequence')
                ->orderBy('id')
                ->get()
            : collect();

        $plannedWoIds = $planItems->pluck('work_order_id')->filter()->unique()->all();

        $activeWorkOrders = WorkOrder::query()
            ->where(function ($query) use ($plannedWoIds): void {
                $query->whereIn('status', ['planned', 'in_progress']);
                if ($plannedWoIds !== []) {
                    $query->orWhereIn('id', $plannedWoIds);
                }
            })
            ->with([
                'part:id,part_number,part_name,model',
                'items.machine:id,machine_code,machine_name',
                'items.process:id,process_name',
                'items.parentPart:id,part_number,part_name,model',
                'items.childPart:id,part_number,part_name,model',
            ])
            ->orderByDesc('id')
            ->get();

        $allSlots = $this->scheduleService->workOrderMachineSlots($activeWorkOrders, $dayDates['d'], $subconMachineIds);

        // Kelompokkan plan items per (machine_id, work_order_id, dayKey).
        $planKeyByDate = array_flip($dayDates);
        $plannedByMachineWo = [];
        foreach ($planItems as $pi) {
            $planDateStr = $pi->plan?->plan_date?->toDateString();
            $dayKey = $planDateStr !== null ? ($planKeyByDate[$planDateStr] ?? null) : null;
            if ($dayKey === null) {
                continue;
            }
            $mId = (int) $pi->machine_id;
            $woId = (int) $pi->work_order_id;
            $plannedByMachineWo[$mId][$woId][$dayKey] = $pi;
        }

        // Kumpulkan semua wip_part_id untuk lookup Tact Time & QDC (1 query saja).
        $wipPartIds = $allSlots->pluck('wip_part_id')
            ->merge($planItems->pluck('wip_part_id'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $cycleTimeMaps = $this->scheduleService->cycleTimeMaps($wipPartIds);

        // Pemetaan FG per mesin dari BOM aktif untuk pilihan "Buat WO Baru" di pop-up mesin.
        $fgIdsByMachine = BomItem::query()
            ->whereNotNull('machine_id')
            ->whereNotIn('machine_id', $subconMachineIds)
            ->whereHas('bom', fn ($q) => $q->where('is_active', true))
            ->join('boms', 'boms.id', '=', 'bom_items.bom_id')
            ->select('bom_items.machine_id', 'boms.part_id')
            ->distinct()
            ->get()
            ->groupBy('machine_id')
            ->map(fn ($rows) => $rows->pluck('part_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        $slotsByMachine = $allSlots->groupBy('machine_id');
        $boardMachines = [];
        $availMapByWoMachine = [];

        foreach ($machines as $machine) {
            $mId = (int) $machine->id;
            $initialQdc = [];
            $shiftCounts = [];
            foreach ($dayDates as $dayKey => $planDateStr) {
                $p = $plansByDate->get($planDateStr);
                $md = $p !== null ? $machineDays->get($p->id.'|'.$mId) : null;
                $initialQdc[$dayKey] = (bool) ($md?->initial_qdc ?? false);
                $shiftCounts[$dayKey] = max(1, min(3, (int) ($md?->shift_count ?? 3)));
            }

            // Gabungkan semua WO yang punya slot di mesin ini (dengan sisa > 0) ATAU sudah ter-plan di D/D+1/D+2.
            $machineSlots = ($slotsByMachine->get($mId) ?? collect())->keyBy('work_order_id');
            $machinePlannedWos = $plannedByMachineWo[$mId] ?? [];
            $candidateWoIds = $machineSlots->keys()->merge(array_keys($machinePlannedWos))->unique()->values();

            // Siapkan entri per hari untuk menghitung Start–Finish Time berurutan sesuai Sequence.
            $dayEntries = ['d' => [], 'd1' => [], 'd2' => []];
            $rowMetaByWo = [];

            foreach ($candidateWoIds as $woId) {
                $woId = (int) $woId;
                $slot = $machineSlots->get($woId);
                $dayItems = $machinePlannedWos[$woId] ?? [];

                $firstPi = reset($dayItems) ?: null;
                $wipPartId = $slot['wip_part_id'] ?? ($firstPi?->wip_part_id !== null ? (int) $firstPi->wip_part_id : null);
                $remainingQty = $slot !== null ? (float) $slot['remaining_qty'] : (float) ($firstPi?->workOrder?->qty ?? 0);

                $plannedD = isset($dayItems['d']) ? (float) $dayItems['d']->target_d : 0.0;
                $plannedD1 = isset($dayItems['d1']) ? (float) $dayItems['d1']->target_d : 0.0;
                $plannedD2 = isset($dayItems['d2']) ? (float) $dayItems['d2']->target_d : 0.0;
                $plannedTotal = round($plannedD + $plannedD1 + $plannedD2, 4);
                $availQty = round(max(0.0, $remainingQty - $plannedTotal), 4);

                $availMapByWoMachine[$woId][$mId] = [
                    'remaining_qty' => $remainingQty,
                    'planned_qty' => $plannedTotal,
                    'avail_qty' => $availQty,
                ];

                $ct = $this->scheduleService->resolveCycleTime($machine, $mId, $wipPartId, $cycleTimeMaps);

                $rowMetaByWo[$woId] = [
                    'work_order_id' => $woId,
                    'wo_no' => $slot['wo_no'] ?? $firstPi?->workOrder?->wo_no ?? '',
                    'wo_status' => $slot['wo_status'] ?? $firstPi?->workOrder?->status ?? 'planned',
                    'wo_qty' => $slot['wo_qty'] ?? (float) ($firstPi?->workOrder?->qty ?? 0),
                    'remaining_qty' => $remainingQty,
                    'planned_total' => $plannedTotal,
                    'avail_qty' => $availQty,
                    'fg_part' => $slot['fg_part'] ?? ($firstPi?->fgPart ? [
                        'id' => (int) $firstPi->fgPart->id,
                        'part_number' => $firstPi->fgPart->part_number,
                        'part_name' => $firstPi->fgPart->part_name,
                        'model' => $firstPi->fgPart->model,
                    ] : null),
                    'input_part' => $slot['input_part'] ?? ($firstPi?->inputPart ? [
                        'id' => (int) $firstPi->inputPart->id,
                        'part_number' => $firstPi->inputPart->part_number,
                        'part_name' => $firstPi->inputPart->part_name,
                        'model' => $firstPi->inputPart->model,
                    ] : null),
                    'wip_part' => $slot['wip_part'] ?? ($firstPi?->wipPart ? [
                        'id' => (int) $firstPi->wipPart->id,
                        'part_number' => $firstPi->wipPart->part_number,
                        'part_name' => $firstPi->wipPart->part_name,
                        'model' => $firstPi->wipPart->model,
                    ] : null),
                    'process_name' => $slot['process_name'] ?? $firstPi?->process?->process_name,
                    'cycle_time_seconds' => $ct['cycle_time_seconds'],
                    'qdc_minutes' => $ct['qdc_minutes'],
                    'day_items' => $dayItems,
                ];

                foreach (['d', 'd1', 'd2'] as $dayKey) {
                    if (! isset($dayItems[$dayKey])) {
                        continue;
                    }
                    /** @var ProductionPlanItem $pi */
                    $pi = $dayItems[$dayKey];
                    $dayEntries[$dayKey][] = [
                        'key' => $woId,
                        'sequence' => (int) ($pi->sequence_d > 0 ? $pi->sequence_d : ($pi->sequence > 0 ? $pi->sequence : 1)),
                        'id' => (int) $pi->id,
                        'qty' => (float) $pi->target_d,
                        'wip_part_id' => $wipPartId,
                        'cycle_time_seconds' => $ct['cycle_time_seconds'],
                        'qdc_minutes' => $ct['qdc_minutes'],
                        'apply_qdc' => (bool) $pi->apply_qdc,
                    ];
                }
            }

            $daySchedules = [];
            foreach (['d', 'd1', 'd2'] as $dayKey) {
                $daySchedules[$dayKey] = $this->scheduleService->calculateDaySchedule(
                    $dayDates[$dayKey],
                    $dayEntries[$dayKey],
                    $initialQdc[$dayKey],
                    $shiftCounts[$dayKey],
                );
            }

            $plannedRows = [];
            $modalRows = [];
            $availWoCount = 0;

            foreach ($rowMetaByWo as $woId => $meta) {
                $daysPayload = [];
                $hasAnyPlan = false;
                foreach (['d', 'd1', 'd2'] as $dayKey) {
                    /** @var ProductionPlanItem|null $pi */
                    $pi = $meta['day_items'][$dayKey] ?? null;
                    if ($pi === null) {
                        $daysPayload[$dayKey] = null;

                        continue;
                    }
                    $hasAnyPlan = true;
                    $sched = $daySchedules[$dayKey][$woId] ?? null;
                    $daysPayload[$dayKey] = [
                        'id' => (int) $pi->id,
                        'sequence' => (int) ($pi->sequence_d > 0 ? $pi->sequence_d : ($pi->sequence > 0 ? $pi->sequence : 1)),
                        'qty' => (float) $pi->target_d,
                        'apply_qdc' => (bool) $pi->apply_qdc,
                        'start_time' => $sched['start_time'] ?? null,
                        'end_time' => $sched['end_time'] ?? null,
                        'qdc_applied_minutes' => $sched['qdc_applied_minutes'] ?? 0.0,
                        'duration_seconds' => $sched['duration_seconds'] ?? null,
                        'is_overload' => (bool) ($sched['is_overload'] ?? false),
                    ];
                }

                $rowPayload = [
                    'work_order_id' => $meta['work_order_id'],
                    'wo_no' => $meta['wo_no'],
                    'wo_status' => $meta['wo_status'],
                    'wo_qty' => $meta['wo_qty'],
                    'remaining_qty' => $meta['remaining_qty'],
                    'planned_total' => $meta['planned_total'],
                    'avail_qty' => $meta['avail_qty'],
                    'fg_part' => $meta['fg_part'],
                    'input_part' => $meta['input_part'],
                    'wip_part' => $meta['wip_part'],
                    'process_name' => $meta['process_name'],
                    'cycle_time_seconds' => $meta['cycle_time_seconds'],
                    'qdc_minutes' => $meta['qdc_minutes'],
                    'days' => $daysPayload,
                ];

                $isActiveWo = in_array($meta['wo_status'], ['planned', 'in_progress'], true);
                if ($isActiveWo && $meta['avail_qty'] > 0) {
                    $availWoCount++;
                }

                if ($hasAnyPlan) {
                    $plannedRows[] = $rowPayload;
                }

                if ($hasAnyPlan || ($isActiveWo && $meta['avail_qty'] > 0)) {
                    $modalRows[] = $rowPayload;
                }
            }

            usort($plannedRows, function (array $a, array $b): int {
                $orderOf = function (array $r): array {
                    foreach (['d', 'd1', 'd2'] as $idx => $key) {
                        if ($r['days'][$key] !== null) {
                            return [$idx, (int) $r['days'][$key]['sequence'], $r['work_order_id']];
                        }
                    }

                    return [9, 999999, $r['work_order_id']];
                };

                return $orderOf($a) <=> $orderOf($b);
            });

            usort($modalRows, function (array $a, array $b): int {
                $aPlanned = $a['planned_total'] > 0 ? 0 : 1;
                $bPlanned = $b['planned_total'] > 0 ? 0 : 1;

                return [$aPlanned, $a['fg_part']['part_number'] ?? '', $a['wo_no']]
                    <=> [$bPlanned, $b['fg_part']['part_number'] ?? '', $b['wo_no']];
            });

            $boardMachines[] = [
                'id' => $mId,
                'machine_code' => $machine->machine_code,
                'machine_name' => $machine->machine_name,
                'sequence' => $machine->sequence,
                'avail_wo_count' => $availWoCount,
                'initial_qdc' => $initialQdc,
                'shift_counts' => $shiftCounts,
                'planned_rows' => $plannedRows,
                'modal_rows' => $modalRows,
                'fg_part_ids' => $fgIdsByMachine->get($mId, []),
            ];
        }

        // Panel Avail WO di atas papan: semua WO aktif (planned/in_progress) yang masih punya sisa produksi.
        $slotsByWo = $allSlots->groupBy('work_order_id');
        $availWorkOrders = $activeWorkOrders
            ->filter(fn (WorkOrder $wo): bool => in_array($wo->status, ['planned', 'in_progress'], true))
            ->map(function (WorkOrder $wo) use ($slotsByWo, $availMapByWoMachine, $machinesById): ?array {
                $woSlots = $slotsByWo->get($wo->id) ?? collect();
                $machineSummaries = [];
                $hasRemaining = false;
                $hasUnplanned = false;
                $plannedMachineCount = 0;

                foreach ($woSlots as $slot) {
                    $mId = (int) $slot['machine_id'];
                    if (! $machinesById->has($mId)) {
                        continue;
                    }
                    $stats = $availMapByWoMachine[$wo->id][$mId] ?? [
                        'remaining_qty' => (float) $slot['remaining_qty'],
                        'planned_qty' => 0.0,
                        'avail_qty' => (float) $slot['remaining_qty'],
                    ];

                    if ($stats['remaining_qty'] > 0) {
                        $hasRemaining = true;
                    }
                    if ($stats['planned_qty'] > 0) {
                        $plannedMachineCount++;
                    }
                    if ($stats['avail_qty'] > 0) {
                        $hasUnplanned = true;
                    }

                    $machineSummaries[] = [
                        'machine_id' => $mId,
                        'machine_name' => $slot['machine']['machine_name'] ?? $machinesById->get($mId)?->machine_name ?? '',
                        'remaining_qty' => $stats['remaining_qty'],
                        'planned_qty' => $stats['planned_qty'],
                        'avail_qty' => $stats['avail_qty'],
                    ];
                }

                if (! $hasRemaining || ! $hasUnplanned) {
                    return null;
                }

                return [
                    'id' => (int) $wo->id,
                    'wo_no' => $wo->wo_no,
                    'status' => $wo->status,
                    'qty' => (float) $wo->qty,
                    'planned_date' => $wo->planned_date?->toDateString(),
                    'part' => $wo->part ? [
                        'id' => (int) $wo->part->id,
                        'part_number' => $wo->part->part_number,
                        'part_name' => $wo->part->part_name,
                        'model' => $wo->part->model,
                    ] : null,
                    'machines' => $machineSummaries,
                    'planned_machines_count' => $plannedMachineCount,
                    'total_machines_count' => count($machineSummaries),
                ];
            })
            ->filter()
            ->values();

        $fgParts = Part::query()
            ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['fg']))
            ->where('is_active', true)
            ->orderBy('part_number')
            ->get(['id', 'part_number', 'part_name', 'model', 'part_type_id']);

        $histories = $planIds !== []
            ? ProductionPlanHistory::query()
                ->whereIn('production_plan_id', $planIds)
                ->with('user:id,name')
                ->latest('id')
                ->limit(100)
                ->get()
            : collect();

        $plan = $plansByDate->get($dayDates['d']);

        $dayShiftCapacities = [
            'd' => $this->scheduleService->shiftCapacitiesForDate($dayDates['d']),
            'd1' => $this->scheduleService->shiftCapacitiesForDate($dayDates['d1']),
            'd2' => $this->scheduleService->shiftCapacitiesForDate($dayDates['d2']),
        ];

        return Inertia::render('Production/Plan/Index', [
            'date' => $dayDates['d'],
            'dates' => $dayDates,
            'dayShiftCapacities' => $dayShiftCapacities,
            'plan' => $plan ? $plan->only(['id', 'plan_date', 'notes']) : null,
            'machines' => $boardMachines,
            'availWorkOrders' => $availWorkOrders,
            'fgParts' => $fgParts,
            'histories' => $histories,
            'canManage' => Gate::allows('manage', ProductionPlan::class),
            'canCreateWo' => Gate::allows('create', ProductionPlan::class),
        ]);
    }

    /**
     * Buat WO baru dari Production Plan (mis. dari pop-up Add/Edit Plan).
     * Tidak otomatis menempel ke papan mesin — langsung tersedia sebagai Avail WO.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ProductionPlan::class);

        $data = $request->validate([
            'plan_date' => ['required', 'date'],
            'fg_part_id' => ['required', 'integer', 'exists:parts,id'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $isFg = Part::query()
            ->whereKey($data['fg_part_id'])
            ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['fg']))
            ->exists();

        if (! $isFg) {
            throw ValidationException::withMessages(['fg_part_id' => __('Part yang dipilih bukan FG.')]);
        }

        if ($this->woService->activeBomFor((int) $data['fg_part_id']) === null) {
            throw ValidationException::withMessages(['fg_part_id' => __('Belum ada BOM aktif untuk FG ini.')]);
        }

        $actorId = (int) auth()->id();

        [$workOrder, $warnings] = DB::transaction(function () use ($data, $actorId): array {
            [$workOrderId, $warnings] = $this->woService->createWorkOrder(
                (int) $data['fg_part_id'],
                (float) $data['qty'],
                $data['plan_date'],
                null,
                $actorId,
            );

            return [WorkOrder::query()->findOrFail($workOrderId), $warnings];
        });

        $redirect = redirect()->route('production-plans.index', ['date' => $data['plan_date']]);

        if (count($warnings) > 0) {
            return $redirect->with('error', __('WO :number dibuat (tersedia di Avail WO), tapi ada kekurangan stok: :details', [
                'number' => $workOrder->wo_no,
                'details' => implode(' · ', array_slice($warnings, 0, 5)).(count($warnings) > 5 ? ' …' : ''),
            ]));
        }

        return $redirect->with('success', __('WO :number dibuat dan tersedia di Avail WO.', ['number' => $workOrder->wo_no]));
    }

    /**
     * Simpan rencana 3 hari (D, D+1, D+2) + checkbox QDC Awal untuk satu mesin.
     */
    public function saveMachinePlan(Request $request, Machine $machine): RedirectResponse
    {
        Gate::authorize('manage', ProductionPlan::class);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'initial_qdc' => ['sometimes', 'array'],
            'initial_qdc.d' => ['sometimes', 'boolean'],
            'initial_qdc.d1' => ['sometimes', 'boolean'],
            'initial_qdc.d2' => ['sometimes', 'boolean'],
            'shift_counts' => ['sometimes', 'array'],
            'shift_counts.d' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'shift_counts.d1' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'shift_counts.d2' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'rows' => ['present', 'array'],
            'rows.*.work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
            'rows.*.d_seq' => ['nullable', 'integer', 'min:0'],
            'rows.*.d_qty' => ['nullable', 'numeric', 'min:0'],
            'rows.*.d_qdc' => ['sometimes', 'nullable', 'boolean'],
            'rows.*.d1_seq' => ['nullable', 'integer', 'min:0'],
            'rows.*.d1_qty' => ['nullable', 'numeric', 'min:0'],
            'rows.*.d1_qdc' => ['sometimes', 'nullable', 'boolean'],
            'rows.*.d2_seq' => ['nullable', 'integer', 'min:0'],
            'rows.*.d2_qty' => ['nullable', 'numeric', 'min:0'],
            'rows.*.d2_qdc' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $initialQdc = [
            'd' => (bool) ($data['initial_qdc']['d'] ?? false),
            'd1' => (bool) ($data['initial_qdc']['d1'] ?? false),
            'd2' => (bool) ($data['initial_qdc']['d2'] ?? false),
        ];

        $shiftCounts = [
            'd' => (int) ($data['shift_counts']['d'] ?? 3),
            'd1' => (int) ($data['shift_counts']['d1'] ?? 3),
            'd2' => (int) ($data['shift_counts']['d2'] ?? 3),
        ];

        $this->scheduleService->saveMachinePlan(
            $machine,
            $data['date'],
            $initialQdc,
            $data['rows'],
            (int) auth()->id(),
            $shiftCounts,
        );

        return redirect()
            ->route('production-plans.index', ['date' => $data['date']])
            ->with('success', __('Rencana mesin :machine disimpan.', ['machine' => $machine->machine_name]));
    }

    /**
     * Tempel WO yang sudah dibuat (planned) ke Production Plan (kompatibilitas endpoint).
     */
    public function attachWorkOrder(Request $request): RedirectResponse
    {
        Gate::authorize('create', ProductionPlan::class);

        $data = $request->validate([
            'work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
            'plan_date' => ['required', 'date'],
        ]);

        $workOrder = WorkOrder::query()->findOrFail((int) $data['work_order_id']);

        if (! in_array($workOrder->status, ['planned', 'in_progress'], true)) {
            throw ValidationException::withMessages(['work_order_id' => __('Hanya WO berstatus planned atau in_progress yang bisa dimasukkan ke plan.')]);
        }
        if ($workOrder->planItems()->exists()) {
            throw ValidationException::withMessages(['work_order_id' => __('WO ini sudah ada di Production Plan.')]);
        }

        $this->woService->populatePlanItems($workOrder, $data['plan_date'], (int) auth()->id());

        if (! $workOrder->planItems()->exists()) {
            return redirect()
                ->route('production-plans.index', ['date' => $data['plan_date']])
                ->with('error', __('WO :number tidak punya step routing yang bisa masuk papan (cek BOM/mesin, termasuk step Subcon).', ['number' => $workOrder->wo_no]));
        }

        return redirect()
            ->route('production-plans.index', ['date' => $data['plan_date']])
            ->with('success', __('WO :number masuk ke Production Plan.', ['number' => $workOrder->wo_no]));
    }

    public function update(Request $request, ProductionPlanItem $item): RedirectResponse
    {
        Gate::authorize('update', $item->plan);

        $data = $request->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'wip_part_id' => ['nullable', 'integer', 'exists:parts,id'],
            'target_d' => ['nullable', 'numeric', 'min:0'],
            'target_d1' => ['nullable', 'numeric', 'min:0'],
            'target_d2' => ['nullable', 'numeric', 'min:0'],
        ]);

        $before = $this->itemSnapshot($item);
        $item->update([
            'machine_id' => $data['machine_id'],
            'wip_part_id' => $data['wip_part_id'] ?? null,
            'target_d' => $data['target_d'] ?? null,
            'target_d1' => $data['target_d1'] ?? null,
            'target_d2' => $data['target_d2'] ?? null,
            'updated_by' => auth()->id(),
        ]);
        $this->recordHistory($item, 'item_updated', $before, $this->itemSnapshot($item));

        return redirect()
            ->route('production-plans.index', ['date' => $item->plan?->plan_date?->toDateString()])
            ->with('success', __('Baris Production Plan diperbarui.'));
    }

    /** Simpan target dan sequence D / D+1 / D+2 banyak baris sekaligus (kompatibilitas endpoint). */
    public function updateTargets(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:production_plan_items,id'],
            'items.*.target_d' => ['nullable', 'numeric', 'min:0'],
            'items.*.target_d1' => ['nullable', 'numeric', 'min:0'],
            'items.*.target_d2' => ['nullable', 'numeric', 'min:0'],
            'items.*.sequence_d' => ['sometimes', 'integer', 'min:0'],
            'items.*.sequence_d1' => ['sometimes', 'integer', 'min:0'],
            'items.*.sequence_d2' => ['sometimes', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $request) {
            $itemIds = collect($data['items'])->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $planItems = ProductionPlanItem::query()
                ->whereIn('id', $itemIds)
                ->with('plan')
                ->get()
                ->keyBy('id');

            foreach ($data['items'] as $row) {
                $item = $planItems->get((int) $row['id']);
                if ($item === null) {
                    continue;
                }

                Gate::authorize('update', $item->plan);

                $before = $this->targetSnapshot($item);
                $item->update([
                    'target_d' => $row['target_d'] ?? null,
                    'target_d1' => $row['target_d1'] ?? null,
                    'target_d2' => $row['target_d2'] ?? null,
                    'sequence_d' => $row['sequence_d'] ?? $item->sequence_d,
                    'sequence_d1' => $row['sequence_d1'] ?? $item->sequence_d1,
                    'sequence_d2' => $row['sequence_d2'] ?? $item->sequence_d2,
                    'updated_by' => $request->user()?->id,
                ]);
                $this->recordHistory($item, 'targets_updated', $before, $this->targetSnapshot($item), $request->user()?->id);
            }
        });

        return back()->with('success', __('Target Production Plan disimpan.'));
    }

    public function reorder(Request $request): RedirectResponse
    {
        Gate::authorize('reorder', ProductionPlan::class);

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:production_plan_items,id'],
            'items.*.sequence' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($data) {
            $itemIds = collect($data['items'])->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $planItems = ProductionPlanItem::query()
                ->whereIn('id', $itemIds)
                ->with('plan')
                ->get()
                ->keyBy('id');

            foreach ($data['items'] as $row) {
                $item = $planItems->get((int) $row['id']);
                if ($item === null) {
                    continue;
                }

                Gate::authorize('update', $item->plan);
                $before = ['sequence' => $item->sequence];
                $item->update([
                    'sequence' => $row['sequence'],
                    'updated_by' => auth()->id(),
                ]);
                $this->recordHistory($item, 'sequence_updated', $before, ['sequence' => $item->sequence]);
            }
        });

        return back()->with('success', __('Urutan diperbarui.'));
    }

    public function detach(ProductionPlanItem $item): RedirectResponse
    {
        Gate::authorize('delete', $item->plan);

        $planDate = $item->plan?->plan_date?->toDateString();
        $woNo = $item->workOrder?->wo_no;

        $this->recordHistory($item, 'item_detached', $this->itemSnapshot($item), null);

        $item->delete();

        $message = $woNo
            ? __('WO :number dilepas dari Production Plan (WO tetap ada).', ['number' => $woNo])
            : __('Baris dilepas dari Production Plan.');

        return redirect()
            ->route('production-plans.index', ['date' => $planDate])
            ->with('success', $message);
    }

    /** @return array{machine_id:int|null,wip_part_id:int|null,target_d:float|null,target_d1:float|null,target_d2:float|null,sequence_d:int,sequence_d1:int,sequence_d2:int} */
    private function itemSnapshot(ProductionPlanItem $item): array
    {
        return [
            'machine_id' => $item->machine_id,
            'wip_part_id' => $item->wip_part_id,
            ...$this->targetSnapshot($item),
        ];
    }

    /** @return array{target_d:float|null,target_d1:float|null,target_d2:float|null,sequence_d:int,sequence_d1:int,sequence_d2:int} */
    private function targetSnapshot(ProductionPlanItem $item): array
    {
        return [
            'target_d' => $item->target_d,
            'target_d1' => $item->target_d1,
            'target_d2' => $item->target_d2,
            'sequence_d' => $item->sequence_d,
            'sequence_d1' => $item->sequence_d1,
            'sequence_d2' => $item->sequence_d2,
        ];
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    private function recordHistory(ProductionPlanItem $item, string $event, ?array $before, ?array $after, ?int $userId = null): void
    {
        if ($item->production_plan_id === null) {
            return;
        }

        ProductionPlanHistory::create([
            'production_plan_id' => $item->production_plan_id,
            'production_plan_item_id' => $item->id,
            'event' => $event,
            'before' => $before,
            'after' => $after,
            'user_id' => $userId ?? auth()->id(),
        ]);
    }
}
