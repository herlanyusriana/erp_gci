<?php

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\BomItem;
use App\Models\IncomingArrival;
use App\Models\Part;
use App\Models\PartSubstitute;
use App\Models\PartType;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartTypeGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->first());
    }

    private function makePart(string $typeCode, string $prefix = 'TEST'): Part
    {
        $type = PartType::firstOrCreate(['code' => $typeCode], ['name' => $typeCode]);

        return Part::create([
            'part_number' => $prefix.'-'.uniqid(),
            'part_name' => 'Test '.$type,
            'part_type_id' => $type->id,
            'is_active' => true,
        ]);
    }

    private function assertValidationError(string $uri, array $payload, string $key): void
    {
        $response = $this->from('/')->post($uri, $payload);
        $response->assertRedirect('/');
        $response->assertSessionHasErrors([$key]);
    }

    public function test_purchase_order_rejects_fg_part(): void
    {
        $fg = $this->makePart('FG');

        // Part material yang memang dipasok supplier (dari seeder).
        $mapping = PartSubstitute::query()
            ->where('is_active', true)
            ->whereNotNull('supplier_id')
            ->firstOrFail();

        $supplier = Supplier::findOrFail($mapping->supplier_id);
        $material = Part::findOrFail($mapping->substitute_part_id);

        $this->post(route('purchase-orders.store'), [
            'po_no' => 'PO-GUARD-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [['part_id' => $fg->id, 'qty' => 1]],
        ])->assertSessionHasErrors(['items.0.part_id']);
        $this->assertSame(0, PurchaseOrder::count());

        $this->post(route('purchase-orders.store'), [
            'po_no' => 'PO-GUARD-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [['part_id' => $material->id, 'qty' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_local_po_rejects_fg_part(): void
    {
        $fg = $this->makePart('FG');

        // Part material yang memang dipasok supplier (dari seeder) — aturan strict
        // part-per-supplier memakai `part_substitutes`.
        $mapping = PartSubstitute::query()
            ->where('is_active', true)
            ->whereNotNull('supplier_id')
            ->firstOrFail();

        $supplier = Supplier::findOrFail($mapping->supplier_id);
        $material = Part::findOrFail($mapping->substitute_part_id);

        $payload = fn ($partId) => [
            'po_no' => 'LPO-GUARD-'.uniqid(),
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'currency' => 'IDR',
            'items' => [['part_id' => $partId, 'qty_goods' => 5, 'unit_goods' => 'PCS', 'weight_nett' => 5]],
        ];

        $this->post(route('local-pos.store'), $payload($fg->id))->assertSessionHasErrors(['items.0.part_id']);
        $this->post(route('local-pos.store'), $payload($material->id))->assertSessionHasNoErrors();
        $this->assertSame(1, IncomingArrival::where('is_local', true)->count());
    }

    public function test_substitute_rejects_fg_part(): void
    {
        $fg = $this->makePart('FG');
        $material = $this->makePart('MATERIAL');
        $wip = $this->makePart('WIP');

        $this->post(route('substitutes.store'), [
            'part_id' => $fg->id,
            'substitute_part_id' => $material->id,
            'is_active' => true,
        ])->assertSessionHasErrors(['part_id']);

        $this->post(route('substitutes.store'), [
            'part_id' => $material->id,
            'substitute_part_id' => $wip->id,
            'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, PartSubstitute::where('part_id', $material->id)->where('substitute_part_id', $wip->id)->count());
    }

    public function test_work_order_requires_fg_part(): void
    {
        $fg = $this->makePart('FG');
        $material = $this->makePart('MATERIAL');

        $this->post(route('work-orders.store'), [
            'part_id' => $material->id,
            'qty' => 1,
        ])->assertSessionHasErrors(['part_id']);
        $this->assertSame(0, WorkOrder::count());

        $bom = Bom::create(['part_id' => $fg->id, 'bom_no' => 1, 'is_active' => true]);
        BomItem::create(['bom_id' => $bom->id, 'sequence' => 10, 'child_part_id' => $material->id, 'child_qty' => 1, 'uom_rm' => 'PCS', 'is_active' => true]);

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 2,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, WorkOrder::where('part_id', $fg->id)->count());
    }
}
