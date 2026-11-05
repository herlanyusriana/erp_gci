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

    public function test_resolve_tag_rejects_ambiguous_tag_shared_across_parts(): void
    {
        $main = Part::where('part_number', 'CBKG07256C')->firstOrFail();
        $sub = Part::where('part_number', 'CMMGI250S')->firstOrFail();

        // Dua part berbeda memakai nomor tag yang sama: "TAG-SHARED-16".
        PartStock::create(['part_id' => $main->id, 'tag' => 'TAG-SHARED-16', 'qty' => 800, 'qty_unit' => 'KGM', 'received_at' => now()]);
        PartStock::create(['part_id' => $sub->id, 'tag' => 'TAG-SHARED-16', 'qty' => 800, 'qty_unit' => 'KGM', 'received_at' => now()]);

        // Tanpa part_id: server menolak 409 (ambigu) dan mengembalikan daftar kandidat,
        // BUKAN menebak salah satu dan membooking part yang salah.
        $res = $this->postJson('/api/stock-tags/resolve', ['tag' => 'TAG-SHARED-16'])
            ->assertStatus(409);
        $this->assertCount(2, $res->json('candidates'));

        // Dengan part_id eksplisit: langsung ter-resolve ke part yang diminta.
        $this->postJson('/api/stock-tags/resolve', ['tag' => 'TAG-SHARED-16', 'part_id' => $main->id])
            ->assertOk()
            ->assertJsonPath('data.part_id', $main->id);

        $this->postJson('/api/stock-tags/resolve', ['tag' => 'TAG-SHARED-16', 'part_id' => $sub->id])
            ->assertOk()
            ->assertJsonPath('data.part_id', $sub->id);
    }

    public function test_full_pack_may_exceed_remaining_share_when_closing_demand(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $main = Part::where('part_number', 'CBKG07256C')->firstOrFail();

        // Kebutuhan WO 100 kg. Alokasi 60 + 40 ke dua part.
        // Satu kemasan fisik utuh 100 kg dari part main di-scan sekaligus.
        // Aturan lama (:636): kemasan utuh boleh keluar penuh, TIDAK ditolak.
        PartStock::create(['part_id' => $main->id, 'tag' => 'PACK-FULL-100', 'qty' => 100, 'qty_unit' => 'KGM', 'received_at' => now()]);

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id, 'qty' => 100, 'planned_date' => now()->toDateString(),
        ])->assertRedirect();
        $wo = WorkOrder::latest('id')->firstOrFail();
        $item = $wo->items()->where('child_part_id', $main->id)->firstOrFail();

        // Release lewat APK dengan 1 kemasan utuh 100 kg: harus diterima (200),
        // bukan 422 karena melebihi alokasi part.
        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'pack-full-'.uniqid(),
            'items' => [[
                'work_order_item_id' => $item->id,
                'scans' => [['tag' => 'PACK-FULL-100', 'part_id' => $main->id, 'qty' => 100]],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('work_order_material_bookings', [
            'work_order_id' => $wo->id, 'tag' => 'PACK-FULL-100', 'status' => 'booked',
        ]);
    }
}
