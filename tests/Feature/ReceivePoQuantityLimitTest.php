<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReceivePoQuantityLimitTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Supplier $supplier;

    private Part $part;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);

        $this->supplier = Supplier::where('is_active', true)
            ->whereHas('partSubstitutes', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();
        $this->part = $this->supplier->partSubstitutes()
            ->where('is_active', true)
            ->firstOrFail()
            ->substitutePart;
    }

    private function makePo(float $qty = 100, string $unit = 'PCS'): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_no' => 'PO-LIMIT-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'status' => 'confirmed',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'part_id' => $this->part->id,
            'qty' => $qty,
            'unit' => $unit,
        ]);

        return $po;
    }

    private function makeArrival(PurchaseOrder $po, float $qty, string $unit = 'PCS'): IncomingArrivalItem
    {
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-LIMIT-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'is_local' => false,
            'status' => 'pending',
            'iqc_status' => 'passed',
        ]);

        return IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $this->part->id,
            'qty_goods' => $qty,
            'unit_goods' => $unit,
            'weight_nett' => null,
        ]);
    }

    public function test_receive_cannot_exceed_purchase_order_quantity(): void
    {
        $po = $this->makePo(100);
        $item = $this->makeArrival($po, 500);

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'tags' => [[
                'tag' => 'T-PO-LIMIT-1',
                'qty' => 500,
                'qty_unit' => 'PCS',
            ]],
        ])->assertSessionHasErrors('tags');

        $this->assertDatabaseMissing('incoming_receives', ['tag' => 'T-PO-LIMIT-1']);
    }

    public function test_receive_up_to_purchase_order_quantity_is_allowed_and_the_next_one_is_blocked(): void
    {
        $po = $this->makePo(100);
        $item = $this->makeArrival($po, 100);

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'tags' => [[
                'tag' => 'T-PO-LIMIT-2',
                'qty' => 100,
                'qty_unit' => 'PCS',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('incoming_receives', ['tag' => 'T-PO-LIMIT-2']);

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'tags' => [[
                'tag' => 'T-PO-LIMIT-3',
                'qty' => 1,
                'qty_unit' => 'PCS',
            ]],
        ])->assertSessionHasErrors('tags');

        $this->assertDatabaseMissing('incoming_receives', ['tag' => 'T-PO-LIMIT-3']);
    }

    public function test_second_arrival_cannot_exceed_remaining_purchase_order_quantity(): void
    {
        $po = $this->makePo(100);
        $this->makeArrival($po, 60);

        $this->post(route('incoming-arrivals.store'), [
            'is_local' => true,
            'purchase_order_id' => $po->id,
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'items' => [[
                'part_id' => $this->part->id,
                'qty_goods' => 60,
                'unit_goods' => 'PCS',
            ]],
        ])->assertSessionHasErrors('items.0.qty_goods');

        $this->assertSame(1, IncomingArrival::where('purchase_order_id', $po->id)->count());
    }

    public function test_receives_across_two_arrivals_cannot_exceed_purchase_order_quantity(): void
    {
        $po = $this->makePo(100);
        $first = $this->makeArrival($po, 60);
        $second = $this->makeArrival($po, 40);

        $this->post(route('receive.store', $first), [
            'receive_date' => now()->toDateString(),
            'tags' => [['tag' => 'T-PO-SPLIT-1', 'qty' => 60, 'qty_unit' => 'PCS']],
        ])->assertSessionHasNoErrors();

        // 41 melebihi sisa PO (40) walau masih di bawah qty Arrival kedua.
        $this->post(route('receive.store', $second), [
            'receive_date' => now()->toDateString(),
            'tags' => [['tag' => 'T-PO-SPLIT-2', 'qty' => 41, 'qty_unit' => 'PCS']],
        ])->assertSessionHasErrors('tags');

        $this->assertDatabaseMissing('incoming_receives', ['tag' => 'T-PO-SPLIT-2']);
    }

    public function test_updating_an_arrival_does_not_count_its_own_items_against_the_po(): void
    {
        $po = $this->makePo(100);
        $item = $this->makeArrival($po, 100);
        $arrival = $item->arrival;

        $this->put(route('incoming-arrivals.update', $arrival), [
            'is_local' => true,
            'purchase_order_id' => $po->id,
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'status' => 'pending',
            'items' => [[
                'id' => $item->id,
                'part_id' => $this->part->id,
                'qty_goods' => 100,
                'unit_goods' => 'PCS',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(100.0, (float) $item->fresh()->qty_goods, 0.001);
    }

    public function test_arrival_cannot_exceed_purchase_order_quantity(): void
    {
        $po = $this->makePo(100);

        $this->post(route('incoming-arrivals.store'), [
            'is_local' => true,
            'purchase_order_id' => $po->id,
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'items' => [[
                'part_id' => $this->part->id,
                'qty_goods' => 500,
                'unit_goods' => 'PCS',
            ]],
        ])->assertSessionHasErrors('items.0.qty_goods');
    }
}
