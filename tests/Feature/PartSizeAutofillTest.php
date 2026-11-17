<?php

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Part;
use App\Models\PartSubstitute;
use App\Models\Process;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Size pada dokumen (PO, Local PO, BOM) harus berasal dari master part, bukan
 * diketik bebas — kalau tidak, data menyimpang dari master dan laporan
 * produksi/penerimaan tidak bisa dicocokkan.
 */
class PartSizeAutofillTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    /** Part material dengan size nyata di master. */
    private function materialWithSize(): Part
    {
        return Part::query()
            ->whereHas('partType', fn ($q) => $q->where('code', 'MATERIAL'))
            ->whereNotNull('size')
            ->whereNotIn('size', ['', '-'])
            ->firstOrFail();
    }

    /**
     * Pasangan supplier + part yang sah untuk PO: part harus terdaftar sebagai
     * substitute aktif supplier tersebut (aturan PO), dan punya size di master.
     *
     * @return array{0: Supplier, 1: Part}
     */
    private function supplierPartWithSize(): array
    {
        $row = PartSubstitute::query()
            ->where('is_active', true)
            ->whereNotNull('supplier_id')
            ->whereHas('substitutePart', fn ($q) => $q
                ->whereNotNull('size')
                ->whereNotIn('size', ['', '-']))
            ->with('substitutePart')
            ->firstOrFail();

        return [Supplier::findOrFail($row->supplier_id), $row->substitutePart];
    }

    public function test_supplier_parts_payload_carries_size_and_model(): void
    {
        $payload = PartSubstitute::supplierParts();
        $this->assertNotEmpty($payload);

        $withSize = collect($payload)->firstWhere('size', '!=', null);
        $this->assertNotNull($withSize, 'supplierParts() harus mengirim size supaya form bisa autofill');
        $this->assertArrayHasKey('model', $withSize);
    }

    public function test_resolve_size_prefers_supplier_relation_then_part_master(): void
    {
        $part = $this->materialWithSize();
        $supplier = Supplier::where('is_active', true)->firstOrFail();

        PartSubstitute::query()
            ->where('supplier_id', $supplier->id)
            ->where('substitute_part_id', $part->id)
            ->delete();

        $relation = PartSubstitute::create([
            'supplier_id' => $supplier->id,
            'part_id' => $part->id,
            'substitute_part_id' => $part->id,
            'size' => 'SIZE-KHUSUS-SUPPLIER',
            'is_active' => true,
        ]);

        // Kolom relasi menang.
        $this->assertSame('SIZE-KHUSUS-SUPPLIER', PartSubstitute::resolveSize($supplier->id, $part->id));

        // Relasi kosong → master part.
        $relation->update(['size' => null]);
        $this->assertSame($part->size, PartSubstitute::resolveSize($supplier->id, $part->id));

        // Placeholder `-` juga dianggap kosong.
        $relation->update(['size' => '-']);
        $this->assertSame($part->size, PartSubstitute::resolveSize($supplier->id, $part->id));
    }

    public function test_po_stores_the_master_size_even_when_a_different_value_is_posted(): void
    {
        [$supplier, $part] = $this->supplierPartWithSize();

        PartSubstitute::query()
            ->where('supplier_id', $supplier->id)
            ->where('substitute_part_id', $part->id)
            ->update(['size' => null]);

        $poNo = 'PO-SIZE-'.uniqid();

        $this->post(route('purchase-orders.store'), [
            'po_no' => $poNo,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [[
                'uid' => 1,
                'part_id' => $part->id,
                'size' => 'KETIKAN-BEBAS',
                'qty' => 5,
            ]],
        ])->assertRedirect();

        $item = PurchaseOrder::where('po_no', $poNo)->firstOrFail()->items()->firstOrFail();

        $this->assertSame($part->size, $item->size, 'Size PO harus mengikuti master, bukan ketikan bebas');
    }

    public function test_po_falls_back_to_the_posted_size_when_master_has_none(): void
    {
        $row = PartSubstitute::query()
            ->where('is_active', true)
            ->whereNotNull('supplier_id')
            ->whereHas('substitutePart', fn ($q) => $q
                ->where(fn ($w) => $w->whereNull('size')->orWhereIn('size', ['', '-'])))
            ->with('substitutePart')
            ->firstOrFail();

        $supplier = Supplier::findOrFail($row->supplier_id);
        $part = $row->substitutePart;

        PartSubstitute::query()
            ->where('supplier_id', $supplier->id)
            ->where('substitute_part_id', $part->id)
            ->update(['size' => null]);

        $poNo = 'PO-SIZE-FB-'.uniqid();

        $this->post(route('purchase-orders.store'), [
            'po_no' => $poNo,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [[
                'uid' => 1,
                'part_id' => $part->id,
                'size' => 'manual-saja',
                'qty' => 1,
            ]],
        ])->assertRedirect();

        $item = PurchaseOrder::where('po_no', $poNo)->firstOrFail()->items()->firstOrFail();

        $this->assertSame('manual-saja', $item->size, 'Tanpa size master, isian manual dipertahankan');
    }

    public function test_bom_item_size_follows_the_child_part_master(): void
    {
        $child = $this->materialWithSize();
        $fg = Part::whereHas('partType', fn ($q) => $q->where('code', 'FG'))->firstOrFail();
        $process = Process::query()->firstOrFail();

        // Satu BOM per FG, jadi pakai BOM baru bila belum ada.
        $bom = Bom::withTrashed()->where('part_id', $fg->id)->first();

        if ($bom !== null) {
            BomItem::where('bom_id', $bom->id)->delete();
            $bom->forceDelete();
        }

        $this->post(route('boms.store'), [
            'part_id' => $fg->id,
            'items' => [[
                'sequence' => 1,
                'process_id' => $process->id,
                'child_part_id' => $child->id,
                'child_part_name' => 'diabaikan',
                'size' => 'SALAH-KETIK',
                'child_qty' => 2,
                'uom_rm' => 'KGM',
            ]],
        ])->assertRedirect();

        $bom = Bom::where('part_id', $fg->id)->firstOrFail();
        $item = $bom->items()->firstOrFail();

        $this->assertSame($child->size, $item->size, 'Size BOM harus mengikuti master material');
    }
}
