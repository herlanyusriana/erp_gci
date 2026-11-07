<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseOrderPrintTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
    }

    public function test_guest_cannot_access_purchase_order_print(): void
    {
        $po = PurchaseOrder::create([
            'po_no' => 'PO-PRN-GUEST-'.uniqid(),
            'supplier_id' => Supplier::query()->value('id'),
            'status' => 'confirmed',
        ]);

        $this->get(route('purchase-orders.print', $po))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_print_page_with_exact_layout(): void
    {
        $this->actingAs($this->admin);

        $supplier = Supplier::where('is_active', true)
            ->whereHas('partSubstitutes', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();
        $part = $supplier->partSubstitutes()->where('is_active', true)->firstOrFail()->substitutePart;

        $po = PurchaseOrder::create([
            'po_no' => 'PO/1026/00323',
            'supplier_id' => $supplier->id,
            'status' => 'confirmed',
            'po_date' => '2026-10-15',
            'expected_date' => '2026-10-19',
            'created_by' => $this->admin->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'part_id' => $part->id,
            'size' => '2.0 X 1219 X 2438',
            'qty' => 10,
            'unit' => 'SHEET',
            'price' => 780000,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'part_id' => $part->id,
            'size' => '2.3 X 1219 X 2438',
            'qty' => 10,
            'unit' => 'SHEET',
            'price' => 910000,
        ]);

        $response = $this->get(route('purchase-orders.print', $po))->assertOk();

        // Verifikasi struktur template dan layout
        $response->assertSee('Purchase Order Confirmation PO/1026/00323');
        $response->assertSee('images/logo-big.jpg');
        $response->assertSee('PT Geum Cheon Indo');
        $response->assertSee('All of the Metal Manufacture');
        $response->assertSee('Shipping address:');
        $response->assertSee($supplier->supplier_name);
        $response->assertSee('Our Order Reference:');
        $response->assertSee('15 Oct 2026');
        $response->assertSee($this->admin->name);
        $response->assertSee('Date Req.');
        $response->assertSee('19 Oct 2026');
        $response->assertSee('2.0 X 1219 X 2438');
        $response->assertSee('2.3 X 1219 X 2438');
        $response->assertSee('10.00 SHEET');
        $response->assertSee('780,000.00');
        $response->assertSee('7,800,000.00');
        $response->assertSee('9,100,000.00');
        $response->assertSee('20.00'); // total qty
        $response->assertSee('Total Without Taxes');
        $response->assertSee('16,900,000.00'); // total amount
        $response->assertSee('window.print()');
    }
}
