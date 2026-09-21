<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\Part;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanItem;
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

    private function createWorkOrder(): WorkOrder
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 10,
            'planned_date' => now()->toDateString(),
        ])->assertRedirect();

        return WorkOrder::latest('id')->firstOrFail();
    }

    public function test_planned_wo_appears_in_unplanned_panel(): void
    {
        $wo = $this->createWorkOrder();

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Production/Plan/Index')
                ->where('unplannedWorkOrders', fn ($list) => collect($list)->pluck('id')->contains($wo->id)));
    }

    public function test_attach_work_order_to_plan(): void
    {
        $wo = $this->createWorkOrder();
        $machine = Machine::where('is_active', true)->firstOrFail();

        $this->post(route('production-plans.attach'), [
            'work_order_id' => $wo->id,
            'machine_id' => $machine->id,
            'plan_date' => now()->toDateString(),
            'target_d' => 10,
        ])->assertRedirect();

        $this->assertDatabaseHas('production_plan_items', [
            'work_order_id' => $wo->id,
            'machine_id' => $machine->id,
            'fg_part_id' => $wo->part_id,
        ]);

        // Setelah ditempel, WO tidak lagi muncul di panel "belum masuk plan".
        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('unplannedWorkOrders', fn ($list) => ! collect($list)->pluck('id')->contains($wo->id)));
    }

    public function test_attach_rejects_work_order_already_in_a_plan(): void
    {
        $wo = $this->createWorkOrder();
        $machine = Machine::where('is_active', true)->firstOrFail();
        $plan = ProductionPlan::create(['plan_date' => now()->toDateString(), 'created_by' => auth()->id()]);
        ProductionPlanItem::create([
            'production_plan_id' => $plan->id,
            'machine_id' => $machine->id,
            'work_order_id' => $wo->id,
            'fg_part_id' => $wo->part_id,
            'sequence' => 1,
            'target_d' => 10,
        ]);

        $this->post(route('production-plans.attach'), [
            'work_order_id' => $wo->id,
            'machine_id' => $machine->id,
            'plan_date' => now()->toDateString(),
        ])->assertSessionHasErrors('work_order_id');

        $this->assertSame(1, ProductionPlanItem::where('work_order_id', $wo->id)->count());
    }
}
