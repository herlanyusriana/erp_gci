<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartSubstitute;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IncomingPoUnificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    private function supplier(string $source): Supplier
    {
        $existing = Supplier::where('source', $source)->orderBy('id')->first();
        if ($existing) {
            return $existing;
        }

        return Supplier::create([
            'supplier_code' => 'SUP-UNI-'.strtoupper($source).'-'.uniqid(),
            'supplier_name' => 'PT Uni '.ucfirst($source),
            'source' => $source,
            'is_active' => true,
        ]);
    }

    private function materialFor(Supplier $supplier): Part
    {
        $mapping = PartSubstitute::query()
            ->where('supplier_id', $supplier->id)
            ->where('is_active', true)
            ->first();

        if ($mapping) {
            return Part::findOrFail($mapping->substitute_part_id);
        }

        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        PartSubstitute::firstOrCreate([
            'part_id' => Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->value('id'),
            'substitute_part_id' => $part->id,
            'supplier_id' => $supplier->id,
        ], ['is_active' => true]);

        return $part;
    }

    private function freshSupplierWithPart(string $source): array
    {
        $supplier = Supplier::create([
            'supplier_code' => 'SUP-UNI-'.strtoupper($source).'-'.uniqid(),
            'supplier_name' => ($source === Supplier::SOURCE_LOCAL ? 'PT ' : '').'Uni '.ucfirst($source).' '.uniqid(),
            'source' => $source,
            'is_active' => true,
        ]);

        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        PartSubstitute::firstOrCreate([
            'part_id' => Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->value('id'),
            'substitute_part_id' => $part->id,
            'supplier_id' => $supplier->id,
        ], ['is_active' => true]);

        return [$supplier, $part];
    }

    private function poPayload(Supplier $supplier, Part $part): array
    {
        return [
            'po_no' => 'PO-UNI-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [['part_id' => $part->id, 'qty' => 5]],
        ];
    }

    public function test_purchase_order_form_offers_both_local_and_import_suppliers(): void
    {
        $local = $this->supplier(Supplier::SOURCE_LOCAL);
        $import = $this->supplier(Supplier::SOURCE_IMPORT);

        $this->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/PurchaseOrder/Form')
                ->has('suppliers')
                ->where('suppliers', fn ($suppliers) => collect($suppliers)->pluck('id')->contains($local->id)
                    && collect($suppliers)->pluck('id')->contains($import->id)));
    }

    public function test_purchase_order_accepts_both_local_and_import_suppliers(): void
    {
        $local = $this->supplier(Supplier::SOURCE_LOCAL);
        $partLocal = $this->materialFor($local);

        $this->post(route('purchase-orders.store'), $this->poPayload($local, $partLocal))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('purchase_orders', ['supplier_id' => $local->id]);

        $import = $this->supplier(Supplier::SOURCE_IMPORT);
        $partImport = $this->materialFor($import);

        $this->post(route('purchase-orders.store'), $this->poPayload($import, $partImport))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('purchase_orders', ['supplier_id' => $import->id]);
    }

    public function test_incoming_entry_point_displays_unified_purchase_order_tile(): void
    {
        $this->get(route('incoming-data'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Incoming/ModuleLauncher'));

        $source = file_get_contents(resource_path('js/Pages/Incoming/ModuleLauncher.vue'));

        $this->assertStringContainsString("href: '/purchase-orders'", $source);
        $this->assertStringContainsString("href: '/incoming-arrivals'", $source);
        $this->assertStringNotContainsString("href: '/local-pos'", $source);
    }

    public function test_purchase_order_keeps_its_supplier_on_update(): void
    {
        [$local, $part] = $this->freshSupplierWithPart(Supplier::SOURCE_LOCAL);

        $this->post(route('purchase-orders.store'), $this->poPayload($local, $part))
            ->assertSessionHasNoErrors();

        $po = PurchaseOrder::where('supplier_id', $local->id)->latest('id')->firstOrFail();

        [$other] = $this->freshSupplierWithPart(Supplier::SOURCE_LOCAL);

        $this->put(route('purchase-orders.update', $po), $this->poPayload($other, $part))
            ->assertSessionHasErrors('supplier_id');

        $this->assertSame($local->id, $po->fresh()->supplier_id, 'Supplier tidak boleh diubah setelah PO disimpan.');
    }

    public function test_arrival_form_offers_both_local_and_import_suppliers(): void
    {
        $local = $this->supplier(Supplier::SOURCE_LOCAL);
        $import = $this->supplier(Supplier::SOURCE_IMPORT);

        $this->get(route('incoming-arrivals.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Arrival/Form')
                ->where('suppliers', fn ($suppliers) => collect($suppliers)->pluck('id')->contains($local->id)
                    && collect($suppliers)->pluck('id')->contains($import->id)));
    }

    public function test_arrival_accepts_local_supplier_with_pen_aju(): void
    {
        [$local, $part] = $this->freshSupplierWithPart(Supplier::SOURCE_LOCAL);

        $this->post(route('incoming-arrivals.store'), [
            'is_local' => true,
            'delivery_note_no' => 'SJ-'.uniqid(),
            'supplier_id' => $local->id,
            'pen_no' => 'PEN-1122',
            'aju_no' => 'AJU-3344',
            'items' => [[
                'part_id' => $part->id,
                'qty_goods' => 5,
                'unit_goods' => 'PCS',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('incoming_arrivals', [
            'supplier_id' => $local->id,
            'pen_no' => 'PEN-1122',
            'aju_no' => 'AJU-3344',
        ]);
    }
}
