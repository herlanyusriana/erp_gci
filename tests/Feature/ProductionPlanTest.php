<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\MachineCycleTime;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanItem;
use App\Models\ProductionResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ProductionPlanScheduleService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductionPlanTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    private function fg(): Part
    {
        return Part::where('part_number', 'AAN30056405')->firstOrFail();
    }

    private function createWo(float $qty = 500, ?string $plannedDate = null, ?Part $fg = null): WorkOrder
    {
        $part = $fg ?? $this->fg();

        $this->post(route('work-orders.store'), [
            'part_id' => $part->id,
            'qty' => $qty,
            'planned_date' => $plannedDate ?? now()->toDateString(),
        ])->assertRedirect();

        return WorkOrder::latest('id')->firstOrFail();
    }

    private function clearStocksForParts(array $partNumbers): void
    {
        $partIds = Part::whereIn('part_number', $partNumbers)->pluck('id');
        if ($partIds->isNotEmpty()) {
            PartStock::whereIn('part_id', $partIds)->delete();
        }
    }

    public function test_new_and_released_wo_do_not_auto_populate_machine_rows_and_appear_as_avail_wo(): void
    {
        $date = '2026-11-23';
        $this->clearStocksForParts(['AAN30056405-WIP5', 'AAN30056405-WIP6', 'AAN30056405-WIP7']);

        $tplKukil = Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();
        $assy1 = Machine::where('machine_name', 'ASSY. TPL COMP BASE 1')->firstOrFail();
        $autoCaulking = Machine::where('machine_name', 'AUTO CAULKING')->firstOrFail();
        $assy2 = Machine::where('machine_name', 'ASSY. TPL COMP BASE 2')->firstOrFail();

        $baselineCounts = collect(
            $this->get(route('production-plans.index', ['date' => $date]))
                ->assertOk()
                ->inertiaProps('machines')
        )->mapWithKeys(fn (array $m): array => [(int) $m['id'] => (int) $m['avail_wo_count']])->all();

        $wo = $this->createWo(500, $date);

        $this->assertSame(0, $wo->planItems()->count());

        $this->post(route('work-orders.release', $wo))->assertRedirect();
        $this->assertSame(0, $wo->planItems()->count());

        $this->get(route('production-plans.index', ['date' => $date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Production/Plan/Index')
                ->where('date', $date)
                ->where('availWorkOrders', function ($list) use ($wo, $tplKukil, $assy1, $autoCaulking, $assy2) {
                    $entry = collect($list)->firstWhere('id', $wo->id);
                    if ($entry === null || ($entry['part']['model'] ?? null) === null) {
                        return false;
                    }

                    $chipMachineIds = collect($entry['machines'] ?? [])->pluck('machine_id')->map(fn ($id): int => (int) $id);

                    return $chipMachineIds->contains($tplKukil->id)
                        && ! $chipMachineIds->contains($assy1->id)
                        && ! $chipMachineIds->contains($autoCaulking->id)
                        && ! $chipMachineIds->contains($assy2->id);
                })
                ->where('machines', function ($machines) use ($tplKukil, $assy1, $autoCaulking, $assy2, $baselineCounts, $wo) {
                    $byId = collect($machines)->keyBy('id');
                    $tpl = $byId->get($tplKukil->id);
                    $p2 = $byId->get($assy1->id);
                    $p3 = $byId->get($autoCaulking->id);
                    $p4 = $byId->get($assy2->id);

                    if ($tpl === null || $p2 === null || $p3 === null || $p4 === null) {
                        return false;
                    }

                    $tplModalRow = collect($tpl['modal_rows'] ?? [])->firstWhere('work_order_id', $wo->id);

                    $downstreamUnlocked = collect([$p2, $p3, $p4])->contains(function (array $m) use ($wo, $baselineCounts): bool {
                        $hasModalWo = collect($m['modal_rows'] ?? [])->contains('work_order_id', $wo->id);
                        $hasPlannedWo = collect($m['planned_rows'] ?? [])->contains('work_order_id', $wo->id);
                        $countIncreased = (int) $m['avail_wo_count'] !== ($baselineCounts[(int) $m['id']] ?? 0);

                        return $hasModalWo || $hasPlannedWo || $countIncreased;
                    });

                    return ! collect($tpl['planned_rows'] ?? [])->contains('work_order_id', $wo->id)
                        && (int) $tpl['avail_wo_count'] >= 1
                        && (int) $tpl['avail_wo_count'] === ($baselineCounts[$tplKukil->id] ?? 0) + 1
                        && $tplModalRow !== null
                        && $tplModalRow['has_upstream'] === false
                        && (float) $tplModalRow['avail_qty'] === 500.0
                        && ! $downstreamUnlocked;
                }));
    }

    public function test_creating_wo_from_production_plan_modal_adds_avail_wo_without_auto_scheduling(): void
    {
        $date = '2026-11-23';

        $this->post(route('production-plans.store'), [
            'plan_date' => $date,
            'fg_part_id' => $this->fg()->id,
            'qty' => 250,
        ])->assertRedirect(route('production-plans.index', ['date' => $date]));

        $wo = WorkOrder::latest('id')->firstOrFail();
        $this->assertSame('planned', $wo->status);
        $this->assertSame(0, $wo->planItems()->count());

        $this->get(route('production-plans.index', ['date' => $date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availWorkOrders', fn ($list) => collect($list)->contains('id', $wo->id)));
    }

    public function test_save_machine_plan_updates_avail_wo_independently_per_machine_and_rolls_calendar_days(): void
    {
        $monday = '2026-11-23';
        $tuesday = '2026-11-24';
        $this->clearStocksForParts(['AAN30056405-WIP5', 'AAN30056405-WIP6', 'AAN30056405-WIP7']);
        $wo = $this->createWo(500, $monday);

        $tplKukil = Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();
        $assy1 = Machine::where('machine_name', 'ASSY. TPL COMP BASE 1')->firstOrFail();
        $autoCaulking = Machine::where('machine_name', 'AUTO CAULKING')->firstOrFail();

        // Rencanakan 200 di D (Senin) + 150 di D+1 (Selasa) hanya pada TPL KUKIL.
        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'initial_qdc' => ['d' => false, 'd1' => true, 'd2' => false],
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => 1,
                    'd_qty' => 200,
                    'd1_seq' => 2,
                    'd1_qty' => 150,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertRedirect(route('production-plans.index', ['date' => $monday]));

        // Pada papan Senin: TPL KUKIL sisa avail 150 (500 - 350), ASSY 1 terbuka sesuai suplai kumulatif (200 / 350 / 350), AUTO CAULKING tetap terkunci.
        $this->get(route('production-plans.index', ['date' => $monday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($tplKukil, $assy1, $autoCaulking, $wo) {
                    $byId = collect($machines)->keyBy('id');
                    $tpl = $byId->get($tplKukil->id);
                    $p2 = $byId->get($assy1->id);
                    $caulk = $byId->get($autoCaulking->id);

                    $tplRow = collect($tpl['planned_rows'] ?? [])->firstWhere('work_order_id', $wo->id);
                    $p2Modal = collect($p2['modal_rows'] ?? [])->firstWhere('work_order_id', $wo->id);
                    $caulkModal = collect($caulk['modal_rows'] ?? [])->firstWhere('work_order_id', $wo->id);

                    return $tplRow !== null
                        && (float) $tplRow['avail_qty'] === 150.0
                        && (float) $tplRow['days']['d']['qty'] === 200.0
                        && (int) $tplRow['days']['d']['sequence'] === 1
                        && (float) $tplRow['days']['d1']['qty'] === 150.0
                        && (int) $tplRow['days']['d1']['sequence'] === 2
                        && $tplRow['days']['d2'] === null
                        && ! collect($p2['planned_rows'] ?? [])->contains('work_order_id', $wo->id)
                        && $p2Modal !== null
                        && $p2Modal['has_upstream'] === true
                        && (float) $p2Modal['day_caps']['d'] === 200.0
                        && (float) $p2Modal['day_caps']['d1'] === 350.0
                        && (float) $p2Modal['day_caps']['d2'] === 350.0
                        && (float) $p2Modal['avail_qty'] === 350.0
                        && ! collect($caulk['planned_rows'] ?? [])->contains('work_order_id', $wo->id)
                        && $caulkModal === null;
                }));

        // Pada papan Selasa: rencana D+1 kemarin (150, seq 2, initial_qdc=true) otomatis bergeser jadi D hari ini!
        $this->get(route('production-plans.index', ['date' => $tuesday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($tplKukil, $wo) {
                    $tpl = collect($machines)->firstWhere('id', $tplKukil->id);
                    $tplRow = collect($tpl['planned_rows'])->firstWhere('work_order_id', $wo->id);

                    return $tpl !== null
                        && $tpl['initial_qdc']['d'] === true
                        && $tplRow !== null
                        && (float) $tplRow['days']['d']['qty'] === 150.0
                        && (int) $tplRow['days']['d']['sequence'] === 2
                        && $tplRow['days']['d1'] === null;
                }));
    }

    public function test_save_machine_plan_blocks_when_total_qty_exceeds_remaining_wo_qty(): void
    {
        $monday = '2026-11-23';
        $wo = $this->createWo(100, $monday);
        $tplKukil = Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();

        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'initial_qdc' => ['d' => false, 'd1' => false, 'd2' => false],
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => 1,
                    'd_qty' => 60,
                    'd1_seq' => 1,
                    'd1_qty' => 50,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertSessionHasErrors('rows.0.qty');

        $this->assertSame(0, ProductionPlanItem::where('work_order_id', $wo->id)->count());
    }

    public function test_save_machine_plan_deletes_day_item_when_qty_cleared(): void
    {
        $monday = '2026-11-23';
        $wo = $this->createWo(200, $monday);
        $tplKukil = Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();

        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'initial_qdc' => ['d' => false, 'd1' => false, 'd2' => false],
            'rows' => [
                ['work_order_id' => $wo->id, 'd_seq' => 1, 'd_qty' => 120, 'd1_seq' => null, 'd1_qty' => null, 'd2_seq' => null, 'd2_qty' => null],
            ],
        ])->assertRedirect();

        $this->assertSame(1, ProductionPlanItem::where('work_order_id', $wo->id)->where('machine_id', $tplKukil->id)->count());

        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'initial_qdc' => ['d' => false, 'd1' => false, 'd2' => false],
            'rows' => [
                ['work_order_id' => $wo->id, 'd_seq' => null, 'd_qty' => null, 'd1_seq' => null, 'd1_qty' => null, 'd2_seq' => null, 'd2_qty' => null],
            ],
        ])->assertRedirect();

        $this->assertSame(0, ProductionPlanItem::where('work_order_id', $wo->id)->where('machine_id', $tplKukil->id)->count());
    }

    public function test_start_finish_time_respects_breaks_initial_qdc_rollover_and_changeover_qdc(): void
    {
        $service = app(ProductionPlanScheduleService::class);
        $monday = '2026-11-23'; // Shift 1: 07:30-15:30, break 12:00-13:00

        $entries = [
            // Seq 1: 240 menit produksi (4 jam) + QDC 30 menit (opsional via initial_qdc)
            ['key' => 'wo1', 'sequence' => 1, 'id' => 1, 'qty' => 2400, 'wip_part_id' => 101, 'cycle_time_seconds' => 6.0, 'qdc_minutes' => 30.0],
            // Seq 2: 90 menit produksi + QDC 30 menit (ganti part 101 -> 102)
            ['key' => 'wo2', 'sequence' => 2, 'id' => 2, 'qty' => 900, 'wip_part_id' => 102, 'cycle_time_seconds' => 6.0, 'qdc_minutes' => 30.0],
        ];

        // 1. Roll over (initial_qdc = false):
        //    wo1: 07:30 + 240m = 11:30 (tanpa QDC)
        //    wo2: 11:30 + 30m QDC = 12:00 (kena istirahat 12:00-13:00 -> lompat ke 13:00) + 90m prod = 14:30
        $rollOver = $service->calculateDaySchedule($monday, $entries, false);
        $this->assertSame('07:30', $rollOver['wo1']['start_time']);
        $this->assertSame('11:30', $rollOver['wo1']['end_time']);
        $this->assertSame(0.0, $rollOver['wo1']['qdc_applied_minutes']);
        $this->assertSame('11:30', $rollOver['wo2']['start_time']);
        $this->assertSame('14:30', $rollOver['wo2']['end_time']);
        $this->assertSame(30.0, $rollOver['wo2']['qdc_applied_minutes']);
        $this->assertFalse($rollOver['wo2']['is_overload']);

        // 2. Dengan QDC Awal (initial_qdc = true):
        //    wo1: 07:30 + 30m QDC + 240m prod = 12:00
        //    wo2: karena 12:00 masuk istirahat, start lompat ke 13:00 + 30m QDC + 90m prod = 15:00
        $withInitQdc = $service->calculateDaySchedule($monday, $entries, true);
        $this->assertSame('07:30', $withInitQdc['wo1']['start_time']);
        $this->assertSame('12:00', $withInitQdc['wo1']['end_time']);
        $this->assertSame(30.0, $withInitQdc['wo1']['qdc_applied_minutes']);
        $this->assertSame('13:00', $withInitQdc['wo2']['start_time']);
        $this->assertSame('15:00', $withInitQdc['wo2']['end_time']);
    }

    public function test_start_finish_time_handles_friday_break_saturday_gap_and_overload(): void
    {
        $service = app(ProductionPlanScheduleService::class);

        // Jumat (2026-11-27): istirahat Shift 1 = 11:30-13:00 (90 menit)
        $friday = $service->calculateDaySchedule('2026-11-27', [
            ['key' => 'fri', 'sequence' => 1, 'id' => 1, 'qty' => 3000, 'wip_part_id' => 1, 'cycle_time_seconds' => 6.0, 'qdc_minutes' => null], // 300m = 5h -> 07:30-11:30 (4h) + 13:00-14:00 (1h)
        ], false);
        $this->assertSame('07:30', $friday['fri']['start_time']);
        $this->assertSame('14:00', $friday['fri']['end_time']);
        $this->assertFalse($friday['fri']['is_overload']);

        // Sabtu (2026-11-28): kapasitas 15 jam (07:30-17:30 + 19:00-00:00). 16 jam -> overload!
        $saturday = $service->calculateDaySchedule('2026-11-28', [
            ['key' => 'sat', 'sequence' => 1, 'id' => 1, 'qty' => 9600, 'wip_part_id' => 1, 'cycle_time_seconds' => 6.0, 'qdc_minutes' => null], // 960m = 16h
        ], false);
        $this->assertSame('07:30', $saturday['sat']['start_time']);
        $this->assertSame('01:00', $saturday['sat']['end_time']);
        $this->assertTrue($saturday['sat']['is_overload']);
    }

    public function test_subcon_machines_are_hidden_and_machines_follow_master_sequence(): void
    {
        $date = '2026-11-23';
        $mA = Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();
        $mB = Machine::where('machine_name', 'AUTO CAULKING')->firstOrFail();
        $mB->update(['sequence' => 1]);
        $mA->update(['sequence' => 2]);

        $this->get(route('production-plans.index', ['date' => $date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($mA, $mB) {
                    $list = collect($machines);
                    $hasSubcon = $list->contains(fn ($m) => str_contains(strtolower((string) $m['machine_name']), 'subcon'));
                    $idxA = $list->search(fn ($m) => $m['id'] === $mA->id);
                    $idxB = $list->search(fn ($m) => $m['id'] === $mB->id);

                    return ! $hasSubcon && $idxB !== false && $idxA !== false && $idxB < $idxA;
                }));
    }

    public function test_production_result_reduces_remaining_and_avail_wo_qty(): void
    {
        $monday = '2026-11-23';
        $wo = $this->createWo(200, $monday);
        $tplKukil = Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();
        $wip5 = Part::where('part_number', 'AAN30056405-WIP5')->firstOrFail();

        ProductionResult::create([
            'work_order_id' => $wo->id,
            'parent_part_id' => $wip5->id,
            'qty_good' => 80,
            'qty_reject' => 0,
            'result_date' => $monday,
        ]);

        // Sisa produksi tinggal 120 → mencoba plan 130 harus ditolak!
        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'initial_qdc' => ['d' => false, 'd1' => false, 'd2' => false],
            'rows' => [
                ['work_order_id' => $wo->id, 'd_seq' => 1, 'd_qty' => 130, 'd1_seq' => null, 'd1_qty' => null, 'd2_seq' => null, 'd2_qty' => null],
            ],
        ])->assertSessionHasErrors('rows.0.qty');

        // Plan 100 diterima → avail tersisa 20.
        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'initial_qdc' => ['d' => false, 'd1' => false, 'd2' => false],
            'rows' => [
                ['work_order_id' => $wo->id, 'd_seq' => 1, 'd_qty' => 100, 'd1_seq' => null, 'd1_qty' => null, 'd2_seq' => null, 'd2_qty' => null],
            ],
        ])->assertRedirect();

        $this->get(route('production-plans.index', ['date' => $monday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($tplKukil, $wo) {
                    $tpl = collect($machines)->firstWhere('id', $tplKukil->id);
                    $row = collect($tpl['planned_rows'])->firstWhere('work_order_id', $wo->id);

                    return $row !== null
                        && (float) $row['remaining_qty'] === 120.0
                        && (float) $row['avail_qty'] === 20.0;
                }));
    }

    public function test_rebuild_command_and_legacy_endpoints_still_work(): void
    {
        $wo = $this->createWo(50, now()->toDateString());
        $this->post(route('production-plans.attach'), [
            'work_order_id' => $wo->id,
            'plan_date' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertGreaterThan(0, $wo->planItems()->count());

        $plan = ProductionPlan::whereDate('plan_date', now()->toDateString())->firstOrFail();
        $this->assertGreaterThan(0, $plan->items()->count());
    }

    public function test_save_machine_plan_persists_shift_count_and_per_row_qdc_and_detects_shift_overload(): void
    {
        $monday = '2026-11-23';
        $wo = $this->createWo(5000, $monday);
        $tplKukil = Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();
        $wip5 = Part::where('part_number', 'AAN30056405-WIP5')->firstOrFail();

        MachineCycleTime::updateOrCreate(
            ['machine_id' => $tplKukil->id, 'part_id' => $wip5->id],
            ['cycle_time_seconds' => 6, 'qdc_minutes' => 30, 'is_active' => true],
        );

        // 1 Shift Senin = 7 jam (420 mnt). 4500 pcs × 6s = 450 mnt + 30 mnt QDC = 480 mnt -> overload pada 1 Shift!
        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'shift_counts' => ['d' => 1, 'd1' => 2, 'd2' => 3],
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => 1,
                    'd_qty' => 4500,
                    'd_qdc' => true,
                    'd1_seq' => 1,
                    'd1_qty' => 500,
                    'd1_qdc' => false,
                    'd2_seq' => null,
                    'd2_qty' => null,
                    'd2_qdc' => false,
                ],
            ],
        ])->assertRedirect();

        $this->get(route('production-plans.index', ['date' => $monday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dayShiftCapacities.d.1', 420)
                ->where('dayShiftCapacities.d.3', 1260)
                ->where('machines', function ($machines) use ($tplKukil, $wo) {
                    $tpl = collect($machines)->firstWhere('id', $tplKukil->id);
                    $row = collect($tpl['planned_rows'])->firstWhere('work_order_id', $wo->id);

                    return $tpl !== null
                        && (int) $tpl['shift_counts']['d'] === 1
                        && (int) $tpl['shift_counts']['d1'] === 2
                        && $row !== null
                        && $row['days']['d']['apply_qdc'] === true
                        && (float) $row['days']['d']['qdc_applied_minutes'] === 30.0
                        && $row['days']['d']['is_overload'] === true
                        && $row['days']['d1']['apply_qdc'] === false
                        && (float) $row['days']['d1']['qdc_applied_minutes'] === 0.0
                        && $row['days']['d1']['is_overload'] === false;
                }));
    }

    public function test_downstream_machine_enforces_cumulative_day_caps_and_cascades_to_process_3(): void
    {
        $monday = '2026-11-23';
        $this->clearStocksForParts(['AAN30056405-WIP5', 'AAN30056405-WIP6', 'AAN30056405-WIP7']);
        $wo = $this->createWo(500, $monday);

        $tplKukil = Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();
        $assy1 = Machine::where('machine_name', 'ASSY. TPL COMP BASE 1')->firstOrFail();
        $autoCaulking = Machine::where('machine_name', 'AUTO CAULKING')->firstOrFail();

        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => null,
                    'd_qty' => null,
                    'd1_seq' => 1,
                    'd1_qty' => 300,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertRedirect(route('production-plans.index', ['date' => $monday]));

        $this->get(route('production-plans.index', ['date' => $monday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($assy1, $autoCaulking, $wo) {
                    $byId = collect($machines)->keyBy('id');
                    $p2Modal = collect($byId->get($assy1->id)['modal_rows'] ?? [])->firstWhere('work_order_id', $wo->id);
                    $p3Modal = collect($byId->get($autoCaulking->id)['modal_rows'] ?? [])->firstWhere('work_order_id', $wo->id);

                    return $p2Modal !== null
                        && $p2Modal['has_upstream'] === true
                        && (float) $p2Modal['day_caps']['d'] === 0.0
                        && (float) $p2Modal['day_caps']['d1'] === 300.0
                        && (float) $p2Modal['day_caps']['d2'] === 300.0
                        && (float) $p2Modal['avail_qty'] === 300.0
                        && $p3Modal === null;
                }));

        $this->put(route('production-plans.machines.save', $assy1), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => 1,
                    'd_qty' => 100,
                    'd1_seq' => null,
                    'd1_qty' => null,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertSessionHasErrors('rows.0.qty');

        $this->assertSame(0, ProductionPlanItem::where('work_order_id', $wo->id)->where('machine_id', $assy1->id)->count());

        $this->put(route('production-plans.machines.save', $assy1), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => null,
                    'd_qty' => null,
                    'd1_seq' => 1,
                    'd1_qty' => 350,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertSessionHasErrors('rows.0.qty');

        $this->assertSame(0, ProductionPlanItem::where('work_order_id', $wo->id)->where('machine_id', $assy1->id)->count());

        $this->put(route('production-plans.machines.save', $assy1), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => null,
                    'd_qty' => null,
                    'd1_seq' => 1,
                    'd1_qty' => 250,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertRedirect(route('production-plans.index', ['date' => $monday]));

        $this->assertSame(
            250.0,
            (float) ProductionPlanItem::where('work_order_id', $wo->id)->where('machine_id', $assy1->id)->value('target_d'),
        );

        $this->get(route('production-plans.index', ['date' => $monday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($autoCaulking, $wo) {
                    $p3 = collect($machines)->firstWhere('id', $autoCaulking->id);
                    $p3Modal = collect($p3['modal_rows'] ?? [])->firstWhere('work_order_id', $wo->id);

                    return $p3Modal !== null
                        && $p3Modal['has_upstream'] === true
                        && (float) $p3Modal['day_caps']['d'] === 0.0
                        && (float) $p3Modal['day_caps']['d1'] === 250.0
                        && (float) $p3Modal['day_caps']['d2'] === 250.0
                        && (float) $p3Modal['avail_qty'] === 250.0;
                }));
    }

    public function test_physical_wip_stock_unlocks_downstream_on_day_d_without_double_counting_production_result(): void
    {
        $monday = '2026-11-23';
        $this->clearStocksForParts(['AAN30056405-WIP5', 'AAN30056405-WIP6', 'AAN30056405-WIP7']);
        $wo = $this->createWo(500, $monday);

        $tplKukil = Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();
        $assy1 = Machine::where('machine_name', 'ASSY. TPL COMP BASE 1')->firstOrFail();
        $wip5 = Part::where('part_number', 'AAN30056405-WIP5')->firstOrFail();

        PartStock::create([
            'part_id' => $wip5->id,
            'tag' => 'TEST-WIP5-180',
            'qty' => 180,
            'qty_unit' => 'PCS',
            'received_at' => now(),
        ]);

        $this->get(route('production-plans.index', ['date' => $monday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($assy1, $wo) {
                    $p2 = collect($machines)->firstWhere('id', $assy1->id);
                    $p2Modal = collect($p2['modal_rows'] ?? [])->firstWhere('work_order_id', $wo->id);

                    return $p2Modal !== null
                        && $p2Modal['has_upstream'] === true
                        && (float) $p2Modal['day_caps']['d'] === 180.0
                        && (float) $p2Modal['day_caps']['d1'] === 180.0
                        && (float) $p2Modal['day_caps']['d2'] === 180.0
                        && (float) $p2Modal['avail_qty'] === 180.0;
                }));

        ProductionResult::create([
            'work_order_id' => $wo->id,
            'parent_part_id' => $wip5->id,
            'qty_good' => 180,
            'qty_reject' => 0,
            'result_date' => $monday,
        ]);

        $this->get(route('production-plans.index', ['date' => $monday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($assy1, $wo) {
                    $p2 = collect($machines)->firstWhere('id', $assy1->id);
                    $p2Modal = collect($p2['modal_rows'] ?? [])->firstWhere('work_order_id', $wo->id);

                    return $p2Modal !== null
                        && (float) $p2Modal['day_caps']['d'] === 180.0
                        && (float) $p2Modal['day_caps']['d1'] === 180.0
                        && (float) $p2Modal['day_caps']['d2'] === 180.0
                        && (float) $p2Modal['avail_qty'] === 180.0;
                }));

        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => null,
                    'd_qty' => null,
                    'd1_seq' => 1,
                    'd1_qty' => 200,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertRedirect(route('production-plans.index', ['date' => $monday]));

        $this->get(route('production-plans.index', ['date' => $monday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($assy1, $wo) {
                    $p2 = collect($machines)->firstWhere('id', $assy1->id);
                    $p2Modal = collect($p2['modal_rows'] ?? [])->firstWhere('work_order_id', $wo->id);

                    return $p2Modal !== null
                        && (float) $p2Modal['day_caps']['d'] === 180.0
                        && (float) $p2Modal['day_caps']['d1'] === 380.0
                        && (float) $p2Modal['day_caps']['d2'] === 380.0
                        && (float) $p2Modal['avail_qty'] === 380.0;
                }));
    }

    public function test_upstream_plan_cannot_be_reduced_or_cleared_below_downstream_planned_qty(): void
    {
        $monday = '2026-11-23';
        $this->clearStocksForParts(['AAN30056405-WIP5', 'AAN30056405-WIP6', 'AAN30056405-WIP7']);
        $wo = $this->createWo(500, $monday);

        $tplKukil = Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();
        $assy1 = Machine::where('machine_name', 'ASSY. TPL COMP BASE 1')->firstOrFail();

        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => 1,
                    'd_qty' => 400,
                    'd1_seq' => null,
                    'd1_qty' => null,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertRedirect(route('production-plans.index', ['date' => $monday]));

        $this->put(route('production-plans.machines.save', $assy1), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => 1,
                    'd_qty' => 300,
                    'd1_seq' => null,
                    'd1_qty' => null,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertRedirect(route('production-plans.index', ['date' => $monday]));

        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => 1,
                    'd_qty' => 200,
                    'd1_seq' => null,
                    'd1_qty' => null,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertSessionHasErrors('rows.0.qty');

        $this->assertSame(
            400.0,
            (float) ProductionPlanItem::where('work_order_id', $wo->id)->where('machine_id', $tplKukil->id)->value('target_d'),
        );

        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => null,
                    'd_qty' => null,
                    'd1_seq' => null,
                    'd1_qty' => null,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertSessionHasErrors('rows.0.qty');

        $this->assertSame(
            400.0,
            (float) ProductionPlanItem::where('work_order_id', $wo->id)->where('machine_id', $tplKukil->id)->value('target_d'),
        );

        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => 1,
                    'd_qty' => 300,
                    'd1_seq' => null,
                    'd1_qty' => null,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertRedirect(route('production-plans.index', ['date' => $monday]));

        $this->assertSame(
            300.0,
            (float) ProductionPlanItem::where('work_order_id', $wo->id)->where('machine_id', $tplKukil->id)->value('target_d'),
        );

        $this->put(route('production-plans.machines.save', $assy1), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => 1,
                    'd_qty' => 150,
                    'd1_seq' => null,
                    'd1_qty' => null,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertRedirect(route('production-plans.index', ['date' => $monday]));

        $this->put(route('production-plans.machines.save', $tplKukil), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => 1,
                    'd_qty' => 200,
                    'd1_seq' => null,
                    'd1_qty' => null,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertRedirect(route('production-plans.index', ['date' => $monday]));

        $this->assertSame(
            150.0,
            (float) ProductionPlanItem::where('work_order_id', $wo->id)->where('machine_id', $assy1->id)->value('target_d'),
        );
        $this->assertSame(
            200.0,
            (float) ProductionPlanItem::where('work_order_id', $wo->id)->where('machine_id', $tplKukil->id)->value('target_d'),
        );
    }

    public function test_subcon_intermediate_step_does_not_deadlock_downstream_internal_machine(): void
    {
        $monday = '2026-11-23';
        $this->clearStocksForParts(['PINHC0101', 'AEH76183701-WIP1', 'AEH76183702-WIP3']);

        $aeh = Part::where('part_number', 'AEH76183702')->firstOrFail();
        $aida = Machine::where('machine_name', 'AIDA 60 TON')->firstOrFail();
        $wo = $this->createWo(200, $monday, $aeh);

        $this->get(route('production-plans.index', ['date' => $monday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($aida, $wo) {
                    $m = collect($machines)->firstWhere('id', $aida->id);
                    $row = collect($m['modal_rows'] ?? [])->firstWhere('work_order_id', $wo->id);

                    return $m !== null
                        && $row !== null
                        && $row['has_upstream'] === false
                        && (float) $row['avail_qty'] === 200.0
                        && (float) $row['day_caps']['d'] === 200.0
                        && (float) $row['day_caps']['d1'] === 200.0
                        && (float) $row['day_caps']['d2'] === 200.0;
                }));

        $this->put(route('production-plans.machines.save', $aida), [
            'date' => $monday,
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => 1,
                    'd_qty' => 200,
                    'd1_seq' => null,
                    'd1_qty' => null,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertRedirect(route('production-plans.index', ['date' => $monday]));

        $this->assertSame(
            200.0,
            (float) ProductionPlanItem::where('work_order_id', $wo->id)->where('machine_id', $aida->id)->value('target_d'),
        );
    }
}
