<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Location;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PickingList;
use App\Models\Trolley;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DeliveryLoadingApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Customer $customer;

    private Part $fg;

    private Location $origin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'customer_code' => 'API-DLV-'.uniqid(),
            'customer_name' => 'Customer API',
            'is_active' => true,
        ]);

        $this->fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        Trolley::query()->delete();
        Trolley::create(['code' => 'TRL-1', 'type' => 'internal', 'kind' => 'compbase']);

        $this->origin = Location::firstOrCreate(
            ['code' => 'TPL_DONGSHIN'],
            ['name' => 'TPL DONGSHIN', 'type' => Location::TYPE_MACHINE, 'is_active' => true],
        );
        $this->origin->update(['type' => Location::TYPE_MACHINE, 'is_active' => true]);

        Location::firstOrCreate(
            ['code' => 'DELIVERY-PREP'],
            ['name' => 'Delivery Preparation Area', 'type' => Location::TYPE_DELIVERY_PREP, 'is_active' => true],
        )->update(['type' => Location::TYPE_DELIVERY_PREP, 'is_active' => true]);
    }

    public function test_list_reports_plan_progress_and_scan_deducts_stock(): void
    {
        $stock = PartStock::create([
            'part_id' => $this->fg->id,
            'tag' => 'TAG-API-'.uniqid(),
            'qty' => 10,
            'qty_unit' => 'PCS',
            'location_code' => $this->origin->code,
            'received_at' => now(),
        ]);

        $this->post(route('picking-lists.store'), [
            'picking_date' => now()->toDateString(),
            'items' => [[
                'sequence' => 1,
                'customer_id' => $this->customer->id,
                'part_id' => $this->fg->id,
                'qty' => 6,
                'qty_unit' => 'PCS',
            ]],
        ])->assertRedirect();

        $list = PickingList::latest('id')->firstOrFail();
        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();
        $delivery = Delivery::where('picking_list_id', $list->id)->firstOrFail();

        $this->getJson('/api/deliveries')
            ->assertOk()
            ->assertJsonPath('data.0.id', $delivery->id)
            ->assertJsonPath('data.0.picking_no', $list->picking_no)
            ->assertJsonPath('data.0.total_tags', 1)
            ->assertJsonPath('data.0.scanned_tags', 0)
            ->assertJsonPath('data.0.total_parts', 1)
            ->assertJsonPath('data.0.covered_parts', 0);

        // Kontrak layar loading APK: baris rencana per part tanpa tag.
        $this->getJson("/api/deliveries/{$delivery->id}/loading-context")
            ->assertOk()
            ->assertJsonPath('data.picking_no', $list->picking_no)
            ->assertJsonPath('data.items.0.part_id', $this->fg->id)
            ->assertJsonPath('data.items.0.tag', '')
            ->assertJsonPath('data.items.0.scanned_qty', 0)
            ->assertJsonPath('data.items.0.scanned', false)
            ->assertJsonPath('data.part_progress.0.part_id', $this->fg->id)
            ->assertJsonPath('data.part_progress.0.planned_qty', 6)
            ->assertJsonPath('data.part_progress.0.scanned_qty', 0)
            ->assertJsonPath('data.part_progress.0.remaining_qty', 6)
            ->assertJsonPath('data.part_progress.0.covered', false)
            ->assertJsonPath('data.total_parts', 1)
            ->assertJsonPath('data.covered_parts', 0);

        $this->postJson("/api/deliveries/{$delivery->id}/scan", ['code' => 'TRL-1'])->assertOk();

        $this->postJson("/api/deliveries/{$delivery->id}/scan", [
            'code' => (string) $stock->tag,
            'qty' => 6,
        ])->assertOk()->assertJsonPath('data.status', Delivery::STATUS_PICKED);

        $this->assertEqualsWithDelta(4.0, (float) $stock->fresh()->qty, 1e-6);

        $this->getJson('/api/deliveries')
            ->assertOk()
            ->assertJsonPath('data.0.scanned_tags', 1)
            ->assertJsonPath('data.0.covered_parts', 1);

        $this->getJson("/api/deliveries/{$delivery->id}/loading-context")
            ->assertOk()
            ->assertJsonPath('data.items.0.scanned_qty', 6)
            ->assertJsonPath('data.items.0.scanned', true)
            ->assertJsonPath('data.part_progress.0.scanned_qty', 6)
            ->assertJsonPath('data.part_progress.0.remaining_qty', 0)
            ->assertJsonPath('data.part_progress.0.covered', true)
            ->assertJsonPath('data.covered_parts', 1)
            ->assertJsonPath('data.loading_complete', true);
    }

    public function test_progress_is_reported_per_part_when_one_fg_sits_on_two_rows(): void
    {
        $stock = PartStock::create([
            'part_id' => $this->fg->id,
            'tag' => 'TAG-SPLIT-'.uniqid(),
            'qty' => 20,
            'qty_unit' => 'PCS',
            'location_code' => $this->origin->code,
            'received_at' => now(),
        ]);

        // Tag kedua untuk sisa 4 pcs — satu tag tidak boleh dipindai dua kali.
        $secondStock = PartStock::create([
            'part_id' => $this->fg->id,
            'tag' => 'TAG-SPLIT-'.uniqid(),
            'qty' => 20,
            'qty_unit' => 'PCS',
            'location_code' => $this->origin->code,
            'received_at' => now(),
        ]);

        // FG yang sama muncul di dua sequence untuk satu customer.
        $this->post(route('picking-lists.store'), [
            'picking_date' => now()->toDateString(),
            'items' => [
                [
                    'sequence' => 1,
                    'customer_id' => $this->customer->id,
                    'part_id' => $this->fg->id,
                    'qty' => 6,
                    'qty_unit' => 'PCS',
                ],
                [
                    'sequence' => 2,
                    'customer_id' => $this->customer->id,
                    'part_id' => $this->fg->id,
                    'qty' => 4,
                    'qty_unit' => 'PCS',
                ],
            ],
        ])->assertRedirect();

        $list = PickingList::latest('id')->firstOrFail();
        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();
        $delivery = Delivery::where('picking_list_id', $list->id)->firstOrFail();

        $this->assertSame(2, $delivery->items()->count());

        $context = $this->getJson("/api/deliveries/{$delivery->id}/loading-context")->assertOk();

        // Satu part = satu entri progres; rencananya jumlah kedua baris.
        $context->assertJsonCount(1, 'data.part_progress')
            ->assertJsonPath('data.part_progress.0.part_id', $this->fg->id)
            ->assertJsonPath('data.part_progress.0.planned_qty', 10)
            ->assertJsonPath('data.part_progress.0.scanned_qty', 0)
            ->assertJsonPath('data.part_progress.0.remaining_qty', 10)
            ->assertJsonPath('data.part_progress.0.covered', false)
            ->assertJsonPath('data.total_parts', 1)
            ->assertJsonPath('data.covered_parts', 0);

        // Baris pertama tidak boleh mengunci baris kedua.
        $this->postJson("/api/deliveries/{$delivery->id}/scan", ['code' => 'TRL-1'])->assertOk();
        $this->postJson("/api/deliveries/{$delivery->id}/scan", [
            'code' => (string) $stock->tag,
            'qty' => 6,
        ])->assertOk();

        $context = $this->getJson("/api/deliveries/{$delivery->id}/loading-context")->assertOk();

        // Baris dengan part sama menerima angka identik, dan part belum tertutup.
        $context->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.scanned_qty', 6)
            ->assertJsonPath('data.items.1.scanned_qty', 6)
            ->assertJsonPath('data.items.0.scanned', false)
            ->assertJsonPath('data.items.1.scanned', false)
            ->assertJsonPath('data.part_progress.0.scanned_qty', 6)
            ->assertJsonPath('data.part_progress.0.remaining_qty', 4)
            ->assertJsonPath('data.part_progress.0.covered', false)
            ->assertJsonPath('data.loading_complete', false);

        $this->postJson("/api/deliveries/{$delivery->id}/scan", [
            'code' => (string) $secondStock->tag,
            'qty' => 4,
        ])->assertOk();

        $this->getJson("/api/deliveries/{$delivery->id}/loading-context")
            ->assertOk()
            ->assertJsonPath('data.part_progress.0.remaining_qty', 0)
            ->assertJsonPath('data.part_progress.0.covered', true)
            ->assertJsonPath('data.items.1.scanned', true)
            ->assertJsonPath('data.covered_parts', 1)
            ->assertJsonPath('data.loading_complete', true);
    }
}
