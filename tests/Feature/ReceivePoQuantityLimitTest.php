<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\Part;
use App\Models\PartSubstitute;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReceivePoQuantityLimitTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Supplier $supplier;

    private Part $part;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);

        $this->supplier = Supplier::where('is_active', true)
            ->whereHas('partSubstitutes', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();
        $this->part = $this->supplier->partSubstitutes()
            ->where('is_active', true)
            ->firstOrFail()
            ->substitutePart;
    }

    private function makePo(float $qty = 100, string $unit = 'PCS'): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_no' => 'PO-LIMIT-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'status' => 'confirmed',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'part_id' => $this->part->id,
            'qty' => $qty,
            'unit' => $unit,
        ]);

        return $po;
    }

    private function makeArrival(PurchaseOrder $po, float $qty, string $unit = 'PCS'): IncomingArrivalItem
    {
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-LIMIT-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'is_local' => false,
            'status' => 'pending',
            'iqc_status' => 'passed',
        ]);

        return IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $this->part->id,
            'qty_goods' => $qty,
            'unit_goods' => $unit,
            'weight_nett' => null,
        ]);
    }

    public function test_receive_cannot_exceed_purchase_order_quantity(): void
    {
        $po = $this->makePo(100);
        $item = $this->makeArrival($po, 500);

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'tags' => [[
                'tag' => 'T-PO-LIMIT-1',
                'qty' => 500,
                'qty_unit' => 'PCS',
            ]],
        ])->assertSessionHasErrors('tags');

        $this->assertDatabaseMissing('incoming_receives', ['tag' => 'T-PO-LIMIT-1']);
    }

    public function test_receive_up_to_purchase_order_quantity_is_allowed_and_the_next_one_is_blocked(): void
    {
        $po = $this->makePo(100);
        $item = $this->makeArrival($po, 100);

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'tags' => [[
                'tag' => 'T-PO-LIMIT-2',
                'qty' => 100,
                'qty_unit' => 'PCS',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('incoming_receives', ['tag' => 'T-PO-LIMIT-2']);

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'tags' => [[
                'tag' => 'T-PO-LIMIT-3',
                'qty' => 1,
                'qty_unit' => 'PCS',
            ]],
        ])->assertSessionHasErrors('tags');

        $this->assertDatabaseMissing('incoming_receives', ['tag' => 'T-PO-LIMIT-3']);
    }

    public function test_second_arrival_cannot_exceed_remaining_purchase_order_quantity(): void
    {
        $po = $this->makePo(100);
        $this->makeArrival($po, 60);

        $this->post(route('incoming-arrivals.store'), [
            'is_local' => true,
            'purchase_order_id' => $po->id,
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'items' => [[
                'part_id' => $this->part->id,
                'qty_goods' => 60,
                'unit_goods' => 'PCS',
            ]],
        ])->assertSessionHasErrors('items.0.qty_goods');

        $this->assertSame(1, IncomingArrival::where('purchase_order_id', $po->id)->count());
    }

    public function test_receives_across_two_arrivals_cannot_exceed_purchase_order_quantity(): void
    {
        $po = $this->makePo(100);
        $first = $this->makeArrival($po, 60);
        $second = $this->makeArrival($po, 40);

        $this->post(route('receive.store', $first), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'tags' => [['tag' => 'T-PO-SPLIT-1', 'qty' => 60, 'qty_unit' => 'PCS']],
        ])->assertSessionHasNoErrors();

        // 41 melebihi sisa PO (40) walau masih di bawah qty Arrival kedua.
        $this->post(route('receive.store', $second), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'tags' => [['tag' => 'T-PO-SPLIT-2', 'qty' => 41, 'qty_unit' => 'PCS']],
        ])->assertSessionHasErrors('tags');

        $this->assertDatabaseMissing('incoming_receives', ['tag' => 'T-PO-SPLIT-2']);
    }

    public function test_updating_an_arrival_does_not_count_its_own_items_against_the_po(): void
    {
        $po = $this->makePo(100);
        $item = $this->makeArrival($po, 100);
        $arrival = $item->arrival;

        $this->put(route('incoming-arrivals.update', $arrival), [
            'is_local' => true,
            'purchase_order_id' => $po->id,
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'status' => 'pending',
            'items' => [[
                'id' => $item->id,
                'part_id' => $this->part->id,
                'qty_goods' => 100,
                'unit_goods' => 'PCS',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(100.0, (float) $item->fresh()->qty_goods, 0.001);
    }

    public function test_arrival_cannot_exceed_purchase_order_quantity(): void
    {
        $po = $this->makePo(100);

        $this->post(route('incoming-arrivals.store'), [
            'is_local' => true,
            'purchase_order_id' => $po->id,
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'items' => [[
                'part_id' => $this->part->id,
                'qty_goods' => 500,
                'unit_goods' => 'PCS',
            ]],
        ])->assertSessionHasErrors('items.0.qty_goods');
    }

    public function test_local_po_supports_repeated_partial_arrivals_until_exhausted_and_hides_exhausted_po(): void
    {
        $localSupplier = Supplier::create([
            'supplier_code' => 'SUP-LOC-PARTIAL-'.uniqid(),
            'supplier_name' => 'PT Supplier Lokal Parsial',
            'source' => Supplier::SOURCE_LOCAL,
            'is_active' => true,
        ]);

        $parts = Part::query()
            ->where('is_active', true)
            ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))
            ->orderBy('id')
            ->limit(2)
            ->get();
        $this->assertCount(2, $parts);
        [$partA, $partB] = [$parts[0], $parts[1]];

        PartSubstitute::create([
            'part_id' => $partA->id,
            'substitute_part_id' => $partA->id,
            'supplier_id' => $localSupplier->id,
            'material_group' => 'GRP-A',
            'size' => 'STD',
            'is_active' => true,
        ]);
        PartSubstitute::create([
            'part_id' => $partB->id,
            'substitute_part_id' => $partB->id,
            'supplier_id' => $localSupplier->id,
            'material_group' => 'GRP-B',
            'size' => 'STD',
            'is_active' => true,
        ]);

        $po = PurchaseOrder::create([
            'po_no' => 'PO-LOC-MULTI-'.uniqid(),
            'supplier_id' => $localSupplier->id,
            'status' => 'confirmed',
            'currency' => 'IDR',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'part_id' => $partA->id,
            'qty' => 100,
            'unit' => 'SHEET',
            'price' => 5000,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'part_id' => $partB->id,
            'qty' => 50,
            'unit' => 'PCS',
            'price' => 2000,
        ]);

        // Awal: PO muncul dengan sisa penuh.
        $this->get(route('incoming-arrivals.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Arrival/Form')
                ->where('purchaseOrders', function ($orders) use ($po, $partA, $partB) {
                    $row = collect($orders)->firstWhere('id', $po->id);
                    if (! $row) {
                        return false;
                    }
                    $items = collect($row['items']);
                    $a = $items->firstWhere('part_id', $partA->id);
                    $b = $items->firstWhere('part_id', $partB->id);

                    return (float) $a['remaining_qty'] === 100.0 && (float) $b['remaining_qty'] === 50.0;
                }));

        // Arrival #1 (parsial Part A = 40 SHEET dengan weight_nett = 120 KGM).
        $this->post(route('incoming-arrivals.store'), [
            'is_local' => true,
            'arrival_type' => 'local',
            'purchase_order_id' => $po->id,
            'supplier_id' => $localSupplier->id,
            'delivery_note_no' => 'SJ-PARTIAL-1',
            'invoice_date' => now()->toDateString(),
            'items' => [[
                'part_id' => $partA->id,
                'qty_goods' => 40,
                'unit_goods' => 'SHEET',
                'weight_nett' => 120,
                'unit_weight' => 'KGM',
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        // Receive atas Arrival #1 tetap lolos meski PO memakai satuan SHEET dan weight_nett = 120 KGM.
        $arrival1 = IncomingArrival::where('delivery_note_no', 'SJ-PARTIAL-1')->firstOrFail();
        $arrival1->update(['iqc_status' => 'passed']);
        $arrival1Item = $arrival1->items()->firstOrFail();
        $this->post(route('receive.store', $arrival1Item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'tags' => [[
                'tag' => 'TAG-SHEET-PO-1-'.uniqid(),
                'qty' => 40,
                'qty_unit' => 'SHEET',
                'net_weight' => 120,
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        // Setelah Arrival #1, Part A tersisa 60 SHEET (bukan 100 - 120 KGM).
        $this->get(route('incoming-arrivals.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrders', function ($orders) use ($po, $partA, $partB) {
                    $row = collect($orders)->firstWhere('id', $po->id);
                    if (! $row) {
                        return false;
                    }
                    $items = collect($row['items']);
                    $a = $items->firstWhere('part_id', $partA->id);
                    $b = $items->firstWhere('part_id', $partB->id);

                    return (float) $a['arrived_qty'] === 40.0
                        && (float) $a['remaining_qty'] === 60.0
                        && (float) $b['remaining_qty'] === 50.0;
                }));

        // Arrival #2: habiskan Part A (60 SHEET) + parsial Part B (20 PCS).
        $this->post(route('incoming-arrivals.store'), [
            'is_local' => true,
            'arrival_type' => 'local',
            'purchase_order_id' => $po->id,
            'supplier_id' => $localSupplier->id,
            'delivery_note_no' => 'SJ-PARTIAL-2',
            'invoice_date' => now()->toDateString(),
            'items' => [
                [
                    'part_id' => $partA->id,
                    'qty_goods' => 60,
                    'unit_goods' => 'SHEET',
                ],
                [
                    'part_id' => $partB->id,
                    'qty_goods' => 20,
                    'unit_goods' => 'PCS',
                ],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->get(route('incoming-arrivals.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrders', function ($orders) use ($po, $partA, $partB) {
                    $row = collect($orders)->firstWhere('id', $po->id);
                    if (! $row) {
                        return false;
                    }
                    $items = collect($row['items']);
                    $a = $items->firstWhere('part_id', $partA->id);
                    $b = $items->firstWhere('part_id', $partB->id);

                    return (float) $a['remaining_qty'] === 0.0
                        && (float) $b['remaining_qty'] === 30.0;
                }));

        // Over-limit: Part A sudah habis (1 > 0).
        $this->post(route('incoming-arrivals.store'), [
            'is_local' => true,
            'arrival_type' => 'local',
            'purchase_order_id' => $po->id,
            'supplier_id' => $localSupplier->id,
            'delivery_note_no' => 'SJ-OVER-A',
            'invoice_date' => now()->toDateString(),
            'items' => [[
                'part_id' => $partA->id,
                'qty_goods' => 1,
                'unit_goods' => 'SHEET',
            ]],
        ])->assertSessionHasErrors('items.0.qty_goods');

        // Duplicate-row bypass guard: Part B dua baris (20 + 15 = 35 > 30).
        $this->post(route('incoming-arrivals.store'), [
            'is_local' => true,
            'arrival_type' => 'local',
            'purchase_order_id' => $po->id,
            'supplier_id' => $localSupplier->id,
            'delivery_note_no' => 'SJ-OVER-DUP-B',
            'invoice_date' => now()->toDateString(),
            'items' => [
                [
                    'part_id' => $partB->id,
                    'qty_goods' => 20,
                    'unit_goods' => 'PCS',
                ],
                [
                    'part_id' => $partB->id,
                    'qty_goods' => 15,
                    'unit_goods' => 'PCS',
                ],
            ],
        ])->assertSessionHasErrors('items.1.qty_goods');

        // Arrival #3: habiskan sisa Part B (30 PCS).
        $this->post(route('incoming-arrivals.store'), [
            'is_local' => true,
            'arrival_type' => 'local',
            'purchase_order_id' => $po->id,
            'supplier_id' => $localSupplier->id,
            'delivery_note_no' => 'SJ-PARTIAL-3',
            'invoice_date' => now()->toDateString(),
            'items' => [[
                'part_id' => $partB->id,
                'qty_goods' => 30,
                'unit_goods' => 'PCS',
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        // Setelah PO habis total: PO tidak lagi tampil di dropdown, tapi supplier tercatat punya PO untuk warning UI.
        $this->get(route('incoming-arrivals.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrders', fn ($orders) => collect($orders)->firstWhere('id', $po->id) === null)
                ->where('suppliersWithPos', fn ($ids) => collect($ids)->contains($localSupplier->id)));

        // Halaman Detail PO menampilkan progress Tiba / Sisa dan riwayat 3 arrival.
        $this->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/PurchaseOrder/Show')
                ->where('purchaseOrder.items', function ($items) use ($partA, $partB) {
                    $col = collect($items);
                    $a = $col->firstWhere('part_id', $partA->id);
                    $b = $col->firstWhere('part_id', $partB->id);

                    return (float) $a['arrived_qty'] === 100.0
                        && (float) $a['remaining_qty'] === 0.0
                        && (float) $b['arrived_qty'] === 50.0
                        && (float) $b['remaining_qty'] === 0.0;
                })
                ->has('arrivals', 3));
    }
}
