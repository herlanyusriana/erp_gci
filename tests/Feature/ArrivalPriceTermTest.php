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
 * `price_term` pada kedatangan impor: syarat harga (FOB, CIF, dan sejenisnya).
 *
 * Kolomnya sudah lama ada dan sudah tercetak di invoice, tetapi form Arrival
 * tidak pernah menampilkan input-nya sehingga operator tidak bisa mengisinya.
 */
class ArrivalPriceTermTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    public function test_import_arrival_stores_price_term(): void
    {
        $invoiceNo = 'INV-PRICETERM-'.uniqid();

        $this->post(route('incoming-arrivals.store'), [
            'is_local' => false,
            'supplier_id' => Supplier::where('is_active', true)->value('id'),
            'invoice_no' => $invoiceNo,
            'invoice_date' => now()->toDateString(),
            'price_term' => 'CIF',
            'items' => [[
                'part_id' => $this->nonFgPart()->id,
                'qty_goods' => 10,
                'unit_goods' => 'KGM',
            ]],
            'containers' => [],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('incoming_arrivals', [
            'invoice_no' => $invoiceNo,
            'price_term' => 'CIF',
        ]);
    }

    public function test_price_term_is_stored_in_uppercase(): void
    {
        $invoiceNo = 'INV-PRICETERM-CASE-'.uniqid();

        $this->post(route('incoming-arrivals.store'), [
            'is_local' => false,
            'supplier_id' => Supplier::where('is_active', true)->value('id'),
            'invoice_no' => $invoiceNo,
            'invoice_date' => now()->toDateString(),
            'price_term' => ' cif busan ',
            'items' => [[
                'part_id' => $this->nonFgPart()->id,
                'qty_goods' => 10,
                'unit_goods' => 'KGM',
            ]],
            'containers' => [],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('incoming_arrivals', [
            'invoice_no' => $invoiceNo,
            'price_term' => 'CIF BUSAN',
        ]);
    }

    public function test_price_term_is_optional(): void
    {
        $invoiceNo = 'INV-PRICETERM-EMPTY-'.uniqid();

        $this->post(route('incoming-arrivals.store'), [
            'is_local' => false,
            'supplier_id' => Supplier::where('is_active', true)->value('id'),
            'invoice_no' => $invoiceNo,
            'invoice_date' => now()->toDateString(),
            'items' => [[
                'part_id' => $this->nonFgPart()->id,
                'qty_goods' => 10,
                'unit_goods' => 'KGM',
            ]],
            'containers' => [],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('incoming_arrivals', [
            'invoice_no' => $invoiceNo,
            'price_term' => null,
        ]);
    }

    public function test_price_term_longer_than_the_column_is_rejected(): void
    {
        $this->post(route('incoming-arrivals.store'), [
            'is_local' => false,
            'supplier_id' => Supplier::where('is_active', true)->value('id'),
            'invoice_no' => 'INV-PRICETERM-LONG-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'price_term' => str_repeat('A', 51),
            'items' => [[
                'part_id' => $this->nonFgPart()->id,
                'qty_goods' => 10,
                'unit_goods' => 'KGM',
            ]],
            'containers' => [],
        ])->assertSessionHasErrors('price_term');
    }

    public function test_edit_form_exposes_price_term(): void
    {
        $arrival = $this->makeArrival('FOB');

        $this->get(route('incoming-arrivals.edit', $arrival))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Incoming/Arrival/Form')
                ->where('arrival.price_term', 'FOB'));
    }

    public function test_update_keeps_price_term_editable(): void
    {
        $arrival = $this->makeArrival('FOB');
        $item = $arrival->items()->firstOrFail();

        $this->put(route('incoming-arrivals.update', $arrival), [
            'supplier_id' => $arrival->supplier_id,
            'status' => 'pending',
            'price_term' => 'CNF',
            'items' => [[
                'id' => $item->id,
                'part_id' => $item->part_id,
                'qty_goods' => $item->qty_goods,
                'unit_goods' => $item->unit_goods,
            ]],
            'containers' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame('CNF', $arrival->fresh()->price_term);
    }

    private function makeArrival(string $priceTerm): IncomingArrival
    {
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-PRICETERM-'.uniqid(),
            'invoice_no' => 'INV-PRICETERM-'.uniqid(),
            'supplier_id' => Supplier::where('is_active', true)->value('id'),
            'is_local' => false,
            'status' => 'pending',
            'iqc_status' => 'pending',
            'price_term' => $priceTerm,
        ]);

        IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $this->nonFgPart()->id,
            'qty_goods' => 10,
            'unit_goods' => 'KGM',
            'weight_nett' => 10,
        ]);

        return $arrival;
    }

    private function nonFgPart(): Part
    {
        return Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();
    }
}
