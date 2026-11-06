<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IncomingPoArrivalTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_purchase_order_can_be_created_for_local_and_import_suppliers(): void
    {
        $localSupp = Supplier::where('source', 'local')->where('is_active', true)->firstOrFail();
        $importSupp = Supplier::where('source', 'import')->where('is_active', true)->firstOrFail();

        // 1. PO Supplier Lokal
        $resLocal = $this->get(route('purchase-orders.create'))->assertOk();
        $resLocal->assertSee($localSupp->supplier_name);
        $resLocal->assertSee($importSupp->supplier_name);

        $partLocal = $localSupp->partSubstitutes()->where('is_active', true)->first()?->substitutePart;
        if ($partLocal) {
            $this->post(route('purchase-orders.store'), [
                'po_no' => 'PO-LOC-'.uniqid(),
                'supplier_id' => $localSupp->id,
                'status' => 'confirmed',
                'items' => [
                    ['part_id' => $partLocal->id, 'qty' => 500, 'unit' => 'KGM', 'price' => 1000],
                ],
            ])->assertRedirect();
        }

        // 2. PO Supplier Impor
        $partImport = $importSupp->partSubstitutes()->where('is_active', true)->first()?->substitutePart;
        if ($partImport) {
            $this->post(route('purchase-orders.store'), [
                'po_no' => 'PO-IMP-'.uniqid(),
                'supplier_id' => $importSupp->id,
                'status' => 'confirmed',
                'items' => [
                    ['part_id' => $partImport->id, 'qty' => 1000, 'unit' => 'KGM', 'price' => 2000],
                ],
            ])->assertRedirect();
        }
    }

    public function test_arrival_local_stores_pen_aju_and_delivery_note_without_containers(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        $po = PurchaseOrder::create([
            'po_no' => 'PO-ARRV-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'confirmed',
        ]);

        $response = $this->post(route('incoming-arrivals.store'), [
            'is_local' => true,
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'delivery_note_no' => 'SJ-LOCAL-2026-001',
            'pen_no' => 'PEN-998877',
            'aju_no' => 'AJU-112233',
            'invoice_date' => now()->toDateString(),
            'items' => [
                [
                    'part_id' => $part->id,
                    'qty_goods' => 450,
                    'unit_goods' => 'KGM',
                    'weight_nett' => 450,
                ],
            ],
        ]);

        $response->assertRedirect();

        $arrival = IncomingArrival::where('delivery_note_no', 'SJ-LOCAL-2026-001')->firstOrFail();
        $this->assertTrue((bool) $arrival->is_local);
        $this->assertSame('PEN-998877', $arrival->pen_no);
        $this->assertSame('AJU-112233', $arrival->aju_no);
        $this->assertSame('pending', $arrival->iqc_status);
        $this->assertSame(0, $arrival->containers()->count());
    }

    public function test_receive_is_blocked_if_iqc_rejected(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-GATE-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'pending',
            'iqc_status' => 'rejected',
        ]);

        $item = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 100,
            'unit_goods' => 'KGM',
            'weight_nett' => 100,
        ]);

        // 1. Jika IQC Rejected: receive create diblokir 422
        $this->get(route('receive.create', $item->id))->assertStatus(422);

        // 2. Jika IQC Pass: receive create lolos 200
        $arrival->update(['iqc_status' => 'passed']);
        $this->get(route('receive.create', $item->id))->assertOk();
    }
}
