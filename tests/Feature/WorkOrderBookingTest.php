<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartStock;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterialBooking;
use App\Services\ReceiveMaterialService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WorkOrderBookingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    private function seedLeafStock(): void
    {
        foreach ([
            'CBKG07256C' => ['KGM', 20],
            '4000W4A003A' => ['PCS', 20],
            'PINCB01' => ['PCS', 80],
            '5040JA3071C' => ['PCS', 80],
        ] as $pn => $d) {
            PartStock::create([
                'part_id' => Part::where('part_number', $pn)->value('id'),
                'tag' => 'B-'.$pn, 'qty' => $d[1], 'qty_unit' => $d[0], 'received_at' => now(),
            ]);
        }
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

        $this->seedLeafStock();
        $this->post(route('work-orders.release', $wo))->assertRedirect();

        return $wo->fresh();
    }

    private function bookedCount(WorkOrder $wo): int
    {
        return WorkOrderMaterialBooking::where('work_order_id', $wo->id)
            ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED)
            ->count();
    }

    public function test_destroying_wo_releases_its_bookings(): void
    {
        $wo = $this->releasedWorkOrder();
        $this->assertGreaterThan(0, $this->bookedCount($wo));

        $this->delete(route('work-orders.destroy', $wo))->assertRedirect();

        $this->assertSame(0, $this->bookedCount($wo));
        $this->assertDatabaseHas('work_order_material_bookings', [
            'work_order_id' => $wo->id,
            'status' => WorkOrderMaterialBooking::STATUS_RELEASED,
        ]);
    }

    public function test_completing_wo_releases_leftover_bookings(): void
    {
        $wo = $this->releasedWorkOrder();
        $this->assertGreaterThan(0, $this->bookedCount($wo));

        // Tanpa lapor hasil sama sekali → semua booking masih aktif.
        $this->post(route('work-orders.complete', $wo))->assertRedirect();

        $wo->refresh();
        $this->assertSame('completed', $wo->status);
        $this->assertSame(0, $this->bookedCount($wo));
    }

    public function test_cancelling_wo_releases_its_bookings(): void
    {
        $wo = $this->releasedWorkOrder();

        $this->post(route('work-orders.cancel', $wo))->assertRedirect();

        $this->assertSame(0, $this->bookedCount($wo));
    }

    public function test_fifo_consumption_does_not_steal_booked_stock(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id, 'qty' => 1, 'planned_date' => now()->toDateString(),
        ])->assertRedirect();
        $wo = WorkOrder::latest('id')->firstOrFail();
        $item = $wo->items()->firstOrFail();

        $part = Part::where('part_number', 'CBKG07256C')->firstOrFail();

        $booked = PartStock::create([
            'part_id' => $part->id, 'tag' => 'B-BOOKED', 'qty' => 10,
            'qty_unit' => 'KGM', 'received_at' => now()->subDay(),
        ]);
        $free = PartStock::create([
            'part_id' => $part->id, 'tag' => 'B-FREE', 'qty' => 10,
            'qty_unit' => 'KGM', 'received_at' => now(),
        ]);

        // Baris tertua (BOOKED) dikunci WO lain.
        WorkOrderMaterialBooking::create([
            'work_order_id' => $wo->id,
            'work_order_item_id' => $item->id,
            'part_id' => $part->id,
            'part_stock_id' => $booked->id,
            'tag' => $booked->tag,
            'qty' => 10,
            'uom' => 'KGM',
            'status' => WorkOrderMaterialBooking::STATUS_BOOKED,
            'booked_at' => now(),
        ]);

        $allocs = app(ReceiveMaterialService::class)->consumeFifoByUom($part->id, 6, 'KGM');

        $this->assertSame([$free->id], array_column($allocs, 'part_stock_id'));
        $this->assertEqualsWithDelta(10.0, (float) $booked->fresh()->qty, 0.001);
        $this->assertEqualsWithDelta(4.0, (float) $free->fresh()->qty, 0.001);
    }

    public function test_apk_scan_cannot_exceed_the_allocated_share_of_a_part(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $main = Part::where('part_number', 'CBKG07256C')->firstOrFail();
        $sub = Part::where('part_number', 'CMMGI250S')->firstOrFail();

        PartStock::create(['part_id' => $main->id, 'tag' => '13', 'qty' => 4000, 'qty_unit' => 'KGM', 'received_at' => now()]);
        PartStock::create(['part_id' => $sub->id, 'tag' => '16', 'qty' => 1600, 'qty_unit' => 'KGM', 'received_at' => now()]);

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id, 'qty' => 100, 'planned_date' => now()->toDateString(),
        ])->assertRedirect();
        $wo = WorkOrder::latest('id')->firstOrFail();
        $item = $wo->items()->where('child_part_id', $main->id)->firstOrFail();

        // Alokasi: 60 main + 40 sub.
        $item->allocations()->create(['part_id' => $main->id, 'qty' => 60]);
        $item->allocations()->create(['part_id' => $sub->id, 'qty' => 40]);

        // Scan 100 dari part main (alokasinya hanya 60) -> harus ditolak.
        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'test-overalloc-'.uniqid(),
            'items' => [[
                'work_order_item_id' => $item->id,
                'scans' => [['tag' => '13', 'part_id' => $main->id, 'qty' => 100]],
            ]],
        ])->assertStatus(422);

        $bookedMain = (float) WorkOrderMaterialBooking::where('work_order_item_id', $item->id)
            ->where('part_id', $main->id)->where('status', 'booked')->sum('qty');
        $this->assertLessThanOrEqual(60.0 + 1e-6, $bookedMain, 'Scan melebihi alokasi harus ditolak');
    }
}
