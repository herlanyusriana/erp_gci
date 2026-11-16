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
            ->assertJsonPath('data.0.total_tags', 1)
            ->assertJsonPath('data.0.scanned_tags', 0);

        $this->postJson("/api/deliveries/{$delivery->id}/scan", ['code' => 'TRL-1'])->assertOk();

        $this->postJson("/api/deliveries/{$delivery->id}/scan", [
            'code' => (string) $stock->tag,
            'qty' => 6,
        ])->assertOk()->assertJsonPath('data.status', Delivery::STATUS_PICKED);

        $this->assertEqualsWithDelta(4.0, (float) $stock->fresh()->qty, 1e-6);

        $this->getJson('/api/deliveries')
            ->assertOk()
            ->assertJsonPath('data.0.scanned_tags', 1);
    }
}
