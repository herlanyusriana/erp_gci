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

    public function test_arrival_stores_port_of_loading(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();
        $invoiceNo = 'INV-POL-'.uniqid();

        $this->post(route('incoming-arrivals.store'), [
            'is_local' => false,
            'supplier_id' => $supplier->id,
            'invoice_no' => $invoiceNo,
            'invoice_date' => now()->toDateString(),
            'port_of_loading' => 'BUSAN',
            'items' => [
                [
                    'part_id' => $part->id,
                    'qty_goods' => 10,
                    'unit_goods' => 'KGM',
                ],
            ],
            'containers' => [],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('incoming_arrivals', [
            'invoice_no' => $invoiceNo,
            'port_of_loading' => 'BUSAN',
        ]);
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

    public function test_arrival_stores_and_updates_bundle_packaging_and_gross_weight(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        $invNo = 'INV-BUNDLE-'.uniqid();
        $response = $this->post(route('incoming-arrivals.store'), [
            'is_local' => false,
            'supplier_id' => $supplier->id,
            'invoice_no' => $invNo,
            'invoice_date' => now()->toDateString(),
            'items' => [
                [
                    'part_id' => $part->id,
                    'qty_goods' => 500,
                    'unit_goods' => 'KGM',
                    'qty_bundle' => 2,
                    'unit_bundle' => 'PALLET',
                    'weight_nett' => 500,
                    'weight_gross' => 520,
                    'price' => 1500,
                    'total_price' => 750000,
                ],
            ],
            'containers' => [
                ['container_no' => 'TGHU1234567', 'seal_code' => 'SEAL-01', 'size' => '40 HC'],
            ],
        ]);

        $response->assertRedirect();

        $arrival = IncomingArrival::where('invoice_no', $invNo)->firstOrFail();
        $item = $arrival->items()->firstOrFail();

        $this->assertEquals(2, $item->qty_bundle);
        $this->assertSame('PALLET', $item->unit_bundle);
        $this->assertEquals(500, $item->weight_nett);
        $this->assertEquals(520, $item->weight_gross);

        // Update test
        $this->put(route('incoming-arrivals.update', $arrival), [
            'is_local' => false,
            'supplier_id' => $supplier->id,
            'status' => 'pending',
            'invoice_no' => $arrival->invoice_no,
            'invoice_date' => now()->toDateString(),
            'items' => [
                [
                    'id' => $item->id,
                    'part_id' => $part->id,
                    'qty_goods' => 600,
                    'unit_goods' => 'KGM',
                    'qty_bundle' => 3,
                    'unit_bundle' => 'BOX',
                    'weight_nett' => 600,
                    'weight_gross' => 625,
                ],
            ],
            'containers' => [
                ['container_no' => 'TGHU1234567', 'seal_code' => 'SEAL-01', 'size' => '40 HC'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $item->refresh();
        $this->assertEquals(3, $item->qty_bundle);
        $this->assertSame('BOX', $item->unit_bundle);
        $this->assertEquals(600, $item->qty_goods);
        $this->assertEquals(600, $item->weight_nett);
        $this->assertEquals(625, $item->weight_gross);
    }
}
