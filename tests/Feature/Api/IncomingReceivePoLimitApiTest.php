<?php

namespace Tests\Feature\Api;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IncomingReceivePoLimitApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    public function test_api_receive_rejects_quantity_above_purchase_order_quantity(): void
    {
        $supplier = Supplier::where('is_active', true)
            ->whereHas('partSubstitutes', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();
        $part = $supplier->partSubstitutes()->where('is_active', true)->firstOrFail()->substitutePart;

        $po = PurchaseOrder::create([
            'po_no' => 'PO-API-LIMIT-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'confirmed',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'part_id' => $part->id,
            'qty' => 10,
            'unit' => 'PCS',
        ]);

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-API-LIMIT-'.uniqid(),
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'is_local' => false,
            'status' => 'pending',
            'iqc_status' => 'passed',
        ]);
        $item = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 50,
            'unit_goods' => 'PCS',
            'weight_nett' => null,
        ]);

        $this->postJson('/api/incoming/arrival-items/'.$item->id.'/receive', [
            'receive_date' => now()->toDateString(),
            'tag' => 'T-API-PO-LIMIT-1',
            'qty' => 50,
            'qc_status' => 'pass',
        ])->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertDatabaseMissing('incoming_receives', ['tag' => 'T-API-PO-LIMIT-1']);
    }

    public function test_api_receive_allows_quantity_within_purchase_order_quantity(): void
    {
        $supplier = Supplier::where('is_active', true)
            ->whereHas('partSubstitutes', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();
        $part = $supplier->partSubstitutes()->where('is_active', true)->firstOrFail()->substitutePart;

        $po = PurchaseOrder::create([
            'po_no' => 'PO-API-OK-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'confirmed',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'part_id' => $part->id,
            'qty' => 10,
            'unit' => 'PCS',
        ]);

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-API-OK-'.uniqid(),
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'is_local' => false,
            'status' => 'pending',
            'iqc_status' => 'passed',
        ]);
        $item = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 10,
            'unit_goods' => 'PCS',
            'weight_nett' => null,
        ]);

        $this->postJson('/api/incoming/arrival-items/'.$item->id.'/receive', [
            'receive_date' => now()->toDateString(),
            'tag' => 'T-API-PO-LIMIT-OK',
            'qty' => 10,
            'qc_status' => 'pass',
        ])->assertOk()->assertJson(['ok' => true]);
    }
}
