<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\Part;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `remarks` pada kedatangan impor: keterangan bebas (mis. informasi rekening
 * bank) yang tidak masuk kolom terstruktur.
 */
class ArrivalRemarksTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    public function test_import_arrival_stores_free_text_remarks(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();
        $invoiceNo = 'INV-REMARKS-'.uniqid();

        $this->post(route('incoming-arrivals.store'), [
            'is_local' => false,
            'supplier_id' => $supplier->id,
            'invoice_no' => $invoiceNo,
            'invoice_date' => now()->toDateString(),
            'remarks' => 'Bank BCA 1234567890 a/n PT Geum Cheon',
            'items' => [[
                'part_id' => $part->id,
                'qty_goods' => 10,
                'unit_goods' => 'KGM',
            ]],
            'containers' => [],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('incoming_arrivals', [
            'invoice_no' => $invoiceNo,
            'remarks' => 'Bank BCA 1234567890 a/n PT Geum Cheon',
        ]);
    }

    public function test_edit_form_exposes_remarks(): void
    {
        $arrival = $this->makeArrival('Catatan bank: BCA 999');

        $this->get(route('incoming-arrivals.edit', $arrival))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Incoming/Arrival/Form')
                ->where('arrival.remarks', 'Catatan bank: BCA 999'));
    }

    public function test_update_keeps_remarks_editable(): void
    {
        $arrival = $this->makeArrival('lama');
        $item = $arrival->items()->firstOrFail();

        $this->put(route('incoming-arrivals.update', $arrival), [
            'supplier_id' => $arrival->supplier_id,
            'status' => 'pending',
            'remarks' => 'rekening baru: BNI 111',
            'items' => [[
                'id' => $item->id,
                'part_id' => $item->part_id,
                'qty_goods' => $item->qty_goods,
                'unit_goods' => $item->unit_goods,
            ]],
            'containers' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame('rekening baru: BNI 111', $arrival->fresh()->remarks);
    }

    private function makeArrival(string $remarks): IncomingArrival
    {
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-REMARKS-'.uniqid(),
            'invoice_no' => 'INV-REMARKS-'.uniqid(),
            'supplier_id' => Supplier::where('is_active', true)->value('id'),
            'is_local' => false,
            'status' => 'pending',
            'iqc_status' => 'pending',
            'remarks' => $remarks,
        ]);

        IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 10,
            'unit_goods' => 'KGM',
            'weight_nett' => 10,
        ]);

        return $arrival;
    }
}
