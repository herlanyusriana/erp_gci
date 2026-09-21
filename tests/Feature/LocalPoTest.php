<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LocalPoTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'po_no' => 'LPO-TEST-001',
            'invoice_no' => 'INV-LOCAL-001',
            'po_date' => now()->toDateString(),
            'supplier_id' => Supplier::query()->value('id'),
            'currency' => 'IDR',
            'items' => [[
                'part_id' => Part::where('part_number', 'CBKG07256C')->value('id'),
                'size' => 'L',
                'qty_goods' => 100,
                'unit_goods' => 'BAG',
                'qty_bundle' => 100,
                'unit_bundle' => 'BAG',
                'weight_nett' => 2500,
                'weight_gross' => 2600,
                'price' => 1000,
            ]],
        ], $overrides);
    }

    public function test_local_po_stores_po_no_and_invoice_no_separately(): void
    {
        $this->post(route('local-pos.store'), $this->payload())->assertRedirect();

        $this->assertDatabaseHas('incoming_arrivals', [
            'po_no' => 'LPO-TEST-001',
            'invoice_no' => 'INV-LOCAL-001',
            'is_local' => true,
        ]);

        $this->assertDatabaseHas('incoming_arrival_items', [
            'qty_goods' => 100,
            'unit_goods' => 'BAG',
            'qty_bundle' => 100,
            'unit_bundle' => 'BAG',
            'weight_nett' => 2500,
        ]);
    }

    public function test_local_po_rejects_duplicate_po_no(): void
    {
        $this->post(route('local-pos.store'), $this->payload())->assertRedirect();

        $this->post(route('local-pos.store'), $this->payload(['invoice_no' => 'INV-LOCAL-002']))
            ->assertSessionHasErrors('po_no');
    }

    public function test_local_po_requires_item_net_weight(): void
    {
        $payload = $this->payload();
        unset($payload['items'][0]['weight_nett']);

        $this->post(route('local-pos.store'), $payload)->assertSessionHasErrors('items.0.weight_nett');
    }

    public function test_local_receive_is_weight_based_and_posts_stock_in_material_unit(): void
    {
        $this->post(route('local-pos.store'), $this->payload())->assertRedirect();

        $item = IncomingArrivalItem::query()
            ->whereHas('arrival', fn ($q) => $q->where('po_no', 'LPO-TEST-001'))
            ->firstOrFail();

        // Basis berat: net_weight wajib.
        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'tags' => [['tag' => 'T-LOCAL-001', 'qty' => 100, 'qty_unit' => 'BAG']],
        ])->assertSessionHasErrors('tags.0.net_weight');

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'tags' => [[
                'tag' => 'T-LOCAL-001',
                'qty' => 100,
                'qty_unit' => 'BAG',
                'net_weight' => 2500,
            ]],
        ])->assertRedirect();

        $stock = PartStock::where('tag', 'T-LOCAL-001')->firstOrFail();
        $this->assertSame('BAG', $stock->qty_unit);
        $this->assertEqualsWithDelta(100.0, (float) $stock->qty, 0.001);

        $arrival = IncomingArrival::where('po_no', 'LPO-TEST-001')->firstOrFail();
        $this->assertNotNull($arrival->arrival_no);
    }
}
