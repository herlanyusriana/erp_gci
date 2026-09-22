<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartPrice;
use App\Models\PartSubstitute;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchaseOrderSupplierPartTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    public function test_create_form_exposes_supplier_parts_and_active_prices(): void
    {
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $main = Part::where('part_number', 'CBKG07256C')->firstOrFail();
        $substitute = Part::where('part_number', '4000W4A003A')->firstOrFail();

        PartSubstitute::create([
            'part_id' => $main->id,
            'substitute_part_id' => $substitute->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
        ]);

        // Harga lama (harus diabaikan) + harga aktif (valid_from <= hari ini).
        PartPrice::create([
            'supplier_id' => $supplier->id,
            'part_id' => $substitute->id,
            'price' => 9000,
            'currency' => 'IDR',
            'valid_from' => now()->subMonth()->toDateString(),
            'is_active' => true,
        ]);
        PartPrice::create([
            'supplier_id' => $supplier->id,
            'part_id' => $substitute->id,
            'price' => 12500,
            'currency' => 'USD',
            'valid_from' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);
        PartPrice::create([
            'supplier_id' => $supplier->id,
            'part_id' => $substitute->id,
            'price' => 99999,
            'currency' => 'IDR',
            'valid_from' => now()->addMonth()->toDateString(), // belum berlaku
            'is_active' => true,
        ]);

        $this->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/PurchaseOrder/Form')
                ->where('supplierParts', fn ($list) => collect($list)->contains(
                    fn ($row) => $row['supplier_id'] === $supplier->id && $row['part_id'] === $substitute->id,
                ))
                ->where('activePrices', function ($list) use ($supplier, $substitute) {
                    $row = collect($list)->first(
                        fn ($r) => $r['supplier_id'] === $supplier->id && $r['part_id'] === $substitute->id,
                    );

                    // Harga terbaru yang sudah berlaku (12500 USD), bukan yang lama/belum berlaku.
                    return $row !== null && (float) $row['price'] === 12500.0 && $row['currency'] === 'USD';
                }));
    }

    public function test_store_rejects_part_not_mapped_to_supplier(): void
    {
        $supplier = Supplier::create([
            'supplier_code' => 'SUP-PO-GUARD',
            'supplier_name' => 'PO Guard Supplier',
            'is_active' => true,
        ]);
        $part = Part::where('part_number', 'CBKG07256C')->firstOrFail();

        $this->post(route('purchase-orders.store'), [
            'po_no' => 'PO-GUARD-001',
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [[
                'part_id' => $part->id,
                'qty' => 1,
                'unit' => 'PCS',
            ]],
        ])->assertSessionHasErrors('items.0.part_id');

        $this->assertDatabaseMissing('purchase_orders', ['po_no' => 'PO-GUARD-001']);
    }
}
