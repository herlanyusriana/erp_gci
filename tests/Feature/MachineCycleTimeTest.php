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

    public function test_plan_board_shows_estimated_seconds_and_qdc_from_wo_qty(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 10,
            'planned_date' => now()->toDateString(),
        ])->assertRedirect();

        $wo = WorkOrder::latest('id')->firstOrFail();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        // Cycle time 3 detik/pcs + QDC 15 menit untuk mesin + part hasil baris pertama.
        MachineCycleTime::create([
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 3,
            'qdc_minutes' => 15,
        ]);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', function ($list) {
                    $row = collect($list)->firstWhere('machine_id', $this->machine()->id);

                    return $row !== null
                        && (float) $row['cycle_time_seconds'] === 3.0
                        && (float) $row['qdc_minutes'] === 15.0
                        && (float) $row['estimated_seconds'] === 30.0; // 10 pcs × 3 detik
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
}
