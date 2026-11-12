<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Location;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\WipInventoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Customer $customer;

    private Location $machineLocation;

    private Location $prepLocation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'customer_code' => 'CUST-'.uniqid(),
            'customer_name' => 'Budi Jaya',
            'address' => 'Jl. Industri 1',
            'is_active' => true,
        ]);

        // Lokasi mesin dan area pengiriman (seeder sudah membuatnya; pastikan ada).
        $this->machineLocation = Location::firstOrCreate(
            ['code' => 'TPL_DONGSHIN'],
            ['name' => 'TPL DONGSHIN', 'type' => Location::TYPE_MACHINE, 'is_active' => true],
        );
        $this->machineLocation->update(['type' => Location::TYPE_MACHINE, 'is_active' => true]);

        $this->prepLocation = Location::firstOrCreate(
            ['code' => 'DELIVERY-PREP'],
            ['name' => 'Delivery Preparation Area', 'type' => Location::TYPE_DELIVERY_PREP, 'is_active' => true],
        );
        $this->prepLocation->update(['type' => Location::TYPE_DELIVERY_PREP, 'is_active' => true]);
    }

    private function fgPart(): Part
    {
        return Part::where('part_number', 'AAN30056405')->firstOrFail();
    }

    /** Stok barang jadi dengan lokasi mesin pembuat. */
    private function fgStock(string $tag, float $qty, ?string $locationCode = null): PartStock
    {
        return PartStock::create([
            'part_id' => $this->fgPart()->id,
            'tag' => $tag,
            'qty' => $qty,
            'qty_unit' => 'PCS',
            'location_code' => $locationCode ?? $this->machineLocation->code,
            'received_at' => now(),
        ]);
    }

    public function test_delivery_number_is_sequential_and_not_reused_after_delete(): void
    {
        $first = Delivery::generateDeliveryNo(now());
        Delivery::create([
            'delivery_no' => $first,
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'status' => Delivery::STATUS_DRAFT,
        ]);

        $second = Delivery::generateDeliveryNo(now());
        $this->assertNotSame($first, $second);

        Delivery::where('delivery_no', $first)->firstOrFail()->delete();

        $third = Delivery::generateDeliveryNo(now());
        $this->assertNotSame($first, $third, 'Nomor yang sudah dihapus tidak boleh dipakai ulang');
    }

    public function test_available_tags_are_sorted_fifo(): void
    {
        $older = $this->fgStock('TAG-OLD-'.uniqid(), 10);
        $older->forceFill(['received_at' => now()->subDays(3)])->save();
        $newer = $this->fgStock('TAG-NEW-'.uniqid(), 10);
        $newer->forceFill(['received_at' => now()->subDay()])->save();

        $tags = app(DeliveryService::class)->availableTags($this->fgPart()->id);

        $this->assertGreaterThanOrEqual(2, count($tags));
        $this->assertSame($older->tag, $tags[0]['tag'], 'Tag tertua harus muncul lebih dulu');
    }

    public function test_store_creates_draft_with_items_and_keeps_stock_location(): void
    {
        $stock = $this->fgStock('TAG-STORE-'.uniqid(), 50);

        $this->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'truck_no' => 'B 9123 GCI',
            'driver_name' => 'Slamet',
            'items' => [
                ['part_stock_id' => $stock->id, 'qty' => 20],
            ],
        ])->assertRedirect();

        $delivery = Delivery::latest('id')->firstOrFail();
        $this->assertSame(Delivery::STATUS_DRAFT, $delivery->status);
        $this->assertCount(1, $delivery->items);
        $this->assertSame($this->machineLocation->code, $stock->fresh()->location_code, 'Draft belum memindah barang');
    }

    public function test_store_rejects_quantity_above_tag_stock(): void
    {
        $stock = $this->fgStock('TAG-OVER-'.uniqid(), 5);

        $this->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'items' => [
                ['part_stock_id' => $stock->id, 'qty' => 10],
            ],
        ])->assertSessionHasErrors('items');

        $this->assertSame(0, Delivery::count());
    }

    public function test_store_rejects_the_same_tag_twice(): void
    {
        $stock = $this->fgStock('TAG-DUP-'.uniqid(), 50);

        $this->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'items' => [
                ['part_stock_id' => $stock->id, 'qty' => 10],
                ['part_stock_id' => $stock->id, 'qty' => 5],
            ],
        ])->assertSessionHasErrors('items');
    }

    public function test_store_rejects_non_finished_good_stock(): void
    {
        $material = Part::where('part_number', 'CBKG07256C')->firstOrFail();
        $stock = PartStock::create([
            'part_id' => $material->id,
            'tag' => 'MAT-'.uniqid(),
            'qty' => 100,
            'qty_unit' => 'KGM',
            'received_at' => now(),
        ]);

        $this->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'items' => [
                ['part_stock_id' => $stock->id, 'qty' => 10],
            ],
        ])->assertSessionHasErrors('items');
    }

    public function test_pick_moves_stock_to_preparation_and_marks_picked(): void
    {
        $stock = $this->fgStock('TAG-PICK-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();

        $this->assertSame($this->prepLocation->code, $stock->fresh()->location_code);
        $this->assertSame(Delivery::STATUS_PICKED, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->picked_at);
        $this->assertSame($this->machineLocation->code, $delivery->items()->first()->origin_location_code);
    }

    public function test_return_to_origin_restores_location_and_status(): void
    {
        $stock = $this->fgStock('TAG-RET-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.return', $delivery))->assertRedirect();

        $this->assertSame($this->machineLocation->code, $stock->fresh()->location_code);
        $this->assertSame(Delivery::STATUS_DRAFT, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->picked_at);
    }

    public function test_deleting_a_draft_does_not_change_stock_location(): void
    {
        $stock = $this->fgStock('TAG-DEL-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->delete(route('deliveries.destroy', $delivery))->assertRedirect(route('deliveries.index'));

        $this->assertSame($this->machineLocation->code, $stock->fresh()->location_code);
    }

    public function test_picked_delivery_contributes_to_fg_issue_in_wip_inventory(): void
    {
        $stock = $this->fgStock('TAG-WIP-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();

        $machine = Machine::where('machine_code', $this->machineLocation->code)->firstOrFail();
        $today = now()->toDateString();

        $rows = app(WipInventoryService::class)
            ->forRange($today, $today, $machine->id, WipInventoryService::CATEGORY_WIP_FG);

        $row = collect($rows)->firstWhere('part_id', $this->fgPart()->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(25.0, (float) $row['issued_qty'], 1e-6);
    }

    public function test_ship_marks_delivery_shipped(): void
    {
        $stock = $this->fgStock('TAG-SHIP-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.ship', $delivery))->assertRedirect();

        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::STATUS_SHIPPED, $fresh->status);
        $this->assertNotNull($fresh->shipped_at);
    }

    public function test_surat_jalan_prints_delivery_number_customer_and_tag(): void
    {
        $tag = 'TAG-SJ-'.uniqid();
        $stock = $this->fgStock($tag, 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->get(route('deliveries.print', $delivery))
            ->assertOk()
            ->assertSee('SURAT JALAN')
            ->assertSee($delivery->delivery_no)
            ->assertSee($this->customer->customer_name)
            ->assertSee($tag);
    }

    public function test_creating_a_delivery_requires_permission(): void
    {
        // QC boleh melihat daftar, tetapi tidak boleh membuat pengiriman.
        $noAccess = User::where('email', 'qc@geumcheon.local')->firstOrFail();

        $this->actingAs($noAccess)->get(route('deliveries.index'))->assertOk();
        $this->actingAs($noAccess)->get(route('deliveries.create'))->assertForbidden();
    }

    public function test_customer_master_can_be_created_and_updated(): void
    {
        $code = 'CUST-NEW-'.uniqid();

        $this->post(route('customers.store'), [
            'customer_code' => $code,
            'customer_name' => 'Customer Baru',
            'address' => 'Jl. Baru 2',
            'is_active' => true,
        ])->assertRedirect(route('customers.index'));

        $customer = Customer::where('customer_code', $code)->firstOrFail();

        $this->put(route('customers.update', $customer), [
            'customer_code' => $code,
            'customer_name' => 'Customer Diubah',
            'is_active' => true,
        ])->assertRedirect(route('customers.index'));

        $this->assertSame('Customer Diubah', $customer->fresh()->customer_name);
    }

    private function makeDelivery(PartStock $stock, float $qty): Delivery
    {
        $this->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'items' => [
                ['part_stock_id' => $stock->id, 'qty' => $qty],
            ],
        ])->assertRedirect();

        return Delivery::latest('id')->firstOrFail();
    }
}
