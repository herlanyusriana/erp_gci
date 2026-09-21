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

    public function test_plan_row_starts_with_wo_qty_as_remaining(): void
    {
        $wo = $this->createWorkOrder();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $rows = ProductionPlanItem::where('work_order_id', $wo->id)->get();
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            // target_d sengaja kosong → qty WO dibagi manual ke D/D1/D2.
            $this->assertNull($row->target_d);
            $this->assertEqualsWithDelta((float) $wo->qty, (float) $row->avail_qty, 0.001);
        }

        $first = $rows->first();
        $first->update(['target_d' => 4]);

        $this->assertEqualsWithDelta((float) $wo->qty - 4, (float) $first->fresh()->avail_qty, 0.001);
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
