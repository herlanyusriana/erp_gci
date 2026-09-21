<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\Supplier;
use App\Models\User;
use App\Support\UomCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UomCatalogTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        UomCatalog::flush();
    }

    public function test_master_uoms_are_seeded_and_complete(): void
    {
        $codes = UomCatalog::codes();

        foreach (['KGM', 'PCS', 'ROLL', 'SHEET', 'COIL', 'SET', 'EA', 'UOM', 'PALLET'] as $expected) {
            $this->assertContains($expected, $codes, "Master UOM tidak memuat {$expected}.");
        }
    }

    public function test_existence_check_is_case_insensitive(): void
    {
        $this->assertTrue(UomCatalog::exists('pcs'));
        $this->assertTrue(UomCatalog::exists(' Kgm '));
        $this->assertFalse(UomCatalog::exists('NOT-A-UOM'));
        $this->assertFalse(UomCatalog::exists(null));
    }

    public function test_normalize_trims_and_uppercases(): void
    {
        $this->assertSame('KGM', UomCatalog::normalize(' kgm '));
        $this->assertNull(UomCatalog::normalize('   '));
        $this->assertNull(UomCatalog::normalize(null));
    }

    public function test_default_code_follows_config_master(): void
    {
        $this->assertSame('PCS', UomCatalog::defaultCode());
    }

    public function test_local_po_rejects_unit_outside_master(): void
    {
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());

        $supplier = Supplier::firstOrFail();
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        $response = $this->post(route('local-pos.store'), [
            'po_no' => 'LPO-UOM-TEST-1',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'items' => [[
                'part_id' => $part->id,
                'size' => 'TEST',
                'qty_goods' => 1,
                'unit_goods' => 'NOT-A-UOM',
                'weight_nett' => 1,
                'price' => 1,
            ]],
        ]);

        $response->assertSessionHasErrors('items.0.unit_goods');
    }

    public function test_local_po_accepts_unit_from_master(): void
    {
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());

        $supplier = Supplier::firstOrFail();
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        $response = $this->post(route('local-pos.store'), [
            'po_no' => 'LPO-UOM-TEST-2',
            'po_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'items' => [[
                'part_id' => $part->id,
                'size' => 'TEST',
                'qty_goods' => 1,
                'unit_goods' => 'coil',
                'weight_nett' => 1,
                'price' => 1,
            ]],
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('incoming_arrival_items', [
            'part_id' => $part->id,
            'unit_goods' => 'COIL',
        ]);
    }
}
