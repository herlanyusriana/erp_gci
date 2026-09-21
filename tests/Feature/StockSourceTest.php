<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockSourceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@geumcheon.local')->firstOrFail();
    }

    /**
     * Bangun rantai stok -> receive -> arrival item -> arrival -> supplier.
     *
     * @return array{0: PartStock, 1: Supplier}
     */
    private function makeStockSource(string $invoiceNo, string $tag): array
    {
        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $supplier = Supplier::create([
            'supplier_code' => 'SUP-TEST-'.$tag,
            'supplier_name' => 'PT Sumber Test',
            'address' => 'Jl. Industri Raya No. 7',
            'phone' => '021-5551234',
            'email' => 'sales@sumber-test.co.id',
            'contact_person' => 'Budi Santoso',
            'bank_account' => 'BCA 1234567890',
            'is_active' => true,
        ]);

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-TEST-'.$tag,
            'supplier_id' => $supplier->id,
            'is_local' => false,
            'status' => 'draft',
        ]);

        $item = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 100,
            'unit_goods' => 'PCS',
        ]);

        $receive = IncomingReceive::create([
            'arrival_item_id' => $item->id,
            'part_id' => $part->id,
            'tag' => $tag,
            'qty' => 100,
            'qty_unit' => 'PCS',
            'invoice_no' => $invoiceNo,
        ]);

        $stock = PartStock::create([
            'part_id' => $part->id,
            'tag' => $tag,
            'qty' => 100,
            'qty_unit' => 'PCS',
            'receive_id' => $receive->id,
            'received_at' => now(),
        ]);

        return [$stock, $supplier];
    }

    public function test_supplier_stores_contact_details(): void
    {
        $this->actingAs($this->admin())
            ->post(route('suppliers.store'), [
                'supplier_code' => 'SUP-CT-001',
                'supplier_name' => 'PT Kontak Lengkap',
                'address' => 'Jl. Merdeka 10',
                'phone' => '021-7000111',
                'email' => 'info@kontak-lengkap.co.id',
                'contact_person' => 'Siti Aminah',
                'bank_account' => 'Mandiri 987654321',
                'is_active' => true,
            ])
            ->assertRedirect(route('suppliers.index'));

        $this->assertDatabaseHas('suppliers', [
            'supplier_code' => 'SUP-CT-001',
            'address' => 'Jl. Merdeka 10',
            'phone' => '021-7000111',
            'email' => 'info@kontak-lengkap.co.id',
            'contact_person' => 'Siti Aminah',
            'bank_account' => 'Mandiri 987654321',
        ]);
    }

    public function test_supplier_rejects_invalid_email(): void
    {
        $this->actingAs($this->admin())
            ->post(route('suppliers.store'), [
                'supplier_code' => 'SUP-CT-002',
                'supplier_name' => 'PT Email Salah',
                'email' => 'bukan-email',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_supplier_update_saves_contact_details(): void
    {
        $supplier = Supplier::create([
            'supplier_code' => 'SUP-CT-003',
            'supplier_name' => 'PT Update Kontak',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->put(route('suppliers.update', $supplier), [
                'supplier_code' => 'SUP-CT-003',
                'supplier_name' => 'PT Update Kontak',
                'address' => 'Jl. Baru 22',
                'phone' => '022-888999',
                'email' => 'baru@update.co.id',
                'contact_person' => 'Andi Wijaya',
                'bank_account' => 'BNI 555000111',
                'is_active' => true,
            ])
            ->assertRedirect(route('suppliers.index'));

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'address' => 'Jl. Baru 22',
            'phone' => '022-888999',
            'email' => 'baru@update.co.id',
            'contact_person' => 'Andi Wijaya',
            'bank_account' => 'BNI 555000111',
        ]);
    }

    public function test_stock_index_exposes_invoice_and_supplier(): void
    {
        [$stock] = $this->makeStockSource('INV-TEST-0001', 'TAG-TEST-0001');

        $this->actingAs($this->admin())
            ->get(route('stocks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Stock/Index')
                ->where('stocks.data.0.id', $stock->id)
                ->where('stocks.data.0.receive.invoice_no', 'INV-TEST-0001')
                ->where('stocks.data.0.receive.arrival_item.arrival.supplier.supplier_name', 'PT Sumber Test'));
    }

    public function test_arrival_invoice_prints_supplier_address(): void
    {
        [, $supplier] = $this->makeStockSource('INV-TEST-0002', 'TAG-TEST-0002');

        $arrival = IncomingArrival::where('supplier_id', $supplier->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('incoming-arrivals.invoice', $arrival))
            ->assertOk()
            ->assertSee(strtoupper('Jl. Industri Raya No. 7'))
            ->assertSee('021-5551234');
    }
}
