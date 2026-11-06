<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseOrderItemSizeTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_purchase_order_item_size_is_saved_and_rendered_on_detail(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = $supplier->partSubstitutes()->where('is_active', true)->firstOrFail()->substitutePart;

        $response = $this->post(route('purchase-orders.store'), [
            'po_no' => 'PO-SIZE-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [
                [
                    'part_id' => $part->id,
                    'size' => '20 x 30 x 1.2',
                    'qty' => 12,
                    'unit' => 'PCS',
                    'price' => 100,
                ],
            ],
        ]);

        $po = PurchaseOrder::where('po_no', 'like', 'PO-SIZE-%')->latest('id')->firstOrFail();
        $item = PurchaseOrderItem::where('purchase_order_id', $po->id)->firstOrFail();

        $this->assertSame('20 x 30 x 1.2', $item->size);
        $response->assertRedirect(route('purchase-orders.show', $po));
        $this->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('20 x 30 x 1.2');
    }

    public function test_purchase_order_update_persists_item_size(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = $supplier->partSubstitutes()->where('is_active', true)->firstOrFail()->substitutePart;
        $po = PurchaseOrder::create([
            'po_no' => 'PO-SIZE-UPD-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'draft',
        ]);

        $this->put(route('purchase-orders.update', $po), [
            'po_no' => $po->po_no,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [
                [
                    'part_id' => $part->id,
                    'size' => 'M8 x 25',
                    'qty' => 24,
                    'unit' => 'PCS',
                ],
            ],
        ])->assertRedirect(route('purchase-orders.show', $po));

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $po->id,
            'part_id' => $part->id,
            'size' => 'M8 x 25',
        ]);
    }
}
