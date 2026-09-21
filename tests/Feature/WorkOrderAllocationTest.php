<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartStock;
use App\Models\PartSubstitute;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkOrderAllocationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->first());
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

    private function kgmItem(WorkOrder $wo): WorkOrderItem
    {
        return $wo->items()
            ->whereHas('childPart', fn ($q) => $q->where('part_number', 'CBKG07256C'))
            ->firstOrFail();
    }

    /** @return array{0:int,1:int} dua substitute part_id */
    private function substitutes(int $partId): array
    {
        $ids = PartSubstitute::query()
            ->where('part_id', $partId)
            ->where('is_active', true)
            ->pluck('substitute_part_id')
            ->unique()
            ->take(2)
            ->values()
            ->all();

        $this->assertCount(2, $ids, 'Fixture harus punya minimal 2 substitute.');

        return [(int) $ids[0], (int) $ids[1]];
    }

    public function test_edit_page_exposes_material_options_with_stock(): void
    {
        $wo = $this->createWorkOrder();
        $item = $this->kgmItem($wo);
        [$subA] = $this->substitutes($item->child_part_id);

        PartStock::create([
            'part_id' => $subA, 'tag' => 'T-STOCK-A', 'qty' => 7.5,
            'qty_unit' => 'KGM', 'received_at' => now(),
        ]);

        $this->get(route('work-orders.items.edit', [$wo, $item]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Production/WorkOrder/ItemEdit')
                ->has('materialOptions')
                ->has('allocations')
                ->where('materialOptions', fn ($options) => collect($options)
                    ->firstWhere('id', $subA)['stock'] === 7.5));
    }

    public function test_update_item_stores_multiple_allocations(): void
    {
        $wo = $this->createWorkOrder();
        $item = $this->kgmItem($wo);
        [$subA, $subB] = $this->substitutes($item->child_part_id);

        $this->patch(route('work-orders.items.update', [$wo, $item]), [
            'allocations' => [
                ['part_id' => $subA, 'qty' => 6],
                ['part_id' => $subB, 'qty' => 4.9],
            ],
        ])->assertRedirect(route('work-orders.show', $wo));

        $this->assertDatabaseHas('work_order_item_allocations', [
            'work_order_item_id' => $item->id, 'part_id' => $subA, 'qty' => 6,
        ]);
        $this->assertDatabaseHas('work_order_item_allocations', [
            'work_order_item_id' => $item->id, 'part_id' => $subB, 'qty' => 4.9,
        ]);
        $this->assertSame(2, $item->allocations()->count());
    }

    public function test_update_item_rejects_part_outside_main_and_substitutes(): void
    {
        $wo = $this->createWorkOrder();
        $item = $this->kgmItem($wo);
        $outsider = Part::where('part_number', '4000W4A003A')->firstOrFail();

        $this->patch(route('work-orders.items.update', [$wo, $item]), [
            'allocations' => [['part_id' => $outsider->id, 'qty' => 5]],
        ])->assertSessionHasErrors('allocations.0.part_id');

        $this->assertSame(0, $item->allocations()->count());
    }

    public function test_update_item_rejects_allocation_exceeding_required(): void
    {
        $wo = $this->createWorkOrder();
        $item = $this->kgmItem($wo);
        [$subA, $subB] = $this->substitutes($item->child_part_id);

        $this->patch(route('work-orders.items.update', [$wo, $item]), [
            'allocations' => [
                ['part_id' => $subA, 'qty' => (float) $item->qty_required],
                ['part_id' => $subB, 'qty' => 1],
            ],
        ])->assertSessionHasErrors('allocations');
    }

    public function test_release_consumes_from_multiple_allocated_parts(): void
    {
        $wo = $this->createWorkOrder();
        $item = $this->kgmItem($wo);
        [$subA, $subB] = $this->substitutes($item->child_part_id);

        // Stok dua substitute KGM (total 11 >= kebutuhan 10.9).
        PartStock::create(['part_id' => $subA, 'tag' => 'T-A', 'qty' => 6, 'qty_unit' => 'KGM', 'received_at' => now()]);
        PartStock::create(['part_id' => $subB, 'tag' => 'T-B', 'qty' => 5, 'qty_unit' => 'KGM', 'received_at' => now()]);

        // Stok leaf lain agar bukan constraint pengikat.
        foreach ([
            '4000W4A003A' => ['PCS', 20],
            'PINCB01' => ['PCS', 80],
            '5040JA3071C' => ['PCS', 80],
        ] as $pn => $d) {
            PartStock::create([
                'part_id' => Part::where('part_number', $pn)->value('id'),
                'tag' => 'T-'.$pn, 'qty' => $d[1], 'qty_unit' => $d[0], 'received_at' => now(),
            ]);
        }

        $this->patch(route('work-orders.items.update', [$wo, $item]), [
            'allocations' => [
                ['part_id' => $subA, 'qty' => 6],
                ['part_id' => $subB, 'qty' => 4.9],
            ],
        ])->assertSessionHasNoErrors();

        $this->post(route('work-orders.release', $wo))->assertRedirect();
        $wo->refresh();
        $this->assertSame('in_progress', $wo->status);

        // Konsumsi tercatat dari kedua substitute, bukan dari main material.
        $this->assertDatabaseHas('work_order_consumptions', ['work_order_item_id' => $item->id, 'part_id' => $subA]);
        $this->assertDatabaseHas('work_order_consumptions', ['work_order_item_id' => $item->id, 'part_id' => $subB]);
        $this->assertDatabaseMissing('work_order_consumptions', ['work_order_item_id' => $item->id, 'part_id' => $item->child_part_id]);

        // Release hanya mengonsumsi RM; FG belum diposting (menunggu Production Result).
        $fgStock = PartStock::where('part_id', $wo->part_id)->where('qty', '>', 0)->sum('qty');
        $this->assertEqualsWithDelta(0.0, (float) $fgStock, 0.001);
    }

    public function test_release_reports_shortage_for_unallocated_remainder(): void
    {
        $wo = $this->createWorkOrder();
        $item = $this->kgmItem($wo);
        [$subA] = $this->substitutes($item->child_part_id);

        PartStock::create(['part_id' => $subA, 'tag' => 'T-A', 'qty' => 100, 'qty_unit' => 'KGM', 'received_at' => now()]);
        foreach ([
            '4000W4A003A' => ['PCS', 20],
            'PINCB01' => ['PCS', 80],
            '5040JA3071C' => ['PCS', 80],
        ] as $pn => $d) {
            PartStock::create([
                'part_id' => Part::where('part_number', $pn)->value('id'),
                'tag' => 'T-'.$pn, 'qty' => $d[1], 'qty_unit' => $d[0], 'received_at' => now(),
            ]);
        }

        // Hanya 6 dari 10.9 KGM yang dialokasikan.
        $this->patch(route('work-orders.items.update', [$wo, $item]), [
            'allocations' => [['part_id' => $subA, 'qty' => 6]],
        ])->assertSessionHasNoErrors();

        $this->post(route('work-orders.release', $wo))
            ->assertRedirect()
            ->assertSessionHas('error');

        $wo->refresh();
        $this->assertSame('in_progress', $wo->status);

        // Release hanya mengonsumsi RM; FG belum diposting (menunggu Production Result).
        $fgStock = PartStock::where('part_id', $wo->part_id)->where('qty', '>', 0)->sum('qty');
        $this->assertEqualsWithDelta(0.0, (float) $fgStock, 0.01);
    }
}
