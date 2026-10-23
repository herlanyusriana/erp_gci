<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SupplierSourceTest extends TestCase
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

    private function makeSupplier(string $name, array $overrides = []): Supplier
    {
        return Supplier::create($overrides + [
            'supplier_code' => 'SUP-SRC-'.uniqid(),
            'supplier_name' => $name,
            'is_active' => true,
        ]);
    }

    public function test_supplier_table_has_source_column_with_allowed_values(): void
    {
        $this->assertTrue(\Schema::hasColumn('suppliers', 'source'));

        $local = $this->makeSupplier('CV Lokal', ['source' => Supplier::SOURCE_LOCAL]);
        $import = $this->makeSupplier('ACME CORP', ['source' => Supplier::SOURCE_IMPORT]);

        $this->assertSame('local', $local->fresh()->source);
        $this->assertSame('import', $import->fresh()->source);
    }

    public function test_store_requires_a_valid_source(): void
    {
        $base = [
            'supplier_code' => 'SUP-SRC-REQ',
            'supplier_name' => 'PT Wajib Sumber',
            'is_active' => true,
        ];

        $this->actingAs($this->admin())
            ->post(route('suppliers.store'), $base)
            ->assertSessionHasErrors('source');

        $this->actingAs($this->admin())
            ->post(route('suppliers.store'), $base + ['source' => 'asing'])
            ->assertSessionHasErrors('source');

        $this->assertDatabaseMissing('suppliers', ['supplier_code' => 'SUP-SRC-REQ']);

        $this->actingAs($this->admin())
            ->post(route('suppliers.store'), $base + ['source' => 'local'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('suppliers', ['supplier_code' => 'SUP-SRC-REQ', 'source' => 'local']);
    }

    public function test_update_saves_source(): void
    {
        $supplier = $this->makeSupplier('Ganti Sumber');

        $this->actingAs($this->admin())
            ->put(route('suppliers.update', $supplier), [
                'supplier_code' => $supplier->supplier_code,
                'supplier_name' => $supplier->supplier_name,
                'source' => 'import',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('import', $supplier->fresh()->source);
    }

    public function test_backfill_marks_pt_names_as_local(): void
    {
        $withDot = $this->makeSupplier('PT. SINAR JAYA');
        $withSpace = $this->makeSupplier('PT CAHAYA ABADI');

        Supplier::backfillSource();

        $this->assertSame('local', $withDot->fresh()->source);
        $this->assertSame('local', $withSpace->fresh()->source);
    }

    public function test_backfill_uses_history_when_it_shows_a_single_source(): void
    {
        $importOnly = $this->makeSupplier('ACME GLOBAL TRADING');
        $localOnly = $this->makeSupplier('CV MAJU BERSAMA');

        PurchaseOrder::create([
            'po_no' => 'PO-SRC-'.uniqid(),
            'supplier_id' => $importOnly->id,
            'status' => 'draft',
        ]);

        IncomingArrival::create([
            'arrival_no' => 'ARV-SRC-'.uniqid(),
            'supplier_id' => $localOnly->id,
            'is_local' => true,
            'status' => 'draft',
        ]);

        Supplier::backfillSource();

        $this->assertSame('import', $importOnly->fresh()->source);
        $this->assertSame('local', $localOnly->fresh()->source);
    }

    public function test_backfill_leaves_mixed_or_empty_history_blank(): void
    {
        $mixed = $this->makeSupplier('MIXED TRADING CO');
        $empty = $this->makeSupplier('NO HISTORY LTD');

        PurchaseOrder::create([
            'po_no' => 'PO-MIX-'.uniqid(),
            'supplier_id' => $mixed->id,
            'status' => 'draft',
        ]);
        IncomingArrival::create([
            'arrival_no' => 'ARV-MIX-'.uniqid(),
            'supplier_id' => $mixed->id,
            'is_local' => true,
            'status' => 'draft',
        ]);

        $stats = Supplier::backfillSource();

        $this->assertNull($mixed->fresh()->source, 'Riwayat campuran tidak boleh ditebak.');
        $this->assertNull($empty->fresh()->source, 'Supplier tanpa riwayat tidak boleh dikarang.');
        $this->assertGreaterThanOrEqual(2, $stats['skipped']);
    }

    public function test_backfill_does_not_overwrite_an_existing_source(): void
    {
        $supplier = $this->makeSupplier('PT. SUDAH DIISI', ['source' => Supplier::SOURCE_IMPORT]);

        Supplier::backfillSource();

        $this->assertSame(
            'import',
            $supplier->fresh()->source,
            'Backfill hanya mengisi supplier yang belum punya kategori.',
        );
    }

    public function test_backfill_is_idempotent(): void
    {
        $supplier = $this->makeSupplier('PT. IDEMPOTEN');

        Supplier::backfillSource();
        $second = Supplier::backfillSource();

        $this->assertSame('local', $supplier->fresh()->source);
        $this->assertSame(0, $second['local'], 'Jalan kedua tidak boleh mengubah apa pun.');
    }

    public function test_index_exposes_source_for_each_supplier(): void
    {
        $supplier = $this->makeSupplier('PT. TAMPIL DI DAFTAR', ['source' => Supplier::SOURCE_LOCAL]);

        $this->actingAs($this->admin())
            ->get(route('suppliers.index', ['search' => $supplier->supplier_code]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Supplier/Index')
                ->where('suppliers.data.0.id', $supplier->id)
                ->where('suppliers.data.0.source', 'local'));
    }
}
