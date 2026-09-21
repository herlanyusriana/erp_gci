<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartStock;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterialBooking;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WorkOrderHttpTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->first());
    }

    public function test_index_and_create_pages_load(): void
    {
        $this->get(route('work-orders.index'))->assertStatus(200);
        $this->get(route('work-orders.create'))->assertStatus(200);
        $this->get(route('production-data'))->assertStatus(200);
    }

    public function test_full_lifecycle_create_release_complete_destroy(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->first();
        $this->assertNotNull($fg);

        // store
        $resp = $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 10,
            'planned_date' => now()->toDateString(),
            'remarks' => 'phpunit',
        ]);
        $resp->assertRedirect();

        $wo = WorkOrder::latest('id')->first();
        $this->assertNotNull($wo);
        $this->assertSame('planned', $wo->status);
        $this->assertGreaterThan(0, $wo->items()->count());

        // show
        $this->get(route('work-orders.show', $wo))->assertStatus(200);

        // seed stock and release
        $seed = [
            'CBKG07256C' => ['KGM', 20],
            '4000W4A003A' => ['PCS', 20],
            'PINCB01' => ['PCS', 80],
            '5040JA3071C' => ['PCS', 80],
        ];
        foreach ($seed as $pn => $d) {
            $p = Part::where('part_number', $pn)->first();
            PartStock::create([
                'part_id' => $p->id, 'tag' => 'T-'.$pn,
                'qty' => $d[1], 'qty_unit' => $d[0], 'received_at' => now(),
            ]);
        }

        $this->post(route('work-orders.release', $wo))->assertRedirect();
        $wo->refresh();
        $this->assertSame('in_progress', $wo->status);

        // Release hanya BOOKING: stok fisik belum berkurang, belum ada konsumsi.
        $this->assertSame(0, $wo->consumptions()->count());
        $this->assertGreaterThan(0, WorkOrderMaterialBooking::where('work_order_id', $wo->id)->where('status', 'booked')->count());
        $this->assertEqualsWithDelta(20.0, (float) PartStock::where('part_id', Part::where('part_number', 'CBKG07256C')->value('id'))->sum('qty'), 0.001);

        // Output WIP/FG lahir dari Production Result.
        $fgStock = PartStock::where('part_id', $fg->id)->where('qty', '>', 0)->sum('qty');
        $this->assertEqualsWithDelta(0.0, (float) $fgStock, 0.001);

        // complete
        $this->post(route('work-orders.complete', $wo))->assertRedirect();
        $wo->refresh();
        $this->assertSame('completed', $wo->status);

        // destroy
        $this->delete(route('work-orders.destroy', $wo))->assertRedirect();
        $this->assertNull(WorkOrder::find($wo->id));
    }

    public function test_release_with_shortage_warns_and_consumes_available(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->first();

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 10,
            'planned_date' => now()->toDateString(),
        ])->assertRedirect();
        $wo = WorkOrder::latest('id')->first();

        // Only 5 KGM of the binding leaf
        foreach ([
            'CBKG07256C' => ['KGM', 5],
            '4000W4A003A' => ['PCS', 100],
            'PINCB01' => ['PCS', 400],
            '5040JA3071C' => ['PCS', 400],
        ] as $pn => $d) {
            $p = Part::where('part_number', $pn)->first();
            PartStock::create(['part_id' => $p->id, 'tag' => 'T-'.$pn, 'qty' => $d[1], 'qty_unit' => $d[0], 'received_at' => now()]);
        }

        $this->post(route('work-orders.release', $wo))->assertRedirect();
        $wo->refresh();
        $this->assertSame('in_progress', $wo->status);

        // Shortage dilaporkan; RM yang tersedia sudah di-book (belum dikonsumsi).
        $booked = (float) WorkOrderMaterialBooking::where('work_order_id', $wo->id)
            ->where('status', 'booked')->sum('qty');
        $this->assertGreaterThan(0.0, $booked);
        $this->assertSame(0, $wo->consumptions()->count());

        $available = PartStock::where('part_id', $fg->id)->where('qty', '>', 0)->sum('qty');
        $this->assertEqualsWithDelta(0.0, (float) $available, 0.001);
    }
}
