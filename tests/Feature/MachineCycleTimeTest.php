<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\MachineCycleTime;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
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
                ->has('parts'));
    }

    public function test_store_creates_cycle_time(): void
    {
        $this->post(route('cycle-times.store'), [
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 5,
            'is_active' => true,
        ])->assertRedirect(route('cycle-times.index'));

        $this->assertDatabaseHas('machine_cycle_times', [
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 5,
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
        ]);

        $this->put(route('cycle-times.update', $row), [
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 8,
        ])->assertRedirect(route('cycle-times.index'));

        $this->assertSame(8.0, (float) $row->fresh()->cycle_time_seconds);

        $this->delete(route('cycle-times.destroy', $row))->assertRedirect(route('cycle-times.index'));
        $this->assertDatabaseMissing('machine_cycle_times', ['id' => $row->id]);
    }

    public function test_plan_board_shows_estimated_seconds_from_wo_qty(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 10,
            'planned_date' => now()->toDateString(),
        ])->assertRedirect();

        $wo = WorkOrder::latest('id')->firstOrFail();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        // Cycle time 3 detik/pcs untuk mesin + part hasil baris pertama.
        MachineCycleTime::create([
            'machine_id' => $this->machine()->id,
            'part_id' => $this->part()->id,
            'cycle_time_seconds' => 3,
        ]);

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', function ($list) {
                    $row = collect($list)->firstWhere('machine_id', $this->machine()->id);

                    return $row !== null
                        && (float) $row['estimated_seconds'] === 30.0; // 10 pcs × 3 detik
                }));
    }
}
