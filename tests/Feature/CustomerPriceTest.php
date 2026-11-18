<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Delivery;
use App\Models\Location;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PickingList;
use App\Models\Trolley;
use App\Models\User;
use App\Services\DeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerPriceTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Customer $customer;

    private Part $fg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'customer_code' => 'CP-'.uniqid(),
            'customer_name' => 'Customer Harga',
            'is_active' => true,
        ]);

        $this->fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        Trolley::query()->delete();
        Trolley::create(['code' => 'TRL-1', 'type' => 'internal', 'kind' => 'compbase']);

        Location::firstOrCreate(
            ['code' => 'TPL_DONGSHIN'],
            ['name' => 'TPL DONGSHIN', 'type' => Location::TYPE_MACHINE, 'is_active' => true],
        )->update(['type' => Location::TYPE_MACHINE, 'is_active' => true]);

        Location::firstOrCreate(
            ['code' => 'DELIVERY-PREP'],
            ['name' => 'Delivery Preparation Area', 'type' => Location::TYPE_DELIVERY_PREP, 'is_active' => true],
        )->update(['type' => Location::TYPE_DELIVERY_PREP, 'is_active' => true]);
    }

    public function test_page_lists_finished_goods_with_existing_prices(): void
    {
        CustomerPrice::create([
            'customer_id' => $this->customer->id,
            'part_id' => $this->fg->id,
            'price' => 12500,
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $response = $this->get(route('customer-prices.index', ['customer_id' => $this->customer->id]));

        $response->assertOk();
        $parts = collect($response->viewData('page')['props']['parts']);
        $row = $parts->firstWhere('id', $this->fg->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(12500.0, (float) $row['price'], 1e-6);
        $this->assertSame('IDR', $row['currency']);
    }

    public function test_bulk_store_upserts_and_clears_empty_prices(): void
    {
        $this->post(route('customer-prices.store'), [
            'customer_id' => $this->customer->id,
            'prices' => [
                ['part_id' => $this->fg->id, 'price' => 1000, 'currency' => 'IDR', 'is_active' => true],
            ],
        ])->assertRedirect(route('customer-prices.index', ['customer_id' => $this->customer->id]));

        $this->assertEqualsWithDelta(1000.0, (float) CustomerPrice::firstOrFail()->price, 1e-6);

        // Simpan ulang dengan harga baru, lalu kosongkan.
        $this->post(route('customer-prices.store'), [
            'customer_id' => $this->customer->id,
            'prices' => [
                ['part_id' => $this->fg->id, 'price' => 2500, 'currency' => 'usd', 'is_active' => true],
            ],
        ])->assertRedirect();

        $price = CustomerPrice::firstOrFail();
        $this->assertEqualsWithDelta(2500.0, (float) $price->price, 1e-6);
        $this->assertSame('USD', $price->currency);

        $this->post(route('customer-prices.store'), [
            'customer_id' => $this->customer->id,
            'prices' => [
                ['part_id' => $this->fg->id, 'price' => null],
            ],
        ])->assertRedirect();

        $this->assertSame(0, CustomerPrice::count());
    }

    /**
     * Setiap props baru (POST gagal / pencarian ber-debounce) dulu membangun
     * ulang seluruh `form.prices`, menghapus harga yang sedang diketik. Re-seed
     * harus dijaga perbandingan customer; selain itu nilai yang sudah ada
     * dipertahankan dan baris baru (mis. muncul setelah pencarian) ditambahkan.
     */
    public function test_price_form_only_reseeds_rows_when_customer_changes(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Master/CustomerPrice/Index.vue'));
        $this->assertNotFalse($source);

        $watchStart = strpos($source, 'watch(');
        $guardPos = strpos($source, 'if (currentCustomer !== lastSeededCustomer.value)');
        $reseedPos = strpos($source, 'form.prices = parts.map(toPriceRow)');

        $this->assertNotFalse($watchStart, 'Halaman harus tetap memakai watch props.');
        $this->assertNotFalse($guardPos, 'Re-seed harus dijaga perbandingan customer.');
        $this->assertNotFalse($reseedPos, 'Ganti customer harus memuat ulang harga dari server.');
        $this->assertGreaterThan($watchStart, $guardPos);
        $this->assertGreaterThan($guardPos, $reseedPos, 'Re-seed hanya boleh terjadi di cabang ganti customer.');

        $this->assertStringContainsString('existing.get(part.id) ?? toPriceRow(part)', $source);
        $this->assertStringContainsString('const hidden = form.prices.filter', $source);
        $this->assertStringContainsString('form.prices = [...merged, ...hidden]', $source);
        $this->assertSame(
            1,
            substr_count($source, 'form.prices = parts.map'),
            'Hanya cabang ganti customer yang boleh menimpa seluruh baris.',
        );
    }

    /**
     * Server hanya menyentuh `part_id` yang dikirim. Baris yang tersaring
     * pencarian dan tidak ikut terkirim tetap utuh — jadi keputusan frontend
     * (menyertakan baris tersembunyi) aman, dan seandainya pun tidak dikirim,
     * tidak ada harga lain yang terhapus.
     */
    public function test_bulk_store_leaves_parts_outside_the_payload_untouched(): void
    {
        $other = Part::where('part_number', '4114EAQ1141L')->firstOrFail();

        CustomerPrice::create([
            'customer_id' => $this->customer->id,
            'part_id' => $this->fg->id,
            'price' => 12500,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        CustomerPrice::create([
            'customer_id' => $this->customer->id,
            'part_id' => $other->id,
            'price' => 9000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->post(route('customer-prices.store'), [
            'customer_id' => $this->customer->id,
            'prices' => [
                ['part_id' => $this->fg->id, 'price' => 15000, 'currency' => 'IDR', 'is_active' => true],
            ],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(15000.0, (float) CustomerPrice::where('part_id', $this->fg->id)->value('price'), 1e-6);
        $this->assertEqualsWithDelta(9000.0, (float) CustomerPrice::where('part_id', $other->id)->value('price'), 1e-6);
    }

    public function test_price_is_copied_into_the_delivery_when_picking_generates_it(): void
    {
        PartStock::create([
            'part_id' => $this->fg->id,
            'tag' => 'TAG-CP-'.uniqid(),
            'qty' => 100,
            'qty_unit' => 'PCS',
            'location_code' => 'TPL_DONGSHIN',
            'received_at' => now(),
        ]);

        CustomerPrice::create([
            'customer_id' => $this->customer->id,
            'part_id' => $this->fg->id,
            'price' => 4000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->post(route('picking-lists.store'), [
            'picking_date' => now()->toDateString(),
            'items' => [[
                'sequence' => 1,
                'customer_id' => $this->customer->id,
                'part_id' => $this->fg->id,
                'qty' => 5,
                'qty_unit' => 'PCS',
            ]],
        ])->assertRedirect();

        $list = PickingList::latest('id')->firstOrFail();
        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();

        $item = Delivery::where('picking_list_id', $list->id)->firstOrFail()->items()->firstOrFail();
        $this->assertEqualsWithDelta(4000.0, (float) $item->price, 1e-6);
        $this->assertEqualsWithDelta(20000.0, (float) $item->total_price, 1e-6);
    }

    public function test_inactive_price_is_not_applied(): void
    {
        PartStock::create([
            'part_id' => $this->fg->id,
            'tag' => 'TAG-CP-OFF-'.uniqid(),
            'qty' => 100,
            'qty_unit' => 'PCS',
            'location_code' => 'TPL_DONGSHIN',
            'received_at' => now(),
        ]);

        CustomerPrice::create([
            'customer_id' => $this->customer->id,
            'part_id' => $this->fg->id,
            'price' => 4000,
            'currency' => 'IDR',
            'is_active' => false,
        ]);

        $this->post(route('picking-lists.store'), [
            'picking_date' => now()->toDateString(),
            'items' => [[
                'sequence' => 1,
                'customer_id' => $this->customer->id,
                'part_id' => $this->fg->id,
                'qty' => 5,
                'qty_unit' => 'PCS',
            ]],
        ])->assertRedirect();

        $list = PickingList::latest('id')->firstOrFail();
        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();

        $item = Delivery::where('picking_list_id', $list->id)->firstOrFail()->items()->firstOrFail();
        $this->assertNull($item->price);
        $this->assertNull($item->total_price);
    }

    public function test_invoice_prints_the_customer_price(): void
    {
        $stock = PartStock::create([
            'part_id' => $this->fg->id,
            'tag' => 'TAG-CP-INV-'.uniqid(),
            'qty' => 10,
            'qty_unit' => 'PCS',
            'location_code' => 'TPL_DONGSHIN',
            'received_at' => now(),
        ]);

        $delivery = Delivery::create([
            'delivery_no' => Delivery::generateDeliveryNo(now()),
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'status' => Delivery::STATUS_DRAFT,
            'invoice_no' => 'INV/CP/1',
            'created_by' => $this->admin->id,
        ]);

        $delivery->items()->create([
            'part_id' => $this->fg->id,
            'qty' => 4,
            'qty_unit' => 'PCS',
            'sequence' => 1,
            'price' => 7500,
            'total_price' => 30000,
        ]);

        // Cetak dokumen butuh OQC lolos.
        $this->post(route('deliveries.scan', $delivery), ['code' => 'TRL-1'])->assertRedirect();
        $this->post(route('deliveries.scan', $delivery), ['code' => (string) $stock->tag])->assertRedirect();

        $service = app(DeliveryService::class);
        $service->setTrolleyQc($delivery->fresh(), 'TRL-1', Delivery::QC_PASSED, $this->admin->id);
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();

        $this->get(route('deliveries.invoice', $delivery))
            ->assertOk()
            ->assertSee('7.500,00')
            ->assertSee('30.000,00')
            ->assertSee((string) $stock->tag);
    }

    public function test_changing_prices_requires_permission(): void
    {
        $qc = User::where('email', 'qc@geumcheon.local')->firstOrFail();

        $this->actingAs($qc)->get(route('customer-prices.index'))->assertOk();
        $this->actingAs($qc)->post(route('customer-prices.store'), [
            'customer_id' => $this->customer->id,
            'prices' => [['part_id' => $this->fg->id, 'price' => 100]],
        ])->assertForbidden();

        $this->assertSame(0, CustomerPrice::count());
    }
}
