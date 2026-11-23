<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\Location;
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
        $part = Part::where('part_number', 'CBKG07256C')->firstOrFail();

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
                'source' => 'local',
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
                'source' => 'local',
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

    public function test_stock_index_exposes_invoice_supplier_and_rack_location(): void
    {
        [$stock] = $this->makeStockSource('INV-TEST-0001', 'TAG-TEST-0001');
        $location = Location::firstOrCreate(['code' => 'Rack-2'], ['name' => 'Rack 2']);
        $stock->receive->update(['location_code' => $location->code]);

        $this->actingAs($this->admin())
            ->get(route('stocks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Stock/Index')
                ->where('stocks.data.0.id', $stock->id)
                ->where('stocks.data.0.receive.invoice_no', 'INV-TEST-0001')
                ->where('stocks.data.0.receive.location_code', 'Rack-2')
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

    public function test_stock_page_separates_material_from_wip(): void
    {
        $this->actingAs($this->admin());

        $material = Part::whereRaw('UPPER(part_number) NOT LIKE ?', ['%-WIP%'])
            ->whereHas('partType', fn ($q) => $q->whereRaw("UPPER(code) NOT IN ('WIP', 'FG')"))
            ->firstOrFail();
        $wip = Part::whereRaw('UPPER(part_number) LIKE ?', ['%-WIP%'])->firstOrFail();
        $fg = Part::whereHas('partType', fn ($q) => $q->whereRaw('UPPER(code) = ?', ['FG']))->firstOrFail();

        PartStock::create([
            'part_id' => $material->id, 'tag' => 'MAT-TAB-1',
            'qty' => 10, 'qty_unit' => 'PCS', 'received_at' => now(),
        ]);
        PartStock::create([
            'part_id' => $wip->id, 'tag' => 'WO-TAB#WIP#R1',
            'qty' => 352, 'qty_unit' => 'PCS', 'received_at' => now(),
        ]);
        PartStock::create([
            'part_id' => $fg->id, 'tag' => 'WO-TAB#FG#R1',
            'qty' => 50, 'qty_unit' => 'PCS', 'location_code' => 'FG-A1', 'received_at' => now(),
        ]);

        // Tab material: WIP & FG tidak boleh ikut.
        $this->get(route('stocks.index', ['tab' => 'material']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'material')
                ->where('stocks.data', fn ($rows) => collect($rows)
                    ->every(fn ($r) => ! in_array(strtoupper((string) ($r['part']['part_type']['code'] ?? '')), ['WIP', 'FG'], true))));

        // Tab WIP: hanya WIP.
        $this->get(route('stocks.index', ['tab' => 'wip']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'wip')
                ->where('stocks.data', fn ($rows) => collect($rows)
                    ->every(fn ($r) => strtoupper((string) ($r['part']['part_type']['code'] ?? '')) === 'WIP')));

        // Tab FG: hanya FG.
        $this->get(route('stocks.index', ['tab' => 'fg']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'fg')
                ->where('stocks.data', fn ($rows) => collect($rows)->isNotEmpty()
                    && collect($rows)->every(fn ($r) => strtoupper((string) ($r['part']['part_type']['code'] ?? '')) === 'FG')));
    }

    /** Label material dicetak ulang dari halaman Stock, jadi receive.id wajib ikut terkirim. */
    public function test_stock_rows_expose_receive_id_for_label_reprint(): void
    {
        [$stock] = $this->makeStockSource('INV-LABEL-0001', 'TAG-LABEL-0001');

        $this->actingAs($this->admin())
            ->get(route('stocks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Stock/Index')
                ->where('stocks.data', fn ($rows) => collect($rows)->contains(
                    fn ($r) => (int) $r['id'] === (int) $stock->id
                        && (int) ($r['receive']['id'] ?? 0) === (int) $stock->receive_id
                )));
    }

    /**
     * Faktur di halaman Stock mengikuti invoice arrival (dokumen induk), bukan
     * snapshot receive. Invoice sering baru diisi setelah barang diterima, dan
     * label mencetak invoice arrival — keduanya harus sama.
     */
    public function test_stock_invoice_follows_arrival_when_receive_snapshot_is_stale(): void
    {
        [$stock] = $this->makeStockSource('INV-LAMA-0001', 'TAG-INVOICE-0001');

        $arrival = IncomingArrival::where('arrival_no', 'ARV-TEST-TAG-INVOICE-0001')->firstOrFail();
        $arrival->update(['invoice_no' => 'INV-BARU-9999']);

        $this->assertSame('INV-LAMA-0001', $stock->fresh()->receive->invoice_no);

        $this->actingAs($this->admin())
            ->get(route('stocks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Stock/Index')
                ->where('stocks.data', fn ($rows) => collect($rows)->contains(
                    fn ($r) => (int) $r['id'] === (int) $stock->id
                        && ($r['receive']['arrival_item']['arrival']['invoice_no'] ?? null) === 'INV-BARU-9999'
                )));
    }

    /** Pencarian nomor part tidak boleh melepas filter tab material/WIP. */
    public function test_stock_search_does_not_leak_wip_rows_into_material_tab(): void
    {
        $this->actingAs($this->admin());

        $material = Part::whereRaw('UPPER(part_number) NOT LIKE ?', ['%-WIP%'])
            ->whereHas('partType', fn ($q) => $q->whereRaw('UPPER(code) <> ?', ['WIP']))
            ->firstOrFail();
        $wip = Part::whereRaw('UPPER(part_number) LIKE ?', ['%-WIP%'])->firstOrFail();

        PartStock::create([
            'part_id' => $material->id, 'tag' => 'MAT-SEARCH-1',
            'qty' => 10, 'qty_unit' => 'PCS', 'received_at' => now(),
        ]);
        PartStock::create([
            'part_id' => $wip->id, 'tag' => 'WO-SEARCH#WIP#R1',
            'qty' => 352, 'qty_unit' => 'PCS', 'received_at' => now(),
        ]);

        $this->get(route('stocks.index', ['tab' => 'material', 'search' => $wip->part_number]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'material')
                ->where('stocks.data', fn ($rows) => collect($rows)
                    ->every(fn ($r) => strtoupper((string) ($r['part']['part_type']['code'] ?? '')) !== 'WIP')));
    }

    public function test_receive_label_requires_view_permission(): void
    {
        [$stock] = $this->makeStockSource('INV-LABEL-0002', 'TAG-LABEL-0002');

        $this->actingAs(User::factory()->create())
            ->get(route('receive.label', $stock->receive_id))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('receive.label', $stock->receive_id))
            ->assertOk();
    }

    public function test_stock_index_exposes_bundle_packings_and_searches_packing_no(): void
    {
        [$stock] = $this->makeStockSource('INV-PACK-0001', 'TAG-PACK-0001');

        $receive = $stock->receive;
        $receive->update([
            'packing_count' => 2,
            'packing_type' => 'COIL',
            'bundle_unit' => 'PALLET',
        ]);

        $receive->packings()->create([
            'packing_no' => 'PK-COIL-002',
            'sequence' => 2,
            'qty' => 60,
            'uom' => 'KGM',
            'net_weight' => 60,
        ]);
        $receive->packings()->create([
            'packing_no' => 'PK-COIL-001',
            'sequence' => 1,
            'qty' => 40,
            'uom' => 'KGM',
            'net_weight' => 40,
        ]);

        $this->actingAs($this->admin())
            ->get(route('stocks.index', ['search' => 'PK-COIL-002']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Stock/Index')
                ->where('stocks.data', function ($rows) use ($stock) {
                    $row = collect($rows)->firstWhere('id', $stock->id);
                    if ($row === null) {
                        return false;
                    }
                    $packings = $row['receive']['packings'] ?? [];

                    return ($row['receive']['packing_type'] ?? null) === 'COIL'
                        && count($packings) === 2
                        && $packings[0]['packing_no'] === 'PK-COIL-001'
                        && $packings[1]['packing_no'] === 'PK-COIL-002';
                }));
    }

    public function test_stock_index_searches_by_invoice_number(): void
    {
        [$stock] = $this->makeStockSource('INV-GROUP-7788', 'TAG-GROUP-7788');
        $this->makeStockSource('INV-OTHER-0001', 'TAG-OTHER-0001');

        $this->actingAs($this->admin())
            ->get(route('stocks.index', ['search' => 'INV-GROUP-7788']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Stock/Index')
                ->where('stocks.data', fn ($rows) => count($rows) === 1
                    && (int) $rows[0]['id'] === (int) $stock->id));
    }
}
