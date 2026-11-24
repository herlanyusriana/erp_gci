<?php

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\Machine;
use App\Models\MachineCycleTime;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\MachineCycleTimeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MachineCycleTimeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());

        MachineCycleTime::where('machine_id', $this->machine()->id)->delete();
    }

    private function machine(): Machine
    {
        return Machine::where('machine_name', 'TPL KUKIL')->firstOrFail();
    }

    private function part(): Part
    {
        return Part::where('part_number', 'AAN30056405-WIP5')->firstOrFail();
    }

    public function test_index_page_loads_with_machines_and_parts(): void
    {
        $this->get(route('cycle-times.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/CycleTime/Index')
                ->has('cycleTimes')
                ->has('machines')
                ->has('parts')
                ->where('machineRows', []));
    }

    public function test_store_creates_cycle_time_with_qdc(): void
    {
        $this->post(route('cycle-times.store'), [
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 5,
            'qdc_minutes' => 15,
            'is_active' => true,
        ])->assertRedirect(route('cycle-times.index'));

        $this->assertDatabaseHas('machine_cycle_times', [
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 5,
            'qdc_minutes' => 15,
        ]);
    }

    public function test_same_machine_part_pair_is_rejected(): void
    {
        MachineCycleTime::create([
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 5,
        ]);

        $this->post(route('cycle-times.store'), [
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 7,
        ])->assertSessionHasErrors('machine_id');
    }

    public function test_same_machine_with_other_part_is_allowed(): void
    {
        MachineCycleTime::create([
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 5,
        ]);

        $other = Part::where('part_number', 'AAN30056405-WIP4')->firstOrFail();

        $this->post(route('cycle-times.store'), [
            'machine_id' => $this->machine()->id,
            'part_id' => $other->id,
            'cycle_time_seconds' => 3,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, MachineCycleTime::where('machine_id', $this->machine()->id)->count());
    }

    public function test_update_and_destroy(): void
    {
        $row = MachineCycleTime::create([
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 5,
            'qdc_minutes' => 10,
        ]);

        $this->put(route('cycle-times.update', $row), [
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 8,
            'qdc_minutes' => 12.5,
        ])->assertRedirect(route('cycle-times.index'));

        $fresh = $row->fresh();
        $this->assertSame(8.0, (float) $fresh->cycle_time_seconds);
        $this->assertSame(12.5, (float) $fresh->qdc_minutes);

        $this->delete(route('cycle-times.destroy', $row))->assertRedirect(route('cycle-times.index'));
        $this->assertDatabaseMissing('machine_cycle_times', ['id' => $row->id]);
    }

    public function test_machine_filter_returns_bom_matrix_rows(): void
    {
        MachineCycleTime::create([
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 4.2,
            'qdc_minutes' => 20,
            'is_active' => true,
        ]);

        $this->get(route('cycle-times.index', ['machine_id' => $this->machine()->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/CycleTime/Index')
                ->where('filters.machine_id', (string) $this->machine()->id)
                ->where('machineRows', function ($rows) {
                    $row = collect($rows)->firstWhere('part_id', $this->part()->id);

                    return $row !== null
                        && (float) $row['cycle_time_seconds'] === 4.2
                        && (float) $row['qdc_minutes'] === 20.0
                        && array_key_exists('model', $row);
                }));
    }

    public function test_bulk_save_upserts_and_deletes_rows_per_machine(): void
    {
        $partA = $this->part();
        $partB = Part::where('part_number', 'AAN30056405-WIP4')->firstOrFail();

        // Pre-existing row for partB will be removed when cycle_time_seconds is null.
        MachineCycleTime::create([
            'machine_id' => $this->machine()->id,
            'part_id' => $partB->id,
            'cycle_time_seconds' => 6,
            'qdc_minutes' => 15,
        ]);

        $this->post(route('cycle-times.bulk'), [
            'machine_id' => $this->machine()->id,
            'rows' => [
                [
                    'part_id' => $partA->id,
                    'cycle_time_seconds' => 3.5,
                    'qdc_minutes' => 18,
                    'is_active' => true,
                ],
                [
                    'part_id' => $partB->id,
                    'cycle_time_seconds' => null,
                    'qdc_minutes' => null,
                    'is_active' => true,
                ],
            ],
        ])->assertRedirect(route('cycle-times.index', ['machine_id' => $this->machine()->id]));

        $this->assertDatabaseHas('machine_cycle_times', [
            'machine_id' => $this->machine()->id,
            'part_id' => $partA->id,
            'cycle_time_seconds' => 3.5,
            'qdc_minutes' => 18,
        ]);
        $this->assertDatabaseMissing('machine_cycle_times', [
            'machine_id' => $this->machine()->id,
            'part_id' => $partB->id,
        ]);
    }

    public function test_bom_show_looks_up_tact_time_and_qdc_from_master(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $bom = Bom::where('part_id', $fg->id)->firstOrFail();

        MachineCycleTime::updateOrCreate(
            [
                'machine_id' => $this->machine()->id,
                'part_id' => $this->part()->id,
            ],
            [
                'cycle_time_seconds' => 3.7,
                'qdc_minutes' => 15,
                'is_active' => true,
            ],
        );

        $this->get(route('boms.show', $bom))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Bom/Show')
                ->where('bom.items', function ($items) {
                    $row = collect($items)->first(
                        fn ($item) => (int) ($item['machine_id'] ?? 0) === $this->machine()->id
                            && (int) ($item['parent_part_id'] ?? 0) === $this->part()->id,
                    );

                    return $row !== null
                        && (float) $row['tact_time_seconds'] === 3.7
                        && (float) $row['qdc_minutes'] === 15.0;
                }));
    }

    public function test_plan_board_shows_start_finish_time_and_qdc_from_cycle_time(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $monday = '2026-11-23';

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 600,
            'planned_date' => $monday,
        ])->assertRedirect();

        $wo = WorkOrder::latest('id')->firstOrFail();

        // Cycle time 6 detik/pcs (600 pcs = 60 menit) + QDC 15 menit.
        MachineCycleTime::create([
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 6,
            'qdc_minutes' => 15,
        ]);

        $this->put(route('production-plans.machines.save', $this->machine()), [
            'date' => $monday,
            'initial_qdc' => ['d' => true, 'd1' => false, 'd2' => false],
            'rows' => [
                [
                    'work_order_id' => $wo->id,
                    'd_seq' => 1,
                    'd_qty' => 600,
                    'd1_seq' => null,
                    'd1_qty' => null,
                    'd2_seq' => null,
                    'd2_qty' => null,
                ],
            ],
        ])->assertRedirect(route('production-plans.index', ['date' => $monday]));

        $this->get(route('production-plans.index', ['date' => $monday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($wo) {
                    $m = collect($machines)->firstWhere('id', $this->machine()->id);
                    $row = collect($m['planned_rows'] ?? [])->firstWhere('work_order_id', $wo->id);
                    $dayD = $row['days']['d'] ?? null;

                    return $row !== null
                        && $dayD !== null
                        && (float) $row['cycle_time_seconds'] === 6.0
                        && (float) $row['qdc_minutes'] === 15.0
                        && $dayD['start_time'] === '07:30'
                        && $dayD['end_time'] === '08:45' // 15m QDC + 60m prod
                        && (float) $dayD['qdc_applied_minutes'] === 15.0;
                }));
    }

    public function test_seeder_populates_tact_time_and_qdc_idempotently(): void
    {
        $this->seed(MachineCycleTimeSeeder::class);
        $countAfterFirst = MachineCycleTime::count();

        $this->seed(MachineCycleTimeSeeder::class);
        $this->assertSame($countAfterFirst, MachineCycleTime::count());
        $this->assertGreaterThanOrEqual(200, $countAfterFirst);

        $row = MachineCycleTime::query()
            ->where('machine_id', $this->machine()->id)
            ->where('part_id', $this->part()->id)
            ->firstOrFail();

        $this->assertSame(6.7, (float) $row->cycle_time_seconds);
        $this->assertSame(25.0, (float) $row->qdc_minutes);
    }

    public function test_plan_board_falls_back_to_same_machine_group_for_merged_rows(): void
    {
        $fg = Part::where('part_number', '4980JA3016F')->firstOrFail();
        $kukil400 = Machine::where('machine_name', 'KUKIL 400 TON')->firstOrFail();
        $kukil110 = Machine::where('machine_name', 'KUKIL 110 TON')->firstOrFail();
        $wip2 = Part::where('part_number', '4980JA3016F-WIP2')->firstOrFail();
        $monday = '2026-11-23';

        MachineCycleTime::query()
            ->whereIn('machine_id', [$kukil400->id, $kukil110->id])
            ->where('part_id', $wip2->id)
            ->delete();

        // Cycle time tersimpan pada mesin step terakhir (KUKIL 110 TON), sedangkan baris
        // gabungan di Production Plan memakai machine_id mesin pertama (KUKIL 400 TON).
        MachineCycleTime::create([
            'machine_id' => $kukil110->id,
            'part_id' => $wip2->id,
            'cycle_time_seconds' => 6,
            'qdc_minutes' => 60,
            'is_active' => true,
        ]);

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 300,
            'planned_date' => $monday,
        ])->assertRedirect();

        $wo = WorkOrder::latest('id')->firstOrFail();

        $this->put(route('production-plans.machines.save', $kukil400), [
            'date' => $monday,
            'initial_qdc' => ['d' => false, 'd1' => false, 'd2' => false],
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
        ])->assertRedirect();

        $this->get(route('production-plans.index', ['date' => $monday]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('machines', function ($machines) use ($kukil400, $wo, $wip2) {
                    $m = collect($machines)->firstWhere('id', $kukil400->id);
                    $row = collect($m['planned_rows'] ?? [])->firstWhere('work_order_id', $wo->id);
                    $dayD = $row['days']['d'] ?? null;

                    return $row !== null
                        && (int) ($row['wip_part']['id'] ?? 0) === $wip2->id
                        && (float) $row['cycle_time_seconds'] === 6.0
                        && (float) $row['qdc_minutes'] === 60.0
                        && $dayD !== null
                        && $dayD['start_time'] === '07:30'
                        && $dayD['end_time'] === '08:00'; // 300 × 6s = 30m (roll over, no initial QDC)
                }));
    }
}
