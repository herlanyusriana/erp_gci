<?php

namespace Tests\Feature;

use App\Models\Part;
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

    /** @return int jumlah step (sequence + parent unik, non-Subcon) */
    private function expectedStepCount(WorkOrder $wo): int
    {
        return $wo->items()
            ->get()
            ->filter(fn ($it) => $it->parent_part_id !== null && strtoupper((string) $it->source) !== 'SUBCON')
            ->map(fn ($it) => ($it->sequence ?? 0).'|'.$it->parent_part_id)
            ->unique()
            ->count();
    }

    public function test_creating_wo_auto_populates_all_steps_on_plan_board(): void
    {
        $wo = $this->createWorkOrder();

        $rows = ProductionPlanItem::where('work_order_id', $wo->id)->get();

        $this->assertGreaterThan(1, $rows->count());
        $this->assertSame($this->expectedStepCount($wo), $rows->count());

        // Setiap step mendarat di mesin step itu.
        foreach ($wo->items()->get() as $item) {
            if ($item->parent_part_id === null) {
                continue;
            }
            $this->assertDatabaseHas('production_plan_items', [
                'work_order_id' => $wo->id,
                'machine_id' => $item->machine_id,
                'wip_part_id' => $item->parent_part_id,
            ]);
        }

        // Tampil di papan, dan tidak lagi di panel "belum masuk plan".
        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($list) => collect($list)->pluck('work_order_id')->contains($wo->id))
                ->where('unplannedWorkOrders', fn ($list) => ! collect($list)->pluck('id')->contains($wo->id)));
    }

    public function test_wo_without_plan_items_appears_in_unplanned_panel(): void
    {
        $wo = $this->createWorkOrder();
        ProductionPlanItem::where('work_order_id', $wo->id)->delete();

        $this->get(route('production-plans.index', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('unplannedWorkOrders', fn ($list) => collect($list)->pluck('id')->contains($wo->id)));
    }

    public function test_attach_work_order_populates_all_steps(): void
    {
        $wo = $this->createWorkOrder();
        ProductionPlanItem::where('work_order_id', $wo->id)->delete();

        $this->post(route('production-plans.attach'), [
            'work_order_id' => $wo->id,
            'plan_date' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertSame(
            $this->expectedStepCount($wo),
            ProductionPlanItem::where('work_order_id', $wo->id)->count(),
        );
    }

    public function test_attach_rejects_work_order_already_in_a_plan(): void
    {
        $wo = $this->createWorkOrder();

        $this->post(route('production-plans.attach'), [
            'work_order_id' => $wo->id,
            'plan_date' => now()->toDateString(),
        ])->assertSessionHasErrors('work_order_id');
    }
}
