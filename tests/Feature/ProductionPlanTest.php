<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\Part;
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

    public function test_new_and_released_wo_do_not_auto_populate_machine_rows_and_appear_as_avail_wo(): void
    {
        $date = '2026-11-23';
        $wo = $this->createWo(500, $date);

        $this->assertSame(0, $wo->planItems()->count());

        $this->post(route('work-orders.release', $wo))->assertRedirect();
        $this->assertSame(0, $wo->planItems()->count());

        $tplKukil = Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();
        $autoCaulking = Machine::where('machine_name', 'AUTO CAULKING')->firstOrFail();

        $this->get(route('production-plans.index', ['date' => $date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Production/Plan/Index')
                ->where('date', $date)
                ->where('availWorkOrders', function ($list) use ($wo) {
                    $entry = collect($list)->firstWhere('id', $wo->id);

                    return $entry !== null
                        && ($entry['part']['model'] ?? null) !== null
                        && count($entry['machines']) > 0;
                })
                ->where('machines', function ($machines) use ($tplKukil, $autoCaulking, $wo) {
                    $tpl = collect($machines)->firstWhere('id', $tplKukil->id);
                    $caulk = collect($machines)->firstWhere('id', $autoCaulking->id);

                    $tplHasModalWo = collect($tpl['modal_rows'] ?? [])->contains('work_order_id', $wo->id);
                    $caulkHasModalWo = collect($caulk['modal_rows'] ?? [])->contains('work_order_id', $wo->id);

                    return $tpl !== null
                        && $caulk !== null
                        && count($tpl['planned_rows']) === 0
                        && count($caulk['planned_rows']) === 0
                        && $tpl['avail_wo_count'] >= 1
                        && $tplHasModalWo
                        && $caulkHasModalWo;
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
        $wo = $this->createWo(500, $monday);

        $tplKukil = Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();
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

        // Pada papan Senin: TPL KUKIL sisa avail 150 (500 - 350), AUTO CAULKING tetap 500.
        $this->get(route('production-plans.index', ['date' => $monday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($tplKukil, $autoCaulking, $wo) {
                    $tpl = collect($machines)->firstWhere('id', $tplKukil->id);
                    $caulk = collect($machines)->firstWhere('id', $autoCaulking->id);

                    $tplRow = collect($tpl['planned_rows'])->firstWhere('work_order_id', $wo->id);
                    $caulkModal = collect($caulk['modal_rows'])->firstWhere('work_order_id', $wo->id);

                    return $tplRow !== null
                        && (float) $tplRow['avail_qty'] === 150.0
                        && (float) $tplRow['days']['d']['qty'] === 200.0
                        && (int) $tplRow['days']['d']['sequence'] === 1
                        && (float) $tplRow['days']['d1']['qty'] === 150.0
                        && (int) $tplRow['days']['d1']['sequence'] === 2
                        && $tplRow['days']['d2'] === null
                        && count($caulk['planned_rows']) === 0
                        && $caulkModal !== null
                        && (float) $caulkModal['avail_qty'] === 500.0;
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
}
