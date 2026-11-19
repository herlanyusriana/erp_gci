<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Mata uang PO disimpan per dokumen.
 *
 * Sebelumnya halaman cetak menebaknya dari `suppliers.source` (import -> USD,
 * sisanya IDR), sehingga PO tidak bisa dicetak dalam mata uang lain dan
 * operator tidak melihat mata uang apa pun saat mengisi form. Nilai tebakan itu
 * kini hanya menjadi usulan awal; yang tercetak selalu nilai tersimpan.
 */
class PurchaseOrderCurrencyTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_currency_defaults_to_usd_for_an_import_supplier(): void
    {
        $supplier = $this->supplier('import');

        $po = $this->storePo($supplier, 'PO-CUR-IMPORT-'.uniqid());

        $this->assertSame('USD', $po->currency);
    }

    public function test_currency_defaults_to_idr_for_a_local_supplier(): void
    {
        $supplier = $this->supplier('local');

        $po = $this->storePo($supplier, 'PO-CUR-LOCAL-'.uniqid());

        $this->assertSame('IDR', $po->currency);
    }

    public function test_operator_currency_overrides_the_supplier_suggestion(): void
    {
        $supplier = $this->supplier('import');

        $po = $this->storePo($supplier, 'PO-CUR-EUR-'.uniqid(), 'eur');

        $this->assertSame('EUR', $po->currency, 'Mata uang operator disimpan dalam huruf besar');
    }

    public function test_print_uses_the_stored_currency_not_the_supplier_source(): void
    {
        $supplier = $this->supplier('import');
        $po = $this->storePo($supplier, 'PO-CUR-PRINT-'.uniqid(), 'EUR');

        $this->get(route('purchase-orders.print', $po))
            ->assertOk()
            ->assertSee('EUR 1,000.00')
            ->assertDontSee('USD');
    }

    public function test_edit_can_change_the_currency(): void
    {
        $supplier = $this->supplier('import');
        $po = $this->storePo($supplier, 'PO-CUR-EDIT-'.uniqid(), 'USD');
        $item = $po->items()->firstOrFail();

        $this->put(route('purchase-orders.update', $po), [
            'po_no' => $po->po_no,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'currency' => 'JPY',
            'items' => [
                [
                    'id' => $item->id,
                    'part_id' => $item->part_id,
                    'qty' => $item->qty,
                    'unit' => $item->unit,
                    'price' => 100,
                ],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('JPY', $po->fresh()->currency);
    }

    private function supplier(string $source): Supplier
    {
        return Supplier::query()
            ->where('is_active', true)
            ->where('source', $source)
            ->whereHas('partSubstitutes', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();
    }

    private function storePo(Supplier $supplier, string $poNo, ?string $currency = null): PurchaseOrder
    {
        $part = $supplier->partSubstitutes()->where('is_active', true)->firstOrFail()->substitutePart;

        $payload = [
            'po_no' => $poNo,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [
                [
                    'part_id' => $part->id,
                    'qty' => 10,
                    'unit' => 'PCS',
                    'price' => 100,
                ],
            ],
        ];

        if ($currency !== null) {
            $payload['currency'] = $currency;
        }

        $this->post(route('purchase-orders.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return PurchaseOrder::where('po_no', $poNo)->firstOrFail();
    }
}
