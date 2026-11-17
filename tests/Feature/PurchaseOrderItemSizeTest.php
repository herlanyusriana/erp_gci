<?php

namespace Tests\Feature;

use App\Models\PartSubstitute;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Size pada baris PO adalah snapshot master material, bukan isian bebas.
 *
 * Aturan ini sengaja diubah: sebelumnya size diketik manual di form PO sehingga
 * nilainya bisa menyimpang dari master part dan tidak bisa dicocokkan dengan
 * penerimaan/produksi. Sekarang master (atau size khusus supplier) yang menang,
 * dan isian manual hanya dipakai bila master belum punya size.
 */
class PurchaseOrderItemSizeTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_purchase_order_item_size_is_saved_and_rendered_on_detail(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = $supplier->partSubstitutes()->where('is_active', true)->firstOrFail()->substitutePart;

        // Pastikan size datang dari master, bukan dari isian form.
        PartSubstitute::query()
            ->where('supplier_id', $supplier->id)
            ->where('substitute_part_id', $part->id)
            ->update(['size' => null]);
        $part->update(['size' => 'MASTER-20X30']);

        $response = $this->post(route('purchase-orders.store'), [
            'po_no' => 'PO-SIZE-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [
                [
                    'part_id' => $part->id,
                    'size' => '20 x 30 x 1.2',
                    'qty' => 12,
                    'unit' => 'PCS',
                    'price' => 100,
                ],
            ],
        ]);

        $po = PurchaseOrder::where('po_no', 'like', 'PO-SIZE-%')->latest('id')->firstOrFail();
        $item = PurchaseOrderItem::where('purchase_order_id', $po->id)->firstOrFail();

        $this->assertSame('MASTER-20X30', $item->size, 'Size PO harus mengikuti master part');
        $response->assertRedirect(route('purchase-orders.show', $po));
        $this->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('MASTER-20X30');
    }

    public function test_purchase_order_update_persists_item_size(): void
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = $supplier->partSubstitutes()->where('is_active', true)->firstOrFail()->substitutePart;
        $po = PurchaseOrder::create([
            'po_no' => 'PO-SIZE-UPD-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'draft',
        ]);

        PartSubstitute::query()
            ->where('supplier_id', $supplier->id)
            ->where('substitute_part_id', $part->id)
            ->update(['size' => 'SIZE-SUPPLIER-M8']);
        $part->update(['size' => 'M8 x 25']);

        $this->put(route('purchase-orders.update', $po), [
            'po_no' => $po->po_no,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [
                [
                    'part_id' => $part->id,
                    'size' => 'DIKETIK-BEBAS',
                    'qty' => 24,
                    'unit' => 'PCS',
                ],
            ],
        ])->assertRedirect(route('purchase-orders.show', $po));

        // Size khusus supplier menang atas master part dan atas isian form.
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $po->id,
            'part_id' => $part->id,
            'size' => 'SIZE-SUPPLIER-M8',
        ]);
    }
}
