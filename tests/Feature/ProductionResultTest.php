<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartStock;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterialBooking;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfInterceptor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductionResultTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfInterceptor::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    private function releasedWorkOrder(): WorkOrder
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 10,
            'planned_date' => now()->toDateString(),
        ])->assertRedirect();

        $wo = WorkOrder::latest('id')->firstOrFail();

        foreach ([
            'CBKG07256C' => ['KGM', 20],
            '4000W4A003A' => ['PCS', 20],
            'PINCB01' => ['PCS', 80],
            '5040JA3071C' => ['PCS', 80],
        ] as $pn => $d) {
            PartStock::create([
                'part_id' => Part::where('part_number', $pn)->value('id'),
                'tag' => 'R-'.$pn, 'qty' => $d[1], 'qty_unit' => $d[0], 'received_at' => now(),
            ]);
        }

        $this->post(route('work-orders.release', $wo))->assertRedirect();

        return $wo->fresh();
    }

    private function stepIds(WorkOrder $wo): Collection
    {
        return $wo->items()
            ->get()
            ->groupBy('parent_part_id')
            ->map(fn ($rows) => $rows->sortBy('sequence')->first())
            ->sortBy(fn ($r) => [$r->sequence ?? 0, $r->id])
            ->map(fn ($r) => ['parent_part_id' => (int) $r->parent_part_id, 'step' => $r])
            ->values();
    }

    public function test_results_index_lists_running_work_orders(): void
    {
        $wo = $this->releasedWorkOrder();

        $this->get(route('production-results.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Production/Result/Index')
                ->where('workOrders.data', fn ($list) => collect($list)->pluck('id')->contains($wo->id)));
    }

    public function test_report_step_posts_wip_output(): void
    {
        $wo = $this->releasedWorkOrder();
        $steps = $this->stepIds($wo);

        // Step pertama: WIP1 ← RM (tidak konsumsi WIP).
        $first = $steps->first()['parent_part_id'];

        $this->post(route('production-results.store', $wo), [
            'parent_part_id' => $first,
            'qty_good' => 10,
        ])->assertRedirect();

        $this->assertDatabaseHas('production_results', [
            'work_order_id' => $wo->id,
            'parent_part_id' => $first,
            'qty_good' => 10,
        ]);

        // Output WIP1 masuk stok.
        $wipStock = PartStock::where('part_id', $first)->where('qty', '>', 0)->sum('qty');
        $this->assertEqualsWithDelta(10.0, (float) $wipStock, 0.001);
    }

    public function test_reporting_step_without_previous_wip_is_rejected(): void
    {
        $wo = $this->releasedWorkOrder();
        $steps = $this->stepIds($wo);

        // Step kedua butuh WIP1 yang belum diproduksi.
        $second = $steps[1]['parent_part_id'];

        $this->post(route('production-results.store', $wo), [
            'parent_part_id' => $second,
            'qty_good' => 5,
        ])->assertSessionHasErrors('qty_good');
    }

    public function test_full_sequence_produces_fg(): void
    {
        $wo = $this->releasedWorkOrder();
        $steps = $this->stepIds($wo);

        foreach ($steps as $row) {
            $this->post(route('production-results.store', $wo), [
                'parent_part_id' => $row['parent_part_id'],
                'qty_good' => 10,
            ])->assertSessionHasNoErrors();
        }

        $fgStock = PartStock::where('part_id', $wo->part_id)->where('qty', '>', 0)->sum('qty');
        $this->assertEqualsWithDelta(10.0, (float) $fgStock, 0.001);

        // Complete tanpa kekurangan.
        $this->post(route('work-orders.complete', $wo))->assertSessionHas('success');
    }

    public function test_release_books_stock_and_result_consumes_it(): void
    {
        $wo = $this->releasedWorkOrder();
        $rmId = (int) Part::where('part_number', 'CBKG07256C')->value('id');

        // Setelah release: stok fisik utuh, hanya di-book, belum ada konsumsi.
        $this->assertEqualsWithDelta(20.0, (float) PartStock::where('part_id', $rmId)->sum('qty'), 0.001);
        $this->assertGreaterThan(0.0, (float) WorkOrderMaterialBooking::where('work_order_id', $wo->id)->where('status', 'booked')->sum('qty'));
        $this->assertSame(0, $wo->consumptions()->count());

        // Laporkan step pertama → booking RM dikonsumsi, stok fisik baru turun.
        $first = $this->stepIds($wo)->first()['parent_part_id'];

        $this->post(route('production-results.store', $wo), [
            'parent_part_id' => $first,
            'qty_good' => 10,
        ])->assertSessionHasNoErrors();

        $this->assertLessThan(20.0, (float) PartStock::where('part_id', $rmId)->sum('qty'));
        $this->assertGreaterThan(0, $wo->consumptions()->count());
        $this->assertSame(
            0.0,
            (float) WorkOrderMaterialBooking::where('work_order_id', $wo->id)
                ->where('part_id', $rmId)->where('status', 'booked')->sum('qty'),
        );
        $this->assertDatabaseHas('work_order_material_bookings', [
            'work_order_id' => $wo->id,
            'part_id' => $rmId,
            'status' => 'consumed',
        ]);
    }

    public function test_reject_qty_consumes_input_material_while_only_good_qty_is_output(): void
    {
        $wo = $this->releasedWorkOrder();
        $rmId = (int) Part::where('part_number', 'CBKG07256C')->value('id');
        $firstStep = $this->stepIds($wo)->first();
        $first = $firstStep['parent_part_id'];
        $unitNeed = (float) $firstStep['step']->child_qty;
        $stockBefore = (float) PartStock::where('part_id', $rmId)->sum('qty');

        $this->post(route('production-results.store', $wo), [
            'parent_part_id' => $first,
            'qty_good' => 9,
            'qty_reject' => 1,
        ])->assertSessionHasNoErrors();

        // 9 good + 1 NG sama-sama telah memakai 10 unit material yang dibooking.
        $this->assertEqualsWithDelta($stockBefore - (10 * $unitNeed), (float) PartStock::where('part_id', $rmId)->sum('qty'), 0.001);
        $this->assertSame(
            0.0,
            (float) WorkOrderMaterialBooking::where('work_order_id', $wo->id)
                ->where('part_id', $rmId)->where('status', 'booked')->sum('qty'),
        );

        // Hanya hasil good yang diposting sebagai output; WO masih menyisakan 1 good unit.
        $this->assertEqualsWithDelta(9.0, (float) PartStock::where('part_id', $first)->sum('qty'), 0.001);
    }

    public function test_report_rejects_over_production(): void
    {
        $wo = $this->releasedWorkOrder();
        $first = $this->stepIds($wo)->first()['parent_part_id'];

        $this->post(route('production-results.store', $wo), [
            'parent_part_id' => $first,
            'qty_good' => 10,
        ])->assertSessionHasNoErrors();

        $this->post(route('production-results.store', $wo), [
            'parent_part_id' => $first,
            'qty_good' => 1,
        ])->assertSessionHasErrors('qty_good');
    }
}
