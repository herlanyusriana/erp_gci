<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryScan;
use App\Models\Location;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\Trolley;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\WipInventoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Customer $customer;

    private Location $machineLocation;

    private Location $prepLocation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'customer_code' => 'CUST-'.uniqid(),
            'customer_name' => 'Budi Jaya',
            'address' => 'Jl. Industri 1',
            'is_active' => true,
        ]);

        // Master troli produksi ikut terpakai di database dev; bersihkan dulu
        // (di dalam transaksi test) lalu sediakan troli yang dipakai helper.
        Trolley::query()->delete();
        Trolley::create([
            'code' => 'TRL-1',
            'type' => 'internal',
            'kind' => 'compbase',
        ]);

        // Lokasi mesin dan area pengiriman (seeder sudah membuatnya; pastikan ada).
        $this->machineLocation = Location::firstOrCreate(
            ['code' => 'TPL_DONGSHIN'],
            ['name' => 'TPL DONGSHIN', 'type' => Location::TYPE_MACHINE, 'is_active' => true],
        );
        $this->machineLocation->update(['type' => Location::TYPE_MACHINE, 'is_active' => true]);

        $this->prepLocation = Location::firstOrCreate(
            ['code' => 'DELIVERY-PREP'],
            ['name' => 'Delivery Preparation Area', 'type' => Location::TYPE_DELIVERY_PREP, 'is_active' => true],
        );
        $this->prepLocation->update(['type' => Location::TYPE_DELIVERY_PREP, 'is_active' => true]);
    }

    private function fgPart(): Part
    {
        return Part::where('part_number', 'AAN30056405')->firstOrFail();
    }

    /** Stok barang jadi dengan lokasi mesin pembuat. */
    private function fgStock(string $tag, float $qty, ?string $locationCode = null): PartStock
    {
        return PartStock::create([
            'part_id' => $this->fgPart()->id,
            'tag' => $tag,
            'qty' => $qty,
            'qty_unit' => 'PCS',
            'location_code' => $locationCode ?? $this->machineLocation->code,
            'received_at' => now(),
        ]);
    }

    public function test_delivery_number_is_sequential_and_not_reused_after_delete(): void
    {
        $first = Delivery::generateDeliveryNo(now());
        Delivery::create([
            'delivery_no' => $first,
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'status' => Delivery::STATUS_DRAFT,
        ]);

        $second = Delivery::generateDeliveryNo(now());
        $this->assertNotSame($first, $second);

        Delivery::where('delivery_no', $first)->firstOrFail()->delete();

        $third = Delivery::generateDeliveryNo(now());
        $this->assertNotSame($first, $third, 'Nomor yang sudah dihapus tidak boleh dipakai ulang');
    }

    public function test_available_tags_are_sorted_fifo(): void
    {
        $older = $this->fgStock('TAG-OLD-'.uniqid(), 10);
        $older->forceFill(['received_at' => now()->subDays(3)])->save();
        $newer = $this->fgStock('TAG-NEW-'.uniqid(), 10);
        $newer->forceFill(['received_at' => now()->subDay()])->save();

        $tags = app(DeliveryService::class)->availableTags($this->fgPart()->id);

        $this->assertGreaterThanOrEqual(2, count($tags));
        $this->assertSame($older->tag, $tags[0]['tag'], 'Tag tertua harus muncul lebih dulu');
    }

    public function test_store_creates_draft_with_items_and_keeps_stock_location(): void
    {
        $stock = $this->fgStock('TAG-STORE-'.uniqid(), 50);

        $this->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'truck_no' => 'B 9123 GCI',
            'driver_name' => 'Slamet',
            'items' => [
                ['part_stock_id' => $stock->id, 'qty' => 20],
            ],
        ])->assertRedirect();

        $delivery = Delivery::latest('id')->firstOrFail();
        $this->assertSame(Delivery::STATUS_DRAFT, $delivery->status);
        $this->assertCount(1, $delivery->items);
        $this->assertSame($this->machineLocation->code, $stock->fresh()->location_code, 'Draft belum memindah barang');
    }

    public function test_store_rejects_quantity_above_tag_stock(): void
    {
        $stock = $this->fgStock('TAG-OVER-'.uniqid(), 5);

        $this->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'items' => [
                ['part_stock_id' => $stock->id, 'qty' => 10],
            ],
        ])->assertSessionHasErrors('items');

        $this->assertSame(0, Delivery::count());
    }

    public function test_store_rejects_the_same_tag_twice(): void
    {
        $stock = $this->fgStock('TAG-DUP-'.uniqid(), 50);

        $this->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'items' => [
                ['part_stock_id' => $stock->id, 'qty' => 10],
                ['part_stock_id' => $stock->id, 'qty' => 5],
            ],
        ])->assertSessionHasErrors('items');
    }

    public function test_store_rejects_non_finished_good_stock(): void
    {
        $material = Part::where('part_number', 'CBKG07256C')->firstOrFail();
        $stock = PartStock::create([
            'part_id' => $material->id,
            'tag' => 'MAT-'.uniqid(),
            'qty' => 100,
            'qty_unit' => 'KGM',
            'received_at' => now(),
        ]);

        $this->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'items' => [
                ['part_stock_id' => $stock->id, 'qty' => 10],
            ],
        ])->assertSessionHasErrors('items');
    }

    public function test_pick_moves_stock_to_preparation_and_marks_picked(): void
    {
        $stock = $this->fgStock('TAG-PICK-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();

        $this->assertSame($this->prepLocation->code, $stock->fresh()->location_code);
        $this->assertSame(Delivery::STATUS_PICKED, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->picked_at);
        $this->assertSame($this->machineLocation->code, $delivery->items()->first()->origin_location_code);
    }

    public function test_return_to_origin_restores_location_and_status(): void
    {
        $stock = $this->fgStock('TAG-RET-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.return', $delivery))->assertRedirect();

        $this->assertSame($this->machineLocation->code, $stock->fresh()->location_code);
        $this->assertSame(Delivery::STATUS_DRAFT, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->picked_at);
    }

    public function test_deleting_a_draft_does_not_change_stock_location(): void
    {
        $stock = $this->fgStock('TAG-DEL-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->delete(route('deliveries.destroy', $delivery))->assertRedirect(route('deliveries.index'));

        $this->assertSame($this->machineLocation->code, $stock->fresh()->location_code);
    }

    public function test_submitted_outgoing_contributes_to_fg_issue_in_wip_inventory(): void
    {
        $stock = $this->fgStock('TAG-WIP-'.uniqid(), 40);
        $delivery = $this->releasedDelivery($stock, 25, 'INV/WIP/1');

        $machine = Machine::where('machine_code', $this->machineLocation->code)->firstOrFail();
        $today = now()->toDateString();

        $rows = app(WipInventoryService::class)
            ->forRange($today, $today, $machine->id, WipInventoryService::CATEGORY_WIP_FG);

        $row = collect($rows)->firstWhere('part_id', $this->fgPart()->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(25.0, (float) $row['issued_qty'], 1e-6);
    }

    public function test_full_flow_from_picking_to_submitted_outgoing(): void
    {
        $tag = 'TAG-FLOW-'.uniqid();
        $stock = $this->fgStock($tag, 40);

        // 1. Picking (web)
        $delivery = $this->makeDelivery($stock, 25, invoiceNo: 'INV/FLOW/001');
        $this->assertSame(Delivery::STATUS_DRAFT, $delivery->status);

        // 2. Pindah ke area pengiriman: scan label FG + scan troli
        $this->scanTrolley($delivery, $tag);
        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::STATUS_PICKED, $fresh->status);
        $this->assertNotNull($fresh->picked_at);
        $this->assertSame($this->prepLocation->code, $stock->fresh()->location_code);

        // 3. OQC per troli lalu dinyatakan lolos
        $this->passOqc($delivery);
        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::STATUS_QC_PASSED, $fresh->status);
        $this->assertSame(Delivery::QC_PASSED, $fresh->qc_status);

        // 4. Isi PEN/AJU lalu submit
        $this->put(route('deliveries.documents.update', $delivery), [
            'pen_no' => 'PEN-99',
            'aju_no' => 'AJU-99',
            'invoice_no' => 'INV/FLOW/001',
        ])->assertRedirect();

        $this->post(route('deliveries.submit', $delivery))->assertRedirect();

        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::STATUS_RELEASED, $fresh->status);
        $this->assertNotNull($fresh->released_at);
        $this->assertSame('PEN-99', $fresh->pen_no);
        $this->assertSame('AJU-99', $fresh->aju_no);

        // 5. Stok berkurang sebesar qty yang dikirim (40 - 25).
        $this->assertEqualsWithDelta(15.0, (float) $stock->fresh()->qty, 1e-6);
    }

    public function test_oqc_cannot_run_before_goods_are_in_the_delivery_area(): void
    {
        $stock = $this->fgStock('TAG-QC-EARLY-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.qc.pass', $delivery))->assertSessionHasErrors('status');
        $this->assertSame(Delivery::STATUS_DRAFT, $delivery->fresh()->status);
    }

    public function test_oqc_rejection_returns_delivery_to_draft_with_reason(): void
    {
        $stock = $this->fgStock('TAG-QC-REJ-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->scanTrolley($delivery, (string) $stock->tag);
        $this->post(route('deliveries.qc.reject', $delivery), ['qc_notes' => 'Kemasan rusak'])->assertRedirect();

        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::STATUS_DRAFT, $fresh->status);
        $this->assertSame(Delivery::QC_REJECTED, $fresh->qc_status);
        $this->assertSame('Kemasan rusak', $fresh->qc_notes);
        $this->assertNull($fresh->picked_at);
    }

    public function test_oqc_rejection_requires_a_reason(): void
    {
        $stock = $this->fgStock('TAG-QC-NOREASON-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->scanTrolley($delivery, (string) $stock->tag);
        $this->post(route('deliveries.qc.reject', $delivery), ['qc_notes' => ''])->assertSessionHasErrors('qc_notes');

        $this->assertSame(Delivery::STATUS_PICKED, $delivery->fresh()->status);
    }

    public function test_submit_requires_pen_and_aju(): void
    {
        $stock = $this->fgStock('TAG-NOPEN-'.uniqid(), 40);
        $delivery = $this->qcPassedDelivery($stock, 25);

        $this->post(route('deliveries.submit', $delivery))->assertSessionHasErrors('pen_no');
        $this->assertSame(Delivery::STATUS_QC_PASSED, $delivery->fresh()->status);
    }

    public function test_submit_requires_oqc_to_pass_first(): void
    {
        $stock = $this->fgStock('TAG-SUBMIT-EARLY-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->scanTrolley($delivery, (string) $stock->tag);

        $this->put(route('deliveries.documents.update', $delivery), [
            'pen_no' => 'PEN-1',
            'aju_no' => 'AJU-1',
        ])->assertRedirect();

        $this->post(route('deliveries.submit', $delivery))->assertSessionHasErrors('status');
        $this->assertSame(Delivery::STATUS_PICKED, $delivery->fresh()->status);
    }

    public function test_trolley_qc_must_be_complete_and_ok_before_passing(): void
    {
        $stock = $this->fgStock('TAG-QC-CHECK-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->scanTrolley($delivery, (string) $stock->tag);

        // Belum ada checklist troli -> belum boleh lolos.
        $this->post(route('deliveries.qc.pass', $delivery))->assertSessionHasErrors('status');

        // Troli ditandai NG -> tetap tidak boleh lolos.
        app(DeliveryService::class)->setTrolleyQc($delivery->fresh(), 'TRL-1', Delivery::QC_REJECTED, $this->admin->id);
        $this->post(route('deliveries.qc.pass', $delivery))->assertSessionHasErrors('status');

        // Semua OK -> lolos.
        app(DeliveryService::class)->setTrolleyQc($delivery->fresh(), 'TRL-1', Delivery::QC_PASSED, $this->admin->id);
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();
        $this->assertSame(Delivery::STATUS_QC_PASSED, $delivery->fresh()->status);
    }

    public function test_outgoing_cannot_be_submitted_twice(): void
    {
        $stock = $this->fgStock('TAG-TWICE-'.uniqid(), 40);
        $delivery = $this->releasedDelivery($stock, 25, 'INV/TWICE/1');

        $this->post(route('deliveries.submit', $delivery))->assertSessionHasErrors('status');

        // Stok hanya berkurang sekali.
        $this->assertEqualsWithDelta(15.0, (float) $stock->fresh()->qty, 1e-6);
    }

    public function test_documents_cannot_be_printed_before_release(): void
    {
        $stock = $this->fgStock('TAG-PRINT-EARLY-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25, invoiceNo: 'INV/EARLY/2');

        $this->get(route('deliveries.print', $delivery))->assertForbidden();
        $this->get(route('deliveries.invoice', $delivery))->assertForbidden();
        $this->get(route('deliveries.packing-list', $delivery))->assertForbidden();
    }

    public function test_released_delivery_prints_note_invoice_and_packing_list(): void
    {
        $tag = 'TAG-SJ-'.uniqid();
        $stock = $this->fgStock($tag, 40);
        $delivery = $this->releasedDelivery($stock, 25, 'INV/SJ/001');

        $this->get(route('deliveries.print', $delivery))
            ->assertOk()
            ->assertSee('SURAT JALAN')
            ->assertSee($delivery->delivery_no)
            ->assertSee($this->customer->customer_name)
            ->assertSee($tag);

        $this->get(route('deliveries.invoice', $delivery))
            ->assertOk()
            ->assertSee('INVOICE')
            ->assertSee('INV/SJ/001')
            ->assertSee($tag);

        $this->get(route('deliveries.packing-list', $delivery))
            ->assertOk()
            ->assertSee('PACKING LIST')
            ->assertSee($tag);
    }

    public function test_delivery_label_is_available_after_picking(): void
    {
        $tag = 'TAG-LBL-'.uniqid();
        $stock = $this->fgStock($tag, 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->scanTrolley($delivery, $tag);
        $this->assertSame(Delivery::STATUS_PICKED, $delivery->fresh()->status);

        $this->get(route('deliveries.label', $delivery))
            ->assertOk()
            ->assertSee('LABEL PENGIRIMAN')
            ->assertSee($tag)
            ->assertSee($delivery->delivery_no);
    }

    public function test_delivery_item_stores_price_and_total(): void
    {
        $stock = $this->fgStock('TAG-PRICE-'.uniqid(), 40);

        $this->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'items' => [
                ['part_stock_id' => $stock->id, 'qty' => 10, 'price' => 12500],
            ],
        ])->assertRedirect();

        $item = Delivery::latest('id')->firstOrFail()->items()->firstOrFail();
        $this->assertEqualsWithDelta(12500.0, (float) $item->price, 1e-6);
        $this->assertEqualsWithDelta(125000.0, (float) $item->total_price, 1e-6);
    }

    /** Scan satu troli dan satu tag FG lewat endpoint web (tahap pindah ke prep). */
    private function scanTrolley(Delivery $delivery, string $tag, string $trolleyCode = 'TRL-1'): void
    {
        $this->post(route('deliveries.scan', $delivery), ['code' => $trolleyCode])->assertRedirect();
        $this->post(route('deliveries.scan', $delivery), ['code' => $tag])->assertRedirect();
    }

    /** OQC: tandai semua troli OK lalu nyatakan lolos. */
    private function passOqc(Delivery $delivery): void
    {
        $service = app(DeliveryService::class);

        foreach ($delivery->scans()->where('scan_type', DeliveryScan::TYPE_TROLLEY)->pluck('code') as $code) {
            $service->setTrolleyQc($delivery->fresh(), $code, Delivery::QC_PASSED, $this->admin->id);
        }

        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();
    }

    /** Delivery yang sudah sampai tahap OQC lolos, siap submit. */
    private function qcPassedDelivery(PartStock $stock, float $qty, string $invoiceNo = 'INV/FLOW/001'): Delivery
    {
        $delivery = $this->makeDelivery($stock, $qty, $invoiceNo);

        $this->scanTrolley($delivery, (string) $stock->tag);
        $this->passOqc($delivery);

        return $delivery->fresh();
    }

    /** Delivery yang sudah disubmit (stok berkurang, dokumen bisa dicetak). */
    private function releasedDelivery(PartStock $stock, float $qty, string $invoiceNo): Delivery
    {
        $delivery = $this->qcPassedDelivery($stock, $qty, $invoiceNo);

        $this->put(route('deliveries.documents.update', $delivery), [
            'pen_no' => 'PEN-1',
            'aju_no' => 'AJU-1',
            'invoice_no' => $invoiceNo,
        ])->assertRedirect();

        $this->post(route('deliveries.submit', $delivery))->assertRedirect();

        return $delivery->fresh();
    }

    public function test_creating_a_delivery_requires_permission(): void
    {
        // QC boleh melihat daftar, tetapi tidak boleh membuat pengiriman.
        $noAccess = User::where('email', 'qc@geumcheon.local')->firstOrFail();

        $this->actingAs($noAccess)->get(route('deliveries.index'))->assertOk();
        $this->actingAs($noAccess)->get(route('deliveries.create'))->assertForbidden();
    }

    public function test_customer_master_can_be_created_and_updated(): void
    {
        $code = 'CUST-NEW-'.uniqid();

        $this->post(route('customers.store'), [
            'customer_code' => $code,
            'customer_name' => 'Customer Baru',
            'address' => 'Jl. Baru 2',
            'is_active' => true,
        ])->assertRedirect(route('customers.index'));

        $customer = Customer::where('customer_code', $code)->firstOrFail();

        $this->put(route('customers.update', $customer), [
            'customer_code' => $code,
            'customer_name' => 'Customer Diubah',
            'is_active' => true,
        ])->assertRedirect(route('customers.index'));

        $this->assertSame('Customer Diubah', $customer->fresh()->customer_name);
    }

    private function makeDelivery(PartStock $stock, float $qty, ?string $invoiceNo = null): Delivery
    {
        $this->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'invoice_no' => $invoiceNo,
            'items' => [
                ['part_stock_id' => $stock->id, 'qty' => $qty],
            ],
        ])->assertRedirect();

        return Delivery::latest('id')->firstOrFail();
    }
}
