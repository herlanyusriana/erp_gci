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

class IncomingArrivalPoFilterTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_purchase_orders_feed_in_arrival_create_includes_remaining_qty_and_excludes_completed(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        // 1. PO Aktif dengan sisa
        $poActive = PurchaseOrder::create([
            'po_no' => 'PO-ACT-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'confirmed',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $poActive->id,
            'part_id' => $part->id,
            'qty' => 100,
            'price' => 1000,
        ]);

        // 2. PO Selesai 100%
        $poFulfilled = PurchaseOrder::create([
            'po_no' => 'PO-FUL-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'confirmed',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $poFulfilled->id,
            'part_id' => $part->id,
            'qty' => 50,
            'price' => 1000,
        ]);
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-FUL-'.uniqid(),
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $poFulfilled->id,
            'status' => 'pending',
            'is_local' => false,
        ]);
        IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 50,
            'weight_nett' => 50,
        ]);

        // Request ke create arrival
        $res = $this->get(route('incoming-arrivals.create'))->assertOk();
        $page = $res->original->getData()['page'];
        $pos = collect($page['props']['purchaseOrders']);

        // PO Aktif harus ada dengan remaining_qty = 100
        $activeItem = $pos->firstWhere('id', $poActive->id);
        $this->assertNotNull($activeItem);
        $this->assertEquals(100.0, $activeItem['remaining_qty']);

        // PO yang sudah 100% terpenuhi harus dikeluarkan dari daftar
        $fulfilledItem = $pos->firstWhere('id', $poFulfilled->id);
        $this->assertNull($fulfilledItem);
    }
}
