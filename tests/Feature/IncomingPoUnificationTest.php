<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
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

    private function poPayload(Supplier $supplier, Part $part): array
    {
        return [
            'po_no' => 'PO-UNI-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [['part_id' => $part->id, 'qty' => 5]],
        ];
    }

    public function test_purchase_order_form_only_offers_import_suppliers(): void
    {
        $this->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/PurchaseOrder/Form')
                ->has('suppliers')
                ->where('suppliers', fn ($suppliers) => collect($suppliers)->every(fn ($s) => ($s['source'] ?? null) === 'import')));
    }

    public function test_local_po_form_only_offers_local_suppliers(): void
    {
        $this->get(route('local-pos.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/LocalPo/Form')
                ->has('suppliers')
                ->where('suppliers', fn ($suppliers) => collect($suppliers)->every(fn ($s) => ($s['source'] ?? null) === 'local')));
    }

    public function test_purchase_order_rejects_a_local_supplier(): void
    {
        $local = $this->supplier(Supplier::SOURCE_LOCAL);
        $part = $this->materialFor($local);

        $this->post(route('purchase-orders.store'), $this->poPayload($local, $part))
            ->assertSessionHasErrors('supplier_id');
    }

    public function test_local_po_rejects_an_import_supplier(): void
    {
        $import = $this->supplier(Supplier::SOURCE_IMPORT);

        $this->post(route('local-pos.store'), [
            'po_no' => 'LPO-UNI-'.uniqid(),
            'po_date' => now()->toDateString(),
            'supplier_id' => $import->id,
            'currency' => 'IDR',
            'items' => [[
                'part_id' => $this->materialFor($import)->id,
                'qty_goods' => 5,
                'unit_goods' => 'PCS',
                'weight_nett' => 5,
            ]],
        ])->assertSessionHasErrors('supplier_id');
    }

    public function test_purchase_order_accepts_an_import_supplier(): void
    {
        $import = $this->supplier(Supplier::SOURCE_IMPORT);
        $part = $this->materialFor($import);

        $this->post(route('purchase-orders.store'), $this->poPayload($import, $part))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('purchase_orders', ['supplier_id' => $import->id]);
    }

    public function test_incoming_entry_point_states_which_flow_needs_arrival(): void
    {
        $this->get(route('incoming-data'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Incoming/ModuleLauncher'));

        // Launcher dirender sebagai SPA, jadi tautan tidak ada di HTML awal.
        $source = file_get_contents(resource_path('js/Pages/Incoming/ModuleLauncher.vue'));

        $this->assertStringContainsString("href: '/purchase-orders'", $source);
        $this->assertStringContainsString("href: '/local-pos'", $source);
        $this->assertStringContainsString("href: '/incoming-arrivals'", $source);
    }

    public function test_update_cannot_move_a_purchase_order_to_a_local_supplier(): void
    {
        $import = $this->supplier(Supplier::SOURCE_IMPORT);
        $part = $this->materialFor($import);

        $this->post(route('purchase-orders.store'), $this->poPayload($import, $part))
            ->assertSessionHasNoErrors();

        $po = PurchaseOrder::where('supplier_id', $import->id)->latest('id')->firstOrFail();
        $local = $this->supplier(Supplier::SOURCE_LOCAL);

        $this->put(route('purchase-orders.update', $po), [
            'po_no' => $po->po_no,
            'supplier_id' => $local->id,
            'status' => 'draft',
            'items' => [['part_id' => $part->id, 'qty' => 5]],
        ])->assertSessionHasErrors('supplier_id');

        $this->assertSame($import->id, $po->fresh()->supplier_id, 'Supplier tidak boleh berpindah source.');
    }

    public function test_local_po_update_cannot_move_to_an_import_supplier(): void
    {
        $local = $this->supplier(Supplier::SOURCE_LOCAL);
        $part = $this->materialFor($local);

        $this->post(route('local-pos.store'), [
            'po_no' => 'LPO-LOCK-'.uniqid(),
            'po_date' => now()->toDateString(),
            'supplier_id' => $local->id,
            'currency' => 'IDR',
            'items' => [[
                'part_id' => $part->id,
                'qty_goods' => 5,
                'unit_goods' => 'PCS',
                'weight_nett' => 5,
            ]],
        ])->assertSessionHasNoErrors();

        $localPo = IncomingArrival::where('is_local', true)->latest('id')->firstOrFail();
        $import = $this->supplier(Supplier::SOURCE_IMPORT);

        $this->put(route('local-pos.update', $localPo), [
            'po_no' => $localPo->po_no,
            'po_date' => now()->toDateString(),
            'supplier_id' => $import->id,
            'currency' => 'IDR',
            'items' => [[
                'part_id' => $part->id,
                'qty_goods' => 5,
                'unit_goods' => 'PCS',
                'weight_nett' => 5,
            ]],
        ])->assertSessionHasErrors('supplier_id');

        $this->assertSame($local->id, $localPo->fresh()->supplier_id, 'Local PO tidak boleh pindah ke supplier import.');
    }
}
