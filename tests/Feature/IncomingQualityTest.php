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

class IncomingQualityTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_incoming_quality_page_loads_and_lists_arrivals(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $po = PurchaseOrder::create([
            'po_no' => 'PO-QC-TEST-01',
            'supplier_id' => $supplier->id,
            'status' => 'confirmed',
        ]);

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-QC-TEST-01',
            'po_no' => $po->po_no,
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'status' => 'pending',
        ]);

        $response = $this->get(route('incoming-quality.index'))->assertOk();
        $response->assertSee('ARV-QC-TEST-01');
        $response->assertSee('PO-QC-TEST-01');
    }

    public function test_pass_item_marks_single_material_as_iqc_pass(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-QC-ITEM-01',
            'supplier_id' => $supplier->id,
            'status' => 'pending',
        ]);

        $item1 = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 50,
            'unit_goods' => 'PCS',
            'iqc_status' => 'pending',
        ]);

        $item2 = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 30,
            'unit_goods' => 'PCS',
            'iqc_status' => 'pending',
        ]);

        // Loloskan item 1 saja
        $this->post(route('incoming-quality.items.pass', $item1->id))->assertRedirect();

        $this->assertSame('passed', $item1->fresh()->iqc_status);
        $this->assertNotNull($item1->fresh()->iqc_passed_at);
        $this->assertSame($this->admin->id, $item1->fresh()->iqc_passed_by);

        // Item 2 harus tetap pending
        $this->assertSame('pending', $item2->fresh()->iqc_status);
    }

    public function test_pass_all_marks_all_materials_in_arrival_as_iqc_pass(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-QC-ALL-01',
            'supplier_id' => $supplier->id,
            'status' => 'pending',
        ]);

        $item1 = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 10,
            'unit_goods' => 'PCS',
            'iqc_status' => 'pending',
        ]);

        $item2 = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 20,
            'unit_goods' => 'PCS',
            'iqc_status' => 'pending',
        ]);

        $this->post(route('incoming-quality.pass-all', $arrival->id))->assertRedirect();

        $this->assertSame('passed', $item1->fresh()->iqc_status);
        $this->assertSame('passed', $item2->fresh()->iqc_status);
        $this->assertSame('passed', $arrival->fresh()->iqc_status);
    }

    public function test_detail_page_loads_and_displays_individual_materials(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-QC-DETAIL-01',
            'supplier_id' => $supplier->id,
            'status' => 'pending',
        ]);

        IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 100,
            'unit_goods' => 'PCS',
            'iqc_status' => 'pending',
        ]);

        $response = $this->get(route('incoming-quality.show', $arrival->id))->assertOk();
        $response->assertSee($arrival->arrival_no);
        $response->assertSee($part->part_number);
    }
}
