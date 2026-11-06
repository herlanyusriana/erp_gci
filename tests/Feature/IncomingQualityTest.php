<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
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
            'iqc_status' => 'pending',
            'status' => 'pending',
        ]);

        $response = $this->get(route('incoming-quality.index'))->assertOk();
        $response->assertSee('ARV-QC-TEST-01');
        $response->assertSee('PO-QC-TEST-01');
    }

    public function test_pass_button_marks_arrival_as_iqc_pass(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-QC-PASS-01',
            'supplier_id' => $supplier->id,
            'iqc_status' => 'pending',
            'status' => 'pending',
        ]);

        $this->post(route('incoming-quality.pass', $arrival->id))->assertRedirect();

        $fresh = $arrival->fresh();
        $this->assertSame('passed', $fresh->iqc_status);
        $this->assertNotNull($fresh->iqc_passed_at);
        $this->assertSame($this->admin->id, $fresh->iqc_passed_by);
    }

    public function test_revert_button_reverts_iqc_pass_to_pending(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-QC-REVERT-01',
            'supplier_id' => $supplier->id,
            'iqc_status' => 'passed',
            'iqc_passed_at' => now(),
            'iqc_passed_by' => $this->admin->id,
            'status' => 'pending',
        ]);

        $this->post(route('incoming-quality.revert', $arrival->id))->assertRedirect();

        $fresh = $arrival->fresh();
        $this->assertSame('pending', $fresh->iqc_status);
        $this->assertNull($fresh->iqc_passed_at);
        $this->assertNull($fresh->iqc_passed_by);
    }
}
