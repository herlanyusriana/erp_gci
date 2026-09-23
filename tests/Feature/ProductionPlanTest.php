<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\Part;
use App\Models\ProductionPlanItem;
use App\Models\ProductionResult;
use App\Models\User;
use App\Models\WorkOrder;
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

    private function createWorkOrder(string $fgPartNumber = 'AAN30056405'): WorkOrder
    {
        $fg = Part::where('part_number', $fgPartNumber)->firstOrFail();

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 10,
            'planned_date' => now()->toDateString(),
        ])->assertRedirect();

        return WorkOrder::latest('id')->firstOrFail();
    }

    /** @return int jumlah baris papan = grup step berurutan dengan 3 huruf pertama nama mesin sama */
    private function expectedGroupCount(WorkOrder $wo): int
    {
        $steps = $wo->items()
            ->with('machine')
            ->get()
            ->filter(fn ($it) => $it->parent_part_id !== null && strtoupper((string) $it->source) !== 'SUBCON')
            ->mapWithKeys(fn ($it) => [($it->sequence ?? 0).'|'.$it->parent_part_id => $it])
            ->values();

        $keyOf = function ($it): string {
            $name = strtoupper(trim((string) ($it->machine?->machine_name ?? '')));

            return $name !== '' ? substr($name, 0, 3) : (string) $it->machine_id;
        };

        $groups = 0;
        $previousKey = null;
        foreach ($steps as $index => $step) {
            $key = $keyOf($step);
            if ($index === 0 || $key !== $previousKey) {
                $groups++;
            }
            $previousKey = $key;
        }

        return $groups;
    }

    public function test_creating_wo_does_not_enter_plan_board(): void
    {
        $wo = $this->createWorkOrder();

        // Belum masuk papan selama masih `planned`.
        $this->assertSame(0, ProductionPlanItem::where('work_order_id', $wo->id)->count());

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => ! collect($list)->pluck('work_order_id')->contains($wo->id))
                ->where('unplannedWorkOrders', fn ($list) => collect($list)->pluck('id')->contains($wo->id)));
    }

    public function test_release_enters_wo_into_plan_board(): void
    {
        $wo = $this->createWorkOrder();

        $this->post(route('work-orders.release', $wo))->assertRedirect();
        $wo->refresh();
        $this->assertSame('in_progress', $wo->status);

        $rows = ProductionPlanItem::where('work_order_id', $wo->id)->get();
        $this->assertGreaterThan(1, $rows->count());
        $this->assertSame($this->expectedGroupCount($wo), $rows->count());
        $this->assertSame($rows->count(), $rows->pluck('machine_id')->unique()->count());

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => collect($list)->pluck('work_order_id')->contains($wo->id))
                ->where('unplannedWorkOrders', fn ($list) => ! collect($list)->pluck('id')->contains($wo->id)));
    }

    public function test_update_targets_saves_d_d1_d2(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $row = ProductionPlanItem::where('work_order_id', $wo->id)->firstOrFail();

        $this->patch(route('production-plans.items.update', $row), [
            'machine_id' => $row->machine_id,
            'wip_part_id' => $row->wip_part_id,
            'target_d' => 100,
            'target_d1' => 50,
            'target_d2' => 25,
        ])->assertRedirect();

        $row->refresh();
        $this->assertSame(100.0, (float) $row->target_d);
        $this->assertSame(50.0, (float) $row->target_d1);
        $this->assertSame(25.0, (float) $row->target_d2);
    }

    public function test_bulk_update_targets_saves_many_rows(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $rows = ProductionPlanItem::where('work_order_id', $wo->id)->orderBy('id')->take(2)->get();
        $this->assertCount(2, $rows);

        $this->patch(route('production-plans.targets'), [
            'items' => [
                ['id' => $rows[0]->id, 'target_d' => 10, 'target_d1' => 5, 'target_d2' => null],
                ['id' => $rows[1]->id, 'target_d' => 7],
            ],
        ])->assertRedirect();

        $first = $rows[0]->fresh();
        $this->assertSame(10.0, (float) $first->target_d);
        $this->assertSame(5.0, (float) $first->target_d1);
        $this->assertNull($first->target_d2);
        $this->assertSame(7.0, (float) $rows[1]->fresh()->target_d);
    }

    public function test_target_change_is_recorded_in_plan_history(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();
        $row = ProductionPlanItem::where('work_order_id', $wo->id)->firstOrFail();

        $this->patch(route('production-plans.targets'), [
            'items' => [['id' => $row->id, 'target_d' => 8, 'target_d1' => 4, 'target_d2' => null]],
        ])->assertRedirect();

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('histories', fn ($histories) => collect($histories)->contains(
                    fn ($history) => $history['event'] === 'targets_updated'
                        && $history['production_plan_item_id'] === $row->id
                        && $history['before']['target_d'] === null
                        && (float) $history['after']['target_d'] === 8.0
                        && $history['user']['id'] === auth()->id(),
                )));
    }

    public function test_plan_rows_follow_routing_order(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $rows = ProductionPlanItem::where('work_order_id', $wo->id)
            ->with('machine')
            ->orderByRaw('step_sequence ASC NULLS LAST')
            ->orderBy('machine_id')
            ->orderBy('sequence')
            ->get();

        // Urutan grup mesin = alur routing BOM 58.
        $this->assertSame(
            ['TPL KUKIL', 'ASSY. TPL COMP BASE 1', 'AUTO CAULKING', 'ASSY. TPL COMP BASE 2'],
            $rows->pluck('machine.machine_name')->all(),
        );

        // step_sequence menaik (dasar urutan di papan).
        $seqs = $rows->pluck('step_sequence')->map(fn ($v) => (int) $v)->all();
        $sorted = $seqs;
        sort($sorted);
        $this->assertSame($sorted, $seqs);
    }

    public function test_plan_items_include_the_fg_part_model_for_the_board(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($items) => collect($items)->contains(
                    fn ($item) => $item['work_order_id'] === $wo->id
                        && array_key_exists('model', $item['fg_part']),
                )));
    }

    public function test_plan_remaining_qty_uses_results_up_to_board_date_not_targets(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $row = ProductionPlanItem::where('work_order_id', $wo->id)->firstOrFail();
        $boardDate = now()->subDay()->toDateString();
        $row->plan->update(['plan_date' => $boardDate]);

        ProductionResult::create([
            'work_order_id' => $wo->id,
            'parent_part_id' => $row->wip_part_id,
            'result_date' => $boardDate,
            'qty_good' => 3,
        ]);
        ProductionResult::create([
            'work_order_id' => $wo->id,
            'parent_part_id' => $row->wip_part_id,
            'result_date' => now()->toDateString(),
            'qty_good' => 2,
        ]);

        // Target adalah rencana, bukan realisasi; tidak boleh mengurangi sisa WO.
        $row->update(['target_d' => 4]);

        $this->get(route('production-plans.index', ['date' => $boardDate]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($items) => (float) collect($items)
                    ->firstWhere('id', $row->id)['avail_qty'] === 7.0));

    }

    public function test_fully_produced_row_is_hidden_from_board(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $row = ProductionPlanItem::where('work_order_id', $wo->id)->firstOrFail();

        ProductionResult::create([
            'work_order_id' => $wo->id,
            'parent_part_id' => $row->wip_part_id,
            'result_date' => now()->toDateString(),
            'qty_good' => $wo->qty,
        ]);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => ! collect($list)->pluck('id')->contains($row->id)));

        $this->assertSame('in_progress', $wo->fresh()->status);
    }

    public function test_partially_produced_wo_shows_only_unfinished_rows(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $rows = ProductionPlanItem::where('work_order_id', $wo->id)->orderBy('id')->get();
        $this->assertGreaterThan(1, $rows->count());

        $closed = $rows->first();
        $stillOpen = $rows->firstWhere('wip_part_id', '!=', $closed->wip_part_id);
        $this->assertNotNull($stillOpen, 'Butuh dua baris dengan part hasil berbeda.');

        ProductionResult::create([
            'work_order_id' => $wo->id,
            'parent_part_id' => $closed->wip_part_id,
            'result_date' => now()->toDateString(),
            'qty_good' => $wo->qty,
        ]);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', function ($list) use ($closed, $stillOpen) {
                    $ids = collect($list)->pluck('id');

                    return ! $ids->contains($closed->id) && $ids->contains($stillOpen->id);
                }));
    }

    public function test_deleting_production_result_restores_board_row(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $row = ProductionPlanItem::where('work_order_id', $wo->id)->firstOrFail();

        $result = ProductionResult::create([
            'work_order_id' => $wo->id,
            'parent_part_id' => $row->wip_part_id,
            'result_date' => now()->toDateString(),
            'qty_good' => $wo->qty,
        ]);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => ! collect($list)->pluck('id')->contains($row->id)));

        $result->delete();

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => collect($list)->pluck('id')->contains($row->id)));
    }

    public function test_fully_produced_wo_is_not_listed_off_board(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $otherDate = now()->addDay()->toDateString();
        foreach (ProductionPlanItem::where('work_order_id', $wo->id)->get() as $row) {
            $row->plan->update(['plan_date' => $otherDate]);
            ProductionResult::create([
                'work_order_id' => $wo->id,
                'parent_part_id' => $row->wip_part_id,
                'result_date' => now()->toDateString(),
                'qty_good' => $wo->qty,
            ]);
        }

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('offBoardWorkOrders', fn ($list) => ! collect($list)->pluck('id')->contains($wo->id)));
    }

    public function test_carry_over_remaining_quantity_ignores_results_after_board_date(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $boardDate = now()->subDay()->toDateString();
        $planDate = now()->subDays(2)->toDateString();
        $rows = ProductionPlanItem::where('work_order_id', $wo->id)->get();
        $rows->firstOrFail()->plan->update(['plan_date' => $planDate]);

        foreach ($rows as $row) {
            ProductionResult::create([
                'work_order_id' => $wo->id,
                'parent_part_id' => $row->wip_part_id,
                'result_date' => now()->toDateString(),
                'qty_good' => $wo->qty,
            ]);
        }

        $this->get(route('production-plans.index', ['date' => $boardDate]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => collect($list)->pluck('work_order_id')->contains($wo->id))
                ->where('offBoardWorkOrders', fn ($list) => ! collect($list)->pluck('id')->contains($wo->id)));
    }

    public function test_planned_work_order_is_not_listed_on_or_off_board(): void
    {
        $wo = $this->createWorkOrder();

        $this->post(route('production-plans.attach'), [
            'work_order_id' => $wo->id,
            'plan_date' => now()->toDateString(),
        ])->assertRedirect();

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => ! collect($list)->pluck('work_order_id')->contains($wo->id)));

        ProductionPlanItem::where('work_order_id', $wo->id)
            ->firstOrFail()
            ->plan
            ->update(['plan_date' => now()->subDay()->toDateString()]);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('offBoardWorkOrders', fn ($list) => ! collect($list)->pluck('id')->contains($wo->id)));
    }

    public function test_completed_work_order_with_remaining_quantity_stays_on_board(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();
        $wo->update(['status' => 'completed']);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => collect($list)->pluck('work_order_id')->contains($wo->id)));
    }

    public function test_cancelled_work_order_is_not_listed_on_board(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();
        $wo->update(['status' => 'cancelled']);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => ! collect($list)->pluck('work_order_id')->contains($wo->id)));
    }

    public function test_same_machine_steps_merge_into_one_row_with_input_and_output(): void
    {
        $wo = $this->createWorkOrder('AGU30018303');
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $rows = ProductionPlanItem::where('work_order_id', $wo->id)->with(['inputPart', 'wipPart', 'machine'])->get();

        // 3 step Press (WIP1..WIP3) di TPL DONGSHIN + 1 Assembling Full → 2 baris.
        $this->assertSame(2, $rows->count());

        $press = $rows->first(fn ($row) => $row->machine?->machine_name === 'TPL DONGSHIN');
        $this->assertNotNull($press);
        $this->assertSame('BPSH0257021487', $press->inputPart?->part_number);
        $this->assertSame('AGU30018303-WIP3', $press->wipPart?->part_number);
    }

    public function test_attach_work_order_populates_all_steps(): void
    {
        $wo = $this->createWorkOrder();

        $this->post(route('production-plans.attach'), [
            'work_order_id' => $wo->id,
            'plan_date' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertSame(
            $this->expectedGroupCount($wo),
            ProductionPlanItem::where('work_order_id', $wo->id)->count(),
        );
    }

    public function test_rebuild_command_restores_merged_rows(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $plan = ProductionPlanItem::where('work_order_id', $wo->id)->firstOrFail()->plan;
        $expected = $this->expectedGroupCount($wo);

        // Simulasi baris lama (per step, target terisi).
        ProductionPlanItem::create([
            'production_plan_id' => $plan->id,
            'machine_id' => null,
            'work_order_id' => $wo->id,
            'fg_part_id' => $wo->part_id,
            'sequence' => 99,
            'target_d' => 5,
        ]);
        $this->assertSame($expected + 1, ProductionPlanItem::where('work_order_id', $wo->id)->count());

        $this->artisan('production-plan:rebuild', ['--wo' => $wo->id])->assertExitCode(0);

        $rows = ProductionPlanItem::where('work_order_id', $wo->id)->get();
        $this->assertSame($expected, $rows->count());
        $this->assertTrue($rows->every(fn ($r) => $r->target_d === null));
    }

    public function test_rebuild_keeps_existing_rows_when_no_routable_step(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $rowsBefore = ProductionPlanItem::where('work_order_id', $wo->id)->count();
        $this->assertGreaterThan(0, $rowsBefore);

        // Hilangkan semua parent part → tidak ada step routing yang bisa dibuat.
        $wo->items()->update(['parent_part_id' => null]);

        $this->artisan('production-plan:rebuild', ['--wo' => $wo->id])->assertExitCode(0);

        // Baris lama tidak boleh hilang hanya karena rebuild tidak menemukan step.
        $this->assertSame($rowsBefore, ProductionPlanItem::where('work_order_id', $wo->id)->count());
    }

    public function test_unfinished_work_order_from_previous_plan_date_carries_over_to_selected_board(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $row = ProductionPlanItem::where('work_order_id', $wo->id)->firstOrFail();
        $otherDate = now()->subDay()->toDateString();
        $row->plan->update(['plan_date' => $otherDate]);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => collect($list)->pluck('work_order_id')->contains($wo->id))
                ->where('offBoardWorkOrders', fn ($list) => ! collect($list)->pluck('id')->contains($wo->id)));
    }

    public function test_future_plan_allocation_does_not_appear_before_its_date(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $futureDate = now()->addDay()->toDateString();
        ProductionPlanItem::where('work_order_id', $wo->id)
            ->firstOrFail()
            ->plan
            ->update(['plan_date' => $futureDate]);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => ! collect($list)->pluck('work_order_id')->contains($wo->id))
                ->where('offBoardWorkOrders', fn ($list) => collect($list)->contains(
                    fn ($item) => $item['id'] === $wo->id
                        && in_array($futureDate, $item['plan_dates'], true),
                )));
    }

    public function test_work_order_planned_date_does_not_control_board_visibility(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();
        $wo->update(['planned_date' => now()->addMonth()->toDateString()]);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => collect($list)->pluck('work_order_id')->contains($wo->id)));
    }

    public function test_attach_reports_wo_without_routable_step_instead_of_false_success(): void
    {
        $wo = $this->createWorkOrder();

        // Tidak ada parent part → tidak ada step routing yang bisa jadi baris papan.
        $wo->items()->update(['parent_part_id' => null]);

        $this->post(route('production-plans.attach'), [
            'work_order_id' => $wo->id,
            'plan_date' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertSame(0, ProductionPlanItem::where('work_order_id', $wo->id)->count());
        $this->assertNotNull(session('error'));
        $this->assertNull(session('success'));
    }

    public function test_plan_machines_follow_master_sequence(): void
    {
        // Netralkan urutan hasil seeding dulu supaya tidak bentrok nilai.
        Machine::query()->update(['sequence' => null]);
        Machine::where('machine_name', 'AUTO CAULKING')->update(['sequence' => 1]);
        Machine::where('machine_name', 'TPL KUKIL')->update(['sequence' => 2]);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($list) {
                    $names = collect($list)->pluck('machine_name')->values()->all();

                    return ($names[0] ?? null) === 'AUTO CAULKING'
                        && ($names[1] ?? null) === 'TPL KUKIL';
                }));
    }

    public function test_subcon_machine_rows_are_hidden_from_plan_board(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $plan = ProductionPlanItem::where('work_order_id', $wo->id)->firstOrFail()->plan;
        $subcon = Machine::query()->whereRaw("UPPER(machine_code) = 'SUBCON'")->firstOrFail();

        ProductionPlanItem::create([
            'production_plan_id' => $plan->id,
            'machine_id' => $subcon->id,
            'work_order_id' => $wo->id,
            'fg_part_id' => $wo->part_id,
            'sequence' => 99,
        ]);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => collect($list)->pluck('machine_id')->every(fn ($id) => $id !== $subcon->id))
                ->where('machines', fn ($list) => collect($list)->pluck('id')->every(fn ($id) => $id !== $subcon->id)));
    }

    public function test_subcon_rows_do_not_keep_completed_work_order_in_off_board_panel(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $rows = ProductionPlanItem::where('work_order_id', $wo->id)->get();
        $plan = $rows->firstOrFail()->plan;
        $plan->update(['plan_date' => now()->addDay()->toDateString()]);

        foreach ($rows as $row) {
            ProductionResult::create([
                'work_order_id' => $wo->id,
                'parent_part_id' => $row->wip_part_id,
                'result_date' => now()->toDateString(),
                'qty_good' => $wo->qty,
            ]);
        }

        $subcon = Machine::query()->whereRaw("UPPER(machine_code) = 'SUBCON'")->firstOrFail();
        ProductionPlanItem::create([
            'production_plan_id' => $plan->id,
            'machine_id' => $subcon->id,
            'work_order_id' => $wo->id,
            'fg_part_id' => $wo->part_id,
            'sequence' => 99,
        ]);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('offBoardWorkOrders', fn ($list) => ! collect($list)->pluck('id')->contains($wo->id)));
    }

    public function test_attach_rejects_work_order_already_in_a_plan(): void
    {
        $wo = $this->createWorkOrder();

        $this->post(route('production-plans.attach'), [
            'work_order_id' => $wo->id,
            'plan_date' => now()->toDateString(),
        ])->assertRedirect();

        $this->post(route('production-plans.attach'), [
            'work_order_id' => $wo->id,
            'plan_date' => now()->toDateString(),
        ])->assertSessionHasErrors('work_order_id');
    }
}
