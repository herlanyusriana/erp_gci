<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryScan;
use App\Models\Location;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PartType;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\Trolley;
use App\Models\Uom;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\DeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PickingListTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Customer $customerA;

    private Customer $customerB;

    private Part $fg;

    private Location $origin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);

        $this->customerA = Customer::create([
            'customer_code' => 'PICK-A-'.uniqid(),
            'customer_name' => 'Customer A',
            'is_active' => true,
        ]);
        $this->customerB = Customer::create([
            'customer_code' => 'PICK-B-'.uniqid(),
            'customer_name' => 'Customer B',
            'is_active' => true,
        ]);

        $this->fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        Trolley::query()->delete();
        Trolley::create(['code' => 'TRL-1', 'type' => 'internal', 'kind' => 'compbase']);

        $this->origin = Location::firstOrCreate(
            ['code' => 'TPL_DONGSHIN'],
            ['name' => 'TPL DONGSHIN', 'type' => Location::TYPE_MACHINE, 'is_active' => true],
        );
        $this->origin->update(['type' => Location::TYPE_MACHINE, 'is_active' => true]);

        Location::firstOrCreate(
            ['code' => 'DELIVERY-PREP'],
            ['name' => 'Delivery Preparation Area', 'type' => Location::TYPE_DELIVERY_PREP, 'is_active' => true],
        )->update(['type' => Location::TYPE_DELIVERY_PREP, 'is_active' => true]);
    }

    private function fgStock(string $tag, float $qty, ?Part $part = null): PartStock
    {
        return PartStock::create([
            'part_id' => ($part ?? $this->fg)->id,
            'tag' => $tag,
            'qty' => $qty,
            'qty_unit' => 'PCS',
            'location_code' => $this->origin->code,
            'received_at' => now(),
        ]);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function createPicking(array $rows): PickingList
    {
        $this->post(route('picking-lists.store'), [
            'picking_date' => now()->toDateString(),
            'items' => $rows,
        ])->assertRedirect();

        return PickingList::latest('id')->firstOrFail();
    }

    private function row(Customer $customer, float $qty, int $sequence = 1, ?Part $part = null): array
    {
        return [
            'sequence' => $sequence,
            'customer_id' => $customer->id,
            'part_id' => ($part ?? $this->fg)->id,
            'qty' => $qty,
            'qty_unit' => 'PCS',
        ];
    }

    public function test_picking_marks_rows_ok_and_short_without_production(): void
    {
        $this->fgStock('TAG-PICK-A-'.uniqid(), 10);

        $list = $this->createPicking([
            $this->row($this->customerA, 6),
            $this->row($this->customerB, 10),
        ]);

        $items = $list->items()->orderBy('id')->get();

        $this->assertSame(PickingListItem::STOCK_OK, $items[0]->stock_status);
        $this->assertEqualsWithDelta(10.0, (float) $items[0]->stock_available, 1e-6);
        $this->assertEqualsWithDelta(0.0, (float) $items[0]->stock_shortage, 1e-6);

        // Baris kedua hanya kebagian sisa 4 (alokasi dalam satu picking list).
        $this->assertSame(PickingListItem::STOCK_SHORT_NO_PRODUCTION, $items[1]->stock_status);
        $this->assertEqualsWithDelta(4.0, (float) $items[1]->stock_available, 1e-6);
        $this->assertEqualsWithDelta(6.0, (float) $items[1]->stock_shortage, 1e-6);
    }

    public function test_picking_marks_shortage_as_in_production_when_work_order_is_active(): void
    {
        $this->fgStock('TAG-PICK-WO-'.uniqid(), 2);

        WorkOrder::create([
            'wo_no' => 'WO-PICK-'.uniqid(),
            'part_id' => $this->fg->id,
            'qty' => 100,
            'status' => 'in_progress',
        ]);

        $list = $this->createPicking([$this->row($this->customerA, 10)]);

        $item = $list->items()->firstOrFail();
        $this->assertSame(PickingListItem::STOCK_SHORT_IN_PRODUCTION, $item->stock_status);
        $this->assertEqualsWithDelta(8.0, (float) $item->stock_shortage, 1e-6);
    }

    public function test_picking_marks_part_without_stock_rows_as_no_data(): void
    {
        $part = Part::create([
            'part_number' => 'FG-NOSTOCK-'.uniqid(),
            'part_name' => 'FG Tanpa Stok',
            'part_type_id' => PartType::where('code', 'FG')->value('id'),
            'uom_id' => Uom::where('code', 'PCS')->value('id'),
            'is_active' => true,
        ]);

        $list = $this->createPicking([$this->row($this->customerA, 5, 1, $part)]);

        $this->assertSame(PickingListItem::STOCK_NO_DATA, $list->items()->firstOrFail()->stock_status);
    }

    public function test_picking_rejects_non_finished_good_parts(): void
    {
        $material = Part::where('part_number', 'CBKG07256C')->firstOrFail();

        $this->post(route('picking-lists.store'), [
            'picking_date' => now()->toDateString(),
            'items' => [$this->row($this->customerA, 5, 1, $material)],
        ])->assertSessionHasErrors('items.0.part_id');

        $this->assertSame(0, PickingList::count());
    }

    public function test_generate_deliveries_creates_one_plan_delivery_per_customer(): void
    {
        $stock = $this->fgStock('TAG-PICK-GEN-'.uniqid(), 100);

        $list = $this->createPicking([
            $this->row($this->customerA, 6, 1),
            $this->row($this->customerB, 4, 2),
        ]);

        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();

        $deliveries = Delivery::where('picking_list_id', $list->id)->orderBy('customer_id')->get();

        $this->assertCount(2, $deliveries);
        $this->assertSame(PickingList::STATUS_PICKED, $list->fresh()->status);

        foreach ($deliveries as $delivery) {
            $this->assertSame(Delivery::STATUS_DRAFT, $delivery->status);
            $item = $delivery->items()->firstOrFail();
            // Baris rencana: tanpa tag dan tanpa stok spesifik.
            $this->assertNull($item->part_stock_id);
            $this->assertNull($item->tag);
            $this->assertSame($this->fg->id, (int) $item->part_id);
        }

        $this->assertEqualsWithDelta(100.0, (float) $stock->fresh()->qty, 1e-6, 'Generate belum memotong stok');
        $this->assertSame($this->origin->code, $stock->fresh()->location_code);
    }

    public function test_generate_deliveries_is_idempotent(): void
    {
        $this->fgStock('TAG-PICK-IDEM-'.uniqid(), 100);
        $list = $this->createPicking([$this->row($this->customerA, 6)]);

        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();
        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();

        $this->assertSame(1, Delivery::where('picking_list_id', $list->id)->count());
    }

    public function test_picking_cannot_be_edited_after_deliveries_exist(): void
    {
        $this->fgStock('TAG-PICK-EDIT-'.uniqid(), 100);
        $list = $this->createPicking([$this->row($this->customerA, 6)]);
        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();

        $this->put(route('picking-lists.update', $list), [
            'picking_date' => now()->toDateString(),
            'items' => [$this->row($this->customerA, 8)],
        ])->assertSessionHasErrors('items');

        $this->assertEqualsWithDelta(6.0, (float) $list->items()->firstOrFail()->qty, 1e-6);
    }

    public function test_stock_is_deducted_when_goods_move_to_preparation(): void
    {
        $stock = $this->fgStock('TAG-PICK-MOVE-'.uniqid(), 10);
        $list = $this->createPicking([$this->row($this->customerA, 6)]);
        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();

        $delivery = Delivery::where('picking_list_id', $list->id)->firstOrFail();

        $this->post(route('deliveries.scan', $delivery), ['code' => 'TRL-1'])->assertRedirect();
        $this->post(route('deliveries.scan', $delivery), ['code' => (string) $stock->tag])->assertRedirect();

        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::STATUS_PICKED, $fresh->status);
        $this->assertNotNull($fresh->stock_deducted_at);
        $this->assertEqualsWithDelta(4.0, (float) $stock->fresh()->qty, 1e-6);
        $this->assertSame('DELIVERY-PREP', $stock->fresh()->location_code);
    }

    public function test_scan_rejects_qty_above_the_plan(): void
    {
        $stock = $this->fgStock('TAG-PICK-OVER-'.uniqid(), 10);
        $list = $this->createPicking([$this->row($this->customerA, 6)]);
        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();

        $delivery = Delivery::where('picking_list_id', $list->id)->firstOrFail();

        $this->post(route('deliveries.scan', $delivery), [
            'code' => (string) $stock->tag,
            'qty' => 8,
        ])->assertSessionHasErrors('qty');

        $this->assertSame(0, $delivery->scans()->count());
    }

    public function test_stock_is_not_deducted_twice_after_qc_rejection(): void
    {
        $stock = $this->fgStock('TAG-PICK-TWICE-'.uniqid(), 10);
        $list = $this->createPicking([$this->row($this->customerA, 6)]);
        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();

        $delivery = Delivery::where('picking_list_id', $list->id)->firstOrFail();
        $this->post(route('deliveries.scan', $delivery), ['code' => 'TRL-1'])->assertRedirect();
        $this->post(route('deliveries.scan', $delivery), ['code' => (string) $stock->tag])->assertRedirect();

        $this->post(route('deliveries.qc.reject', $delivery), ['qc_notes' => 'Kemasan rusak'])->assertRedirect();
        $this->assertSame(Delivery::STATUS_DRAFT, $delivery->fresh()->status);
        $this->assertEqualsWithDelta(4.0, (float) $stock->fresh()->qty, 1e-6);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();

        $this->assertSame(Delivery::STATUS_PICKED, $delivery->fresh()->status);
        $this->assertEqualsWithDelta(4.0, (float) $stock->fresh()->qty, 1e-6, 'Stok tidak boleh dipotong dua kali');
    }

    public function test_return_to_origin_restores_stock_and_location(): void
    {
        $stock = $this->fgStock('TAG-PICK-RET-'.uniqid(), 10);
        $list = $this->createPicking([$this->row($this->customerA, 6)]);
        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();

        $delivery = Delivery::where('picking_list_id', $list->id)->firstOrFail();
        $this->post(route('deliveries.scan', $delivery), ['code' => 'TRL-1'])->assertRedirect();
        $this->post(route('deliveries.scan', $delivery), ['code' => (string) $stock->tag])->assertRedirect();

        $this->post(route('deliveries.return', $delivery))->assertRedirect();

        $this->assertEqualsWithDelta(10.0, (float) $stock->fresh()->qty, 1e-6);
        $this->assertSame($this->origin->code, $stock->fresh()->location_code);
        $this->assertNull($delivery->fresh()->stock_deducted_at);
        $this->assertSame(Delivery::STATUS_DRAFT, $delivery->fresh()->status);
    }

    public function test_submit_does_not_change_stock_again(): void
    {
        $stock = $this->fgStock('TAG-PICK-SUBMIT-'.uniqid(), 10);
        $list = $this->createPicking([$this->row($this->customerA, 6)]);
        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();

        $delivery = Delivery::where('picking_list_id', $list->id)->firstOrFail();
        $this->post(route('deliveries.scan', $delivery), ['code' => 'TRL-1'])->assertRedirect();
        $this->post(route('deliveries.scan', $delivery), ['code' => (string) $stock->tag])->assertRedirect();

        $service = app(DeliveryService::class);
        foreach ($delivery->fresh()->scans()->where('scan_type', DeliveryScan::TYPE_TROLLEY)->pluck('code') as $code) {
            $service->setTrolleyQc($delivery->fresh(), $code, Delivery::QC_PASSED, $this->admin->id);
        }
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();

        $this->post(route('deliveries.release-ceisa', $delivery))->assertRedirect();
        $this->put(route('deliveries.documents.update', $delivery), [
            'pen_no' => 'PEN-1',
            'aju_no' => 'AJU-1',
        ])->assertRedirect();
        $this->post(route('deliveries.submit', $delivery))->assertRedirect();

        $this->assertSame(Delivery::STATUS_RELEASED, $delivery->fresh()->status);
        $this->assertEqualsWithDelta(4.0, (float) $stock->fresh()->qty, 1e-6);
    }

    public function test_picking_pages_render(): void
    {
        $this->fgStock('TAG-PICK-PAGE-'.uniqid(), 10);
        $list = $this->createPicking([$this->row($this->customerA, 6)]);

        $this->get(route('picking-lists.index'))->assertOk();
        $this->get(route('picking-lists.create'))->assertOk();
        $this->get(route('picking-lists.show', $list))->assertOk();
        $this->get(route('picking-lists.edit', $list))->assertOk();
    }

    public function test_picking_without_deliveries_can_be_deleted(): void
    {
        $this->fgStock('TAG-PICK-DEL-'.uniqid(), 10);
        $list = $this->createPicking([$this->row($this->customerA, 6)]);

        $this->delete(route('picking-lists.destroy', $list))->assertRedirect(route('picking-lists.index'));

        $this->assertNull(PickingList::find($list->id));
    }

    public function test_validation_errors_are_keyed_by_row_uid(): void
    {
        $this->fgStock('TAG-PICK-UID-'.uniqid(), 10);

        $this->post(route('picking-lists.store'), [
            'picking_date' => now()->toDateString(),
            'items' => [
                ['uid' => 11, 'sequence' => 1, 'customer_id' => $this->customerA->id, 'part_id' => $this->fg->id, 'qty' => 1],
                // Baris kedua tanpa qty: error harus menempel ke uid 22, bukan index 1.
                ['uid' => 22, 'sequence' => 1, 'customer_id' => $this->customerB->id, 'part_id' => $this->fg->id, 'qty' => ''],
            ],
        ])->assertSessionHasErrors('items.22.qty');

        $this->assertSame(0, PickingList::count());
    }

    public function test_missing_customer_and_part_have_their_own_messages(): void
    {
        $this->post(route('picking-lists.store'), [
            'picking_date' => now()->toDateString(),
            'items' => [
                ['uid' => 5, 'sequence' => 1, 'customer_id' => '', 'part_id' => '', 'qty' => 2],
            ],
        ])->assertSessionHasErrors(['items.5.customer_id', 'items.5.part_id']);

        $this->assertSame(0, PickingList::count());
    }

    public function test_edit_page_reports_generated_deliveries(): void
    {
        $this->fgStock('TAG-PICK-EDIT-COUNT-'.uniqid(), 100);
        $list = $this->createPicking([$this->row($this->customerA, 6)]);

        $before = $this->get(route('picking-lists.edit', $list));
        $before->assertOk();
        $this->assertSame(0, $before->viewData('page')['props']['deliveriesCount']);

        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();

        $after = $this->get(route('picking-lists.edit', $list));
        $after->assertOk();
        $this->assertSame(1, $after->viewData('page')['props']['deliveriesCount']);
    }

    public function test_show_page_exposes_customers_for_the_generate_dialog(): void
    {
        $this->fgStock('TAG-PICK-SHOW-'.uniqid(), 100);
        $list = $this->createPicking([
            $this->row($this->customerA, 6, 1),
            $this->row($this->customerB, 4, 2),
        ]);

        $page = $this->get(route('picking-lists.show', $list))->assertOk()->viewData('page');
        $items = $page['props']['pickingList']['items'];

        $this->assertCount(2, $items);
        $this->assertSame($this->customerA->id, $items[0]['customer_id']);
        $this->assertSame($this->customerB->id, $items[1]['customer_id']);
        $this->assertArrayNotHasKey('stockStatuses', $page['props']);
    }

    public function test_picking_requires_permission(): void
    {
        $qc = User::where('email', 'qc@geumcheon.local')->firstOrFail();

        $this->actingAs($qc)->get(route('picking-lists.index'))->assertOk();
        $this->actingAs($qc)->get(route('picking-lists.create'))->assertForbidden();
    }
}
