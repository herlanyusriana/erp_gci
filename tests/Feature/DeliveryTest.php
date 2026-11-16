<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryScan;
use App\Models\Location;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionResult;
use App\Models\Trolley;
use App\Models\User;
use App\Models\WorkOrder;
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

    public function test_picked_delivery_contributes_to_fg_issue_in_wip_inventory(): void
    {
        $stock = $this->fgStock('TAG-WIP-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();

        $machine = Machine::where('machine_code', $this->machineLocation->code)->firstOrFail();
        $today = now()->toDateString();

        $rows = app(WipInventoryService::class)
            ->forRange($today, $today, $machine->id, WipInventoryService::CATEGORY_WIP_FG);

        $row = collect($rows)->firstWhere('part_id', $this->fgPart()->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(25.0, (float) $row['issued_qty'], 1e-6);
    }

    public function test_full_flow_from_do_to_released_documents(): void
    {
        $tag = 'TAG-FLOW-'.uniqid();
        $stock = $this->fgStock($tag, 40);

        // 1. Delivery Order
        $delivery = $this->makeDelivery($stock, 25, invoiceNo: 'INV/FLOW/001');
        $this->assertSame(Delivery::STATUS_DRAFT, $delivery->status);

        // 2. Pindah ke area pengiriman
        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->assertSame(Delivery::STATUS_PICKED, $delivery->fresh()->status);

        // 3. QC lolos
        $this->post(route('deliveries.qc.pass', $delivery), ['qc_notes' => 'Fisik sesuai'])->assertRedirect();
        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::STATUS_QC_PASSED, $fresh->status);
        $this->assertSame(Delivery::QC_PASSED, $fresh->qc_status);
        $this->assertNotNull($fresh->qc_checked_at);

        // 4. Scan troli + label FG, lalu rilis label
        $this->scanTrolley($delivery, $tag);
        $this->post(route('deliveries.release-label', $delivery))->assertRedirect();
        $this->assertSame(Delivery::STATUS_LOADED, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->loaded_at);

        // 5. Rilis dokumen
        $this->post(route('deliveries.release-documents', $delivery))->assertRedirect();
        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::STATUS_RELEASED, $fresh->status);
        $this->assertNotNull($fresh->released_at);
    }

    public function test_qc_cannot_run_before_goods_are_in_the_delivery_area(): void
    {
        $stock = $this->fgStock('TAG-QC-EARLY-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.qc.pass', $delivery))->assertSessionHasErrors('status');
        $this->assertSame(Delivery::STATUS_DRAFT, $delivery->fresh()->status);
    }

    public function test_qc_rejection_returns_delivery_to_draft_with_reason(): void
    {
        $stock = $this->fgStock('TAG-QC-REJ-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.reject', $delivery), ['qc_notes' => 'Kemasan rusak'])->assertRedirect();

        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::STATUS_DRAFT, $fresh->status);
        $this->assertSame(Delivery::QC_REJECTED, $fresh->qc_status);
        $this->assertSame('Kemasan rusak', $fresh->qc_notes);
        $this->assertNull($fresh->picked_at);
    }

    public function test_qc_rejection_requires_a_reason(): void
    {
        $stock = $this->fgStock('TAG-QC-NOREASON-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.reject', $delivery), ['qc_notes' => ''])->assertSessionHasErrors('qc_notes');

        $this->assertSame(Delivery::STATUS_PICKED, $delivery->fresh()->status);
    }

    public function test_label_cannot_be_released_before_qc_passes(): void
    {
        $stock = $this->fgStock('TAG-LBL-EARLY-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.release-label', $delivery))->assertSessionHasErrors('status');

        $this->assertSame(Delivery::STATUS_PICKED, $delivery->fresh()->status);
    }

    public function test_documents_cannot_be_released_before_loading(): void
    {
        $stock = $this->fgStock('TAG-DOC-EARLY-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25, invoiceNo: 'INV/EARLY/1');

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();
        $this->post(route('deliveries.release-documents', $delivery))->assertSessionHasErrors('status');

        $this->assertSame(Delivery::STATUS_QC_PASSED, $delivery->fresh()->status);
    }

    public function test_documents_require_an_invoice_number(): void
    {
        $stock = $this->fgStock('TAG-DOC-NOINV-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();
        $this->scanTrolley($delivery, (string) $stock->tag);
        $this->post(route('deliveries.release-label', $delivery))->assertRedirect();
        $this->post(route('deliveries.release-documents', $delivery))->assertSessionHasErrors('invoice_no');

        $this->assertSame(Delivery::STATUS_LOADED, $delivery->fresh()->status);
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

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();

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

    /** Scan satu troli dan satu tag FG lewat endpoint web. */
    private function scanTrolley(Delivery $delivery, string $tag, string $trolleyCode = 'TRL-1'): void
    {
        $this->post(route('deliveries.scan', $delivery), ['code' => $trolleyCode])->assertRedirect();
        $this->post(route('deliveries.scan', $delivery), ['code' => $tag])->assertRedirect();
    }

    public function test_release_label_requires_a_trolley_scan(): void
    {
        $stock = $this->fgStock('TAG-NOTROLLEY-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();

        // Tag sudah discan, tapi troli belum.
        $this->post(route('deliveries.scan', $delivery), ['code' => (string) $stock->tag])->assertRedirect();
        $this->post(route('deliveries.release-label', $delivery))->assertSessionHasErrors('scans');

        $this->assertSame(Delivery::STATUS_QC_PASSED, $delivery->fresh()->status);
    }

    public function test_release_label_requires_every_tag_scanned(): void
    {
        $first = $this->fgStock('TAG-MULTI-1-'.uniqid(), 20);
        $second = $this->fgStock('TAG-MULTI-2-'.uniqid(), 20);

        $this->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'delivery_date' => now()->toDateString(),
            'items' => [
                ['part_stock_id' => $first->id, 'qty' => 10],
                ['part_stock_id' => $second->id, 'qty' => 10],
            ],
        ])->assertRedirect();

        $delivery = Delivery::latest('id')->firstOrFail();

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();
        $this->post(route('deliveries.scan', $delivery), ['code' => 'TRL-1'])->assertRedirect();
        $this->post(route('deliveries.scan', $delivery), ['code' => (string) $first->tag])->assertRedirect();

        // Satu tag belum discan.
        $this->post(route('deliveries.release-label', $delivery))->assertSessionHasErrors('scans');

        $this->post(route('deliveries.scan', $delivery), ['code' => (string) $second->tag])->assertRedirect();
        $this->post(route('deliveries.release-label', $delivery))->assertRedirect();

        $this->assertSame(Delivery::STATUS_LOADED, $delivery->fresh()->status);
    }

    public function test_scan_accepts_trolley_qr_payload(): void
    {
        $stock = $this->fgStock('TAG-QR-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();

        $trolleyCode = $delivery->delivery_no.'-T1';
        Trolley::create([
            'code' => $trolleyCode,
            'type' => 'internal',
            'kind' => 'backplate',
        ]);

        $payload = json_encode([
            'type' => 'trolley',
            'delivery_id' => $delivery->id,
            'code' => $trolleyCode,
        ]);

        $this->post(route('deliveries.scan', $delivery), ['code' => $payload])->assertRedirect();

        $this->assertDatabaseHas('delivery_scans', [
            'delivery_id' => $delivery->id,
            'scan_type' => DeliveryScan::TYPE_TROLLEY,
            'code' => $trolleyCode,
        ]);
    }

    public function test_scan_resolves_fg_label_qr_from_production(): void
    {
        $stock = $this->fgStock('TAG-FGQR-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();

        $result = ProductionResult::create([
            'work_order_id' => WorkOrder::create([
                'wo_no' => 'WO-QR-'.uniqid(),
                'part_id' => $this->fgPart()->id,
                'qty' => 10,
                'status' => 'in_progress',
            ])->id,
            'parent_part_id' => $this->fgPart()->id,
            'result_date' => now()->toDateString(),
            'qty_good' => 10,
            'qty_reject' => 0,
            'uom' => 'PCS',
            'label_status' => 'pending',
            'output_part_stock_id' => $stock->id,
        ]);

        $payload = json_encode(['type' => 'fg', 'result_id' => $result->id, 'part_id' => $this->fgPart()->id]);

        $this->post(route('deliveries.scan', $delivery), ['code' => $payload])->assertRedirect();

        $this->assertDatabaseHas('delivery_scans', [
            'delivery_id' => $delivery->id,
            'scan_type' => DeliveryScan::TYPE_TAG,
            'part_stock_id' => $stock->id,
        ]);
    }

    public function test_scan_rejects_a_tag_from_another_delivery(): void
    {
        $mine = $this->fgStock('TAG-MINE-'.uniqid(), 40);
        $other = $this->fgStock('TAG-OTHER-'.uniqid(), 40);

        $delivery = $this->makeDelivery($mine, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();

        $this->post(route('deliveries.scan', $delivery), ['code' => (string) $other->tag])
            ->assertSessionHasErrors('code');

        $this->assertSame(0, DeliveryScan::where('delivery_id', $delivery->id)->count());
    }

    public function test_duplicate_scan_is_recorded_once(): void
    {
        $stock = $this->fgStock('TAG-DUPSCAN-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();

        Trolley::create([
            'code' => 'TRL-X',
            'type' => 'internal',
            'kind' => 'compbase',
        ]);

        $this->post(route('deliveries.scan', $delivery), ['code' => 'TRL-X'])->assertRedirect();
        $this->post(route('deliveries.scan', $delivery), ['code' => 'TRL-X'])->assertRedirect();

        $this->assertSame(1, DeliveryScan::where('delivery_id', $delivery->id)->where('scan_type', DeliveryScan::TYPE_TROLLEY)->count());
    }

    public function test_scan_is_rejected_before_qc_passes(): void
    {
        $stock = $this->fgStock('TAG-EARLYSCAN-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.scan', $delivery), ['code' => 'TRL-1'])->assertSessionHasErrors('code');

        $this->assertSame(0, DeliveryScan::where('delivery_id', $delivery->id)->count());
    }

    public function test_api_scan_and_loading_context_work_for_mobile(): void
    {
        $tag = 'TAG-API-'.uniqid();
        $stock = $this->fgStock($tag, 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();

        $this->getJson(route('api.deliveries.loading-context', $delivery))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.total_tags', 1)
            ->assertJsonPath('data.scanned_tags', 0);

        Trolley::create([
            'code' => 'TRL-API-1',
            'type' => 'internal',
            'kind' => 'compbase',
        ]);

        $this->postJson(route('api.deliveries.scan', $delivery), ['code' => 'TRL-API-1'])
            ->assertOk()
            ->assertJsonPath('data.scan_type', DeliveryScan::TYPE_TROLLEY);

        $this->postJson(route('api.deliveries.scan', $delivery), ['code' => $tag])
            ->assertOk()
            ->assertJsonPath('data.scan_type', DeliveryScan::TYPE_TAG)
            ->assertJsonPath('data.loading_complete', true);

        $this->getJson(route('api.deliveries.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $delivery->id);
    }

    public function test_trolley_scan_is_validated_against_the_synced_master(): void
    {
        Trolley::create([
            'code' => 'TRL-07',
            'type' => 'internal',
            'kind' => 'compbase',
        ]);

        $stock = $this->fgStock('TAG-MASTER-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();

        // Kode tidak ada di master -> ditolak.
        $this->post(route('deliveries.scan', $delivery), ['code' => 'TRL-999'])
            ->assertSessionHasErrors('code');

        // Kode ada di master -> diterima.
        $this->post(route('deliveries.scan', $delivery), ['code' => 'trl-07'])->assertRedirect();

        $this->assertDatabaseHas('delivery_scans', [
            'delivery_id' => $delivery->id,
            'scan_type' => DeliveryScan::TYPE_TROLLEY,
            'code' => 'TRL-07',
        ]);
    }

    public function test_trolley_scan_is_accepted_before_the_master_is_synced(): void
    {
        Trolley::query()->delete();

        // Master kosong: validasi dilewati supaya loading tidak terkunci.
        $stock = $this->fgStock('TAG-EMPTY-'.uniqid(), 40);
        $delivery = $this->makeDelivery($stock, 25);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();

        $this->post(route('deliveries.scan', $delivery), ['code' => 'ANY-TROLLEY-1'])->assertRedirect();
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

    /** Delivery yang sudah melewati seluruh tahap sampai dokumen dirilis. */
    private function releasedDelivery(PartStock $stock, float $qty, string $invoiceNo): Delivery
    {
        $delivery = $this->makeDelivery($stock, $qty, $invoiceNo);

        $this->post(route('deliveries.pick', $delivery))->assertRedirect();
        $this->post(route('deliveries.qc.pass', $delivery))->assertRedirect();
        $this->scanTrolley($delivery, (string) $stock->tag);
        $this->post(route('deliveries.release-label', $delivery))->assertRedirect();
        $this->post(route('deliveries.release-documents', $delivery))->assertRedirect();

        return $delivery->fresh();
    }
}
