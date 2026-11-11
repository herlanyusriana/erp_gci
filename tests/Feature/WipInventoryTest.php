<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionMaterialReceipt;
use App\Models\ProductionResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderConsumption;
use App\Models\WorkOrderItem;
use App\Services\ProductionResultService;
use App\Services\WipInventoryService;
use App\Services\WipStockService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WipInventoryTest extends TestCase
{
    use DatabaseTransactions;

    private Machine $machine;

    private Part $part;

    private WorkOrder $wo;

    private WorkOrderItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());

        $this->machine = Machine::create([
            'machine_code' => 'WIP-'.uniqid(),
            'machine_name' => 'WIP Machine',
            'is_active' => true,
        ]);
        $this->part = Part::where('part_number', 'CBKG07256C')->firstOrFail();
        $this->wo = WorkOrder::create([
            'wo_no' => 'WO-WIP-'.uniqid(),
            'part_id' => Part::where('part_number', 'AAN30056405')->value('id'),
            'qty' => 100,
            'status' => 'in_progress',
        ]);
        $this->item = WorkOrderItem::create([
            'work_order_id' => $this->wo->id,
            'sequence' => 1,
            'machine_id' => $this->machine->id,
            'parent_part_id' => $this->wo->part_id,
            'parent_qty' => 1,
            'parent_uom' => 'PCS',
            'child_part_id' => $this->part->id,
            'selected_part_id' => $this->part->id,
            'child_qty' => 1,
            'uom_rm' => 'KGM',
            'qty_required' => 100,
        ]);
    }

    private function receipt(string $date, float $qty, float $consumed = 0, ?Machine $machine = null): ProductionMaterialReceipt
    {
        $machine ??= $this->machine;

        $issue = MaterialIssue::create([
            'issue_no' => 'MI-WIP-'.uniqid(),
            'work_order_id' => $this->wo->id,
            'issue_date' => $date,
            'status' => 'posted',
        ]);

        $issueItem = MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $this->item->id,
            'part_id' => $this->part->id,
            'tag' => 'T-'.uniqid(),
            'qty' => $qty,
            'uom' => 'KGM',
            'invoice' => 'INV-'.$date,
            'supplier' => 'Supplier WIP',
        ]);

        return ProductionMaterialReceipt::create([
            'material_issue_item_id' => $issueItem->id,
            'tag' => $issueItem->tag,
            'part_id' => $this->part->id,
            'machine_id' => $machine->id,
            'work_order_id' => $this->wo->id,
            'work_order_item_id' => $this->item->id,
            'received_at' => $date.' 08:00:00',
            'qty' => $qty,
            'qty_consumed' => $consumed,
            'uom' => 'KGM',
            'transfer_status' => 'transferred',
        ]);
    }

    private function receiptForPart(Part $part, string $date, float $qty, ?Machine $machine = null): ProductionMaterialReceipt
    {
        $machine ??= $this->machine;

        $issue = MaterialIssue::create([
            'issue_no' => 'MI-WIP-'.uniqid(),
            'work_order_id' => $this->wo->id,
            'issue_date' => $date,
            'status' => 'posted',
        ]);

        $issueItem = MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $this->item->id,
            'part_id' => $part->id,
            'tag' => 'T-'.uniqid(),
            'qty' => $qty,
            'uom' => 'KGM',
            'invoice' => 'INV-'.$part->id.'-'.$date,
            'supplier' => 'Supplier WIP',
        ]);

        return ProductionMaterialReceipt::create([
            'material_issue_item_id' => $issueItem->id,
            'tag' => $issueItem->tag,
            'part_id' => $part->id,
            'machine_id' => $machine->id,
            'work_order_id' => $this->wo->id,
            'work_order_item_id' => $this->item->id,
            'received_at' => $date.' 08:00:00',
            'qty' => $qty,
            'qty_consumed' => 0,
            'uom' => 'KGM',
            'transfer_status' => 'transferred',
        ]);
    }

    private function consumption(string $date, float $qty): WorkOrderConsumption
    {
        $consumption = new WorkOrderConsumption([
            'work_order_id' => $this->wo->id,
            'work_order_item_id' => $this->item->id,
            'part_id' => $this->part->id,
            'qty' => $qty,
            'uom' => 'KGM',
        ]);
        $consumption->created_at = $date.' 10:00:00';
        $consumption->updated_at = $date.' 10:00:00';
        $consumption->save();

        return $consumption;
    }

    public function test_beginning_stock_carries_forward_everything_before_the_filter_date(): void
    {
        $this->receipt('2026-10-27', 100);
        $this->consumption('2026-10-27', 40);
        $this->receipt('2026-10-30', 50);

        $service = app(WipInventoryService::class);

        $this->assertEqualsWithDelta(60.0, $service->beginningStock($this->machine->id, $this->part->id, '2026-10-30'), 1e-6);
    }

    public function test_received_and_issued_are_scoped_to_the_single_day(): void
    {
        $this->receipt('2026-10-29', 100);
        $this->receipt('2026-10-30', 50);
        $this->consumption('2026-10-30', 30);

        $service = app(WipInventoryService::class);

        $this->assertEqualsWithDelta(50.0, $service->receivedQty($this->machine->id, $this->part->id, '2026-10-30'), 1e-6);
        $this->assertEqualsWithDelta(30.0, $service->issuedQty($this->machine->id, $this->part->id, '2026-10-30'), 1e-6);
    }

    public function test_end_stock_is_beginning_plus_received_minus_issued(): void
    {
        $this->receipt('2026-10-28', 200);
        $this->consumption('2026-10-28', 80);
        $this->receipt('2026-10-30', 50);
        $this->consumption('2026-10-30', 20);

        $service = app(WipInventoryService::class);
        $rows = $service->forDate('2026-10-30');

        $row = collect($rows)->firstWhere(fn (array $r): bool => $r['machine_id'] === $this->machine->id
            && $r['part_id'] === $this->part->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(120.0, (float) $row['beginning_qty'], 1e-6);
        $this->assertEqualsWithDelta(50.0, (float) $row['received_qty'], 1e-6);
        $this->assertEqualsWithDelta(20.0, (float) $row['issued_qty'], 1e-6);
        $this->assertEqualsWithDelta(150.0, (float) $row['ending_qty'], 1e-6);
        $this->assertSame('KGM', $row['uom']);
    }

    public function test_rows_are_grouped_per_machine_and_part(): void
    {
        $otherMachine = Machine::create([
            'machine_code' => 'WIP2-'.uniqid(),
            'machine_name' => 'WIP Machine 2',
            'is_active' => true,
        ]);

        $this->receipt('2026-10-30', 10);
        $this->receipt('2026-10-30', 25, 0, $otherMachine);

        $rows = app(WipInventoryService::class)->forDate('2026-10-30');

        $this->assertCount(2, collect($rows)->where('part_id', $this->part->id));
    }

    public function test_rows_carry_part_type_so_wip_and_raw_material_can_be_grouped(): void
    {
        $this->receipt('2026-10-30', 100);
        $this->consumption('2026-10-30', 20);

        $wipPart = Part::whereHas('partType', fn ($q) => $q->whereRaw('UPPER(code) = ?', ['WIP']))->firstOrFail();
        $wipWo = WorkOrder::create([
            'wo_no' => 'WO-WIP-TYPE-'.uniqid(),
            'part_id' => $this->wo->part_id,
            'qty' => 100,
            'status' => 'in_progress',
        ]);
        $wipItem = WorkOrderItem::create([
            'work_order_id' => $wipWo->id,
            'sequence' => 1,
            'machine_id' => $this->machine->id,
            'parent_part_id' => $wipWo->part_id,
            'parent_qty' => 1,
            'parent_uom' => 'PCS',
            'child_part_id' => $wipPart->id,
            'selected_part_id' => $wipPart->id,
            'child_qty' => 1,
            'uom_rm' => 'KGM',
            'qty_required' => 100,
        ]);
        $issue = MaterialIssue::create([
            'issue_no' => 'MI-WIP-TYPE-'.uniqid(),
            'work_order_id' => $wipWo->id,
            'issue_date' => '2026-10-30',
            'status' => 'posted',
        ]);
        $issueItem = MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $wipItem->id,
            'part_id' => $wipPart->id,
            'tag' => 'T-WIP-'.uniqid(),
            'qty' => 50,
            'uom' => 'KGM',
        ]);
        ProductionMaterialReceipt::create([
            'material_issue_item_id' => $issueItem->id,
            'tag' => $issueItem->tag,
            'part_id' => $wipPart->id,
            'machine_id' => $this->machine->id,
            'work_order_id' => $wipWo->id,
            'work_order_item_id' => $wipItem->id,
            'received_at' => '2026-10-30 08:00:00',
            'qty' => 50,
            'qty_consumed' => 0,
            'uom' => 'KGM',
            'transfer_status' => 'transferred',
        ]);

        $rows = collect(app(WipInventoryService::class)->forDate('2026-10-30'));

        $this->assertSame('MATERIAL', $rows->firstWhere('part_id', $this->part->id)['part_type']);
        $this->assertSame('WIP', $rows->firstWhere('part_id', $wipPart->id)['part_type']);
    }

    public function test_machine_filter_narrows_the_rows(): void
    {
        $otherMachine = Machine::create([
            'machine_code' => 'WIP3-'.uniqid(),
            'machine_name' => 'WIP Machine 3',
            'is_active' => true,
        ]);

        $this->receipt('2026-10-30', 10);
        $this->receipt('2026-10-30', 25, 0, $otherMachine);

        $rows = app(WipInventoryService::class)->forDate('2026-10-30', $this->machine->id);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame($this->machine->id, $row['machine_id']);
        }
    }

    public function test_web_stock_rows_match_the_apk_wip_board_exactly(): void
    {
        $wipPart = Part::whereRaw('UPPER(part_number) LIKE ?', ['%-WIP%'])->firstOrFail();

        // Stok WIP fisik hasil produksi.
        PartStock::create([
            'part_id' => $wipPart->id,
            'tag' => 'WO-TEST#'.$wipPart->part_number.'#R1',
            'qty' => 352, 'qty_unit' => 'PCS', 'received_at' => now()->subDay(),
        ]);

        $webRows = app(WipStockService::class)->current('WIP');

        $webRow = collect($webRows)->firstWhere('part_id', $wipPart->id);
        $this->assertNotNull($webRow, 'Part WIP harus muncul di stok Web');
        $this->assertEqualsWithDelta(352.0, $webRow['qty'], 0.001);

        // Papan WIP di APK memakai service yang sama -> nilainya wajib identik.
        $api = $this->getJson('/api/wip-board')->assertOk()->json('data.parts');
        $apiRow = collect($api)->firstWhere('part_id', $wipPart->id);

        $this->assertNotNull($apiRow, 'Part WIP harus muncul di Papan WIP APK');
        $this->assertEqualsWithDelta($webRow['qty'], (float) $apiRow['qty'], 0.001);
        $this->assertSame($webRow['uom'], $apiRow['uom']);
    }

    public function test_wip_stock_shows_the_invoices_that_produced_it(): void
    {
        $wipPart = Part::whereRaw('UPPER(part_number) LIKE ?', ['%-WIP%'])->firstOrFail();
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $wo = WorkOrder::create([
            'wo_no' => 'WO-INV-'.uniqid(), 'part_id' => $fg->id,
            'qty' => 500, 'status' => 'in_progress',
        ]);
        $woItem = WorkOrderItem::create([
            'work_order_id' => $wo->id, 'sequence' => 1, 'parent_part_id' => $wipPart->id,
            'child_part_id' => $fg->id, 'uom_rm' => 'KGM', 'qty_required' => 500,
            'qty_consumed' => 0, 'source' => 'Vendor',
        ]);

        $stock = PartStock::create([
            'part_id' => $wipPart->id, 'tag' => 'WO-INV#TEST#R1',
            'qty' => 352, 'qty_unit' => 'PCS', 'received_at' => now(),
        ]);

        $result = ProductionResult::create([
            'work_order_id' => $wo->id, 'parent_part_id' => $wipPart->id,
            'result_date' => now()->toDateString(), 'shift' => '1',
            'qty_good' => 352, 'qty_reject' => 0, 'uom' => 'PCS',
            'label_status' => 'pending', 'output_part_stock_id' => $stock->id,
        ]);

        // Dua nota bahan yang dipakai laporan itu.
        foreach (['HCGCI 26-0901' => 'YONGXIN', 'HCGCI 26-0902' => 'TAIZHOU'] as $inv => $sup) {
            WorkOrderConsumption::create([
                'work_order_id' => $wo->id, 'work_order_item_id' => $woItem->id,
                'part_stock_id' => $stock->id, 'part_id' => $fg->id,
                'qty' => 100, 'uom' => 'KGM', 'production_result_id' => $result->id,
                'invoice_no' => $inv, 'supplier_name' => $sup,
            ]);
        }

        $row = collect(app(WipStockService::class)->current('WIP'))
            ->firstWhere('part_id', $wipPart->id);

        $this->assertNotNull($row);
        $invoices = collect($row['invoices'])->pluck('invoice')->sort()->values()->all();
        $this->assertSame(['HCGCI 26-0901', 'HCGCI 26-0902'], $invoices);
    }

    public function test_movement_tab_keeps_invoice_for_material_consumed_on_a_later_day(): void
    {
        $wipPart = Part::whereRaw('UPPER(part_number) LIKE ?', ['%-WIP%'])->firstOrFail();
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $wo = WorkOrder::create([
            'wo_no' => 'WO-LATE-'.uniqid(), 'part_id' => $fg->id,
            'qty' => 500, 'status' => 'in_progress',
        ]);
        $woItem = WorkOrderItem::create([
            'work_order_id' => $wo->id, 'sequence' => 1, 'machine_id' => $this->machine->id,
            'parent_part_id' => $wipPart->id, 'child_part_id' => $fg->id, 'uom_rm' => 'KGM',
            'qty_required' => 500, 'qty_consumed' => 0, 'source' => 'Vendor',
        ]);

        // Konsumsi terjadi hari ini, nota tersimpan di konsumsi (bukan di penerimaan hari ini).
        $consumption = WorkOrderConsumption::create([
            'work_order_id' => $wo->id, 'work_order_item_id' => $woItem->id,
            'part_stock_id' => 1, 'part_id' => $fg->id, 'qty' => 40, 'uom' => 'KGM',
            'invoice_no' => 'HCGCI 26-0903', 'supplier_name' => 'YONGXIN',
        ]);
        $consumption->forceFill(['created_at' => now()])->saveQuietly();

        $rows = app(WipInventoryService::class)->forDate(now()->toDateString(), $this->machine->id);
        $row = collect($rows)->firstWhere('part_id', $fg->id);

        $this->assertNotNull($row, 'Konsumsi hari ini harus muncul di tab pergerakan');
        $this->assertSame('HCGCI 26-0903', $row['invoice']);
        $this->assertSame('YONGXIN', $row['supplier']);
    }

    public function test_detail_page_breaks_movement_down_per_invoice(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        // Dua penerimaan di hari berbeda -> dua nota berbeda di stok awal.
        $this->receipt(now()->subDays(3)->toDateString(), 500);
        $this->receipt(now()->subDays(2)->toDateString(), 300);

        $detail = app(WipInventoryService::class)
            ->detail($this->machine->id, $this->part->id, now()->toDateString());

        $this->assertEqualsWithDelta(800.0, $detail['totals']['beginning'], 0.001);
        $this->assertCount(2, $detail['beginning'], 'Stok awal harus terpecah per nota');

        $invoices = collect($detail['beginning'])->pluck('invoice')->sort()->values()->all();
        $this->assertSame([
            now()->subDays(3)->toDateString(),
            now()->subDays(2)->toDateString(),
        ], array_map(fn ($i) => substr((string) $i, -10), $invoices));
    }

    public function test_detail_page_is_reachable_and_shows_the_part(): void
    {
        $this->get(route('production-receipts.detail', [
            'machine' => $this->machine->id,
            'part' => $this->part->id,
            'date' => now()->toDateString(),
        ]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Production/Receipt/Detail')
                ->where('detail.part.id', $this->part->id)
                ->has('detail.beginning')
                ->has('detail.received')
                ->has('detail.issued'));
    }

    public function test_range_scopes_movement_and_carries_beginning_balance(): void
    {
        $this->receipt('2026-10-27', 500);
        $this->receipt('2026-10-28', 300);
        $this->consumption('2026-10-29', 100);
        $this->receipt('2026-10-30', 200);
        $this->consumption('2026-10-30', 50);

        $rows = app(WipInventoryService::class)->forRange('2026-10-30', '2026-10-30', $this->machine->id);
        $row = collect($rows)->firstWhere('part_id', $this->part->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(700.0, (float) $row['beginning_qty'], 1e-6);
        $this->assertEqualsWithDelta(200.0, (float) $row['received_qty'], 1e-6);
        $this->assertEqualsWithDelta(50.0, (float) $row['issued_qty'], 1e-6);
        $this->assertEqualsWithDelta(850.0, (float) $row['ending_qty'], 1e-6);
    }

    public function test_range_splits_wip_fg_and_raw_material_categories(): void
    {
        $wipPart = Part::whereHas('partType', fn ($q) => $q->whereRaw('UPPER(code) = ?', ['WIP']))->firstOrFail();

        // WIP muncul dari output produksi; raw material dari transfer ke mesin.
        ProductionResult::create([
            'work_order_id' => $this->wo->id,
            'parent_part_id' => $wipPart->id,
            'machine_id' => $this->machine->id,
            'result_date' => '2026-10-30',
            'shift' => '1',
            'qty_good' => 40,
            'qty_reject' => 0,
            'uom' => 'KGM',
            'label_status' => 'pending',
        ]);
        $this->receiptForPart($this->part, '2026-10-30', 100);

        $service = app(WipInventoryService::class);

        $wipFg = collect($service->forRange('2026-10-30', '2026-10-30', $this->machine->id, WipInventoryService::CATEGORY_WIP_FG));
        $material = collect($service->forRange('2026-10-30', '2026-10-30', $this->machine->id, WipInventoryService::CATEGORY_MATERIAL));

        $this->assertTrue($wipFg->contains('part_id', $wipPart->id));
        $this->assertFalse($wipFg->contains('part_id', $this->part->id));
        $this->assertTrue($material->contains('part_id', $this->part->id));
        $this->assertFalse($material->contains('part_id', $wipPart->id));
    }

    public function test_wip_output_is_attributed_to_the_producing_machine(): void
    {
        $wipPart = Part::whereHas('partType', fn ($q) => $q->whereRaw('UPPER(code) = ?', ['WIP']))->firstOrFail();

        $consumer = Machine::create([
            'machine_code' => 'CONS-'.uniqid(),
            'machine_name' => 'Consuming Machine',
            'is_active' => true,
        ]);

        $stock = PartStock::create([
            'part_id' => $wipPart->id,
            'tag' => 'WIP-OUT-'.uniqid(),
            'qty' => 40,
            'qty_unit' => 'KGM',
            'received_at' => now(),
        ]);

        ProductionResult::create([
            'work_order_id' => $this->wo->id,
            'parent_part_id' => $wipPart->id,
            'machine_id' => $this->machine->id,
            'result_date' => now()->toDateString(),
            'shift' => '1',
            'qty_good' => 40,
            'qty_reject' => 0,
            'uom' => 'KGM',
            'label_status' => 'pending',
            'output_part_stock_id' => $stock->id,
        ]);

        // WIP dipakai di mesin lain; baris tetap milik mesin pembuatnya.
        $consumerItem = WorkOrderItem::create([
            'work_order_id' => $this->wo->id,
            'sequence' => 2,
            'machine_id' => $consumer->id,
            'parent_part_id' => $this->wo->part_id,
            'child_part_id' => $wipPart->id,
            'uom_rm' => 'KGM',
            'qty_required' => 40,
            'qty_consumed' => 0,
        ]);

        WorkOrderConsumption::create([
            'work_order_id' => $this->wo->id,
            'work_order_item_id' => $consumerItem->id,
            'part_stock_id' => $stock->id,
            'part_id' => $wipPart->id,
            'qty' => 10,
            'uom' => 'KGM',
        ]);

        $rows = app(WipInventoryService::class)
            ->forRange(now()->toDateString(), now()->toDateString(), $this->machine->id, WipInventoryService::CATEGORY_WIP_FG);
        $row = collect($rows)->firstWhere('part_id', $wipPart->id);

        $this->assertNotNull($row);
        $this->assertSame($this->machine->id, $row['machine_id'], 'Baris WIP harus milik mesin pembuat');
        $this->assertEqualsWithDelta(40.0, (float) $row['received_qty'], 1e-6);
        $this->assertEqualsWithDelta(10.0, (float) $row['issued_qty'], 1e-6);
        $this->assertEqualsWithDelta(30.0, (float) $row['ending_qty'], 1e-6);
    }

    public function test_fg_production_output_is_received_with_zero_issue(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        ProductionResult::create([
            'work_order_id' => $this->wo->id,
            'parent_part_id' => $fg->id,
            'machine_id' => $this->machine->id,
            'result_date' => '2026-10-30',
            'shift' => '1',
            'qty_good' => 250,
            'qty_reject' => 0,
            'uom' => 'PCS',
            'label_status' => 'pending',
        ]);

        $rows = app(WipInventoryService::class)
            ->forRange('2026-10-30', '2026-10-30', $this->machine->id, WipInventoryService::CATEGORY_WIP_FG);
        $row = collect($rows)->firstWhere('part_id', $fg->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(250.0, (float) $row['received_qty'], 1e-6);
        $this->assertEqualsWithDelta(0.0, (float) $row['issued_qty'], 1e-6);
        $this->assertEqualsWithDelta(250.0, (float) $row['ending_qty'], 1e-6);
        $this->assertSame('FG', $row['part_type']);
    }

    public function test_rows_with_only_beginning_stock_are_listed(): void
    {
        $this->receipt('2026-10-29', 400);

        $rows = app(WipInventoryService::class)->forRange('2026-10-30', '2026-10-30', $this->machine->id);
        $row = collect($rows)->firstWhere('part_id', $this->part->id);

        $this->assertNotNull($row, 'Baris dengan stok awal saja harus tetap tampil');
        $this->assertEqualsWithDelta(400.0, (float) $row['beginning_qty'], 1e-6);
        $this->assertEqualsWithDelta(0.0, (float) $row['received_qty'], 1e-6);
        $this->assertEqualsWithDelta(0.0, (float) $row['issued_qty'], 1e-6);
        $this->assertEqualsWithDelta(400.0, (float) $row['ending_qty'], 1e-6);
    }

    public function test_index_page_exposes_two_tabs_and_period_filters(): void
    {
        $this->receipt('2026-10-30', 100);

        $this->get(route('production-receipts.index', [
            'tab' => WipInventoryService::CATEGORY_MATERIAL,
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Production/Receipt/Index')
                ->where('tab', WipInventoryService::CATEGORY_MATERIAL)
                ->where('from', '2026-10-01')
                ->where('to', '2026-10-31')
                ->has('tabs', 2)
                ->has('rows')
                ->missing('stockRows'));
    }

    public function test_detail_accepts_a_date_range(): void
    {
        $this->receipt('2026-10-28', 100);
        $this->receipt('2026-10-30', 50);

        $this->get(route('production-receipts.detail', [
            'machine' => $this->machine->id,
            'part' => $this->part->id,
            'from' => '2026-10-28',
            'to' => '2026-10-30',
        ]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Production/Receipt/Detail')
                ->where('detail.from', '2026-10-28')
                ->where('detail.to', '2026-10-30')
                ->where('detail.totals.received', 150));
    }

    public function test_tpl_final_step_reported_directly_still_lands_on_the_producing_machine(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $rm = Part::where('part_number', 'CBKG07256C')->firstOrFail();
        $wip1 = Part::where('part_number', 'AAN30056405-WIP1')->firstOrFail();
        $wip2 = Part::where('part_number', 'AAN30056405-WIP2')->firstOrFail();
        $wip3 = Part::where('part_number', 'AAN30056405-WIP3')->firstOrFail();

        $tpl = Machine::firstOrCreate(
            ['machine_code' => 'TPL_DONGSHIN'],
            ['machine_name' => 'TPL DONGSHIN', 'is_active' => true],
        );
        $assy = Machine::firstOrCreate(
            ['machine_code' => 'ASSY_TPL_DONGSHIN'],
            ['machine_name' => 'ASSY. TPL DONGSHIN', 'is_active' => true],
        );
        $tpl->forceFill(['is_active' => true])->save();
        $assy->forceFill(['is_active' => true])->save();

        $wo = WorkOrder::create([
            'wo_no' => 'WO-TPL-'.uniqid(),
            'part_id' => $fg->id,
            'qty' => 10,
            'status' => 'in_progress',
        ]);

        // Rantai TPL: WIP1 <- RM, WIP2 <- WIP1, WIP3 <- WIP2, lalu FG <- WIP3 di ASSY.
        $item1 = WorkOrderItem::create([
            'work_order_id' => $wo->id, 'sequence' => 1, 'machine_id' => $tpl->id,
            'parent_part_id' => $wip1->id, 'parent_qty' => 1, 'parent_uom' => 'PCS',
            'child_part_id' => $rm->id, 'uom_rm' => 'KGM', 'child_qty' => 1, 'qty_required' => 10,
        ]);
        $item2 = WorkOrderItem::create([
            'work_order_id' => $wo->id, 'sequence' => 2, 'machine_id' => $tpl->id,
            'parent_part_id' => $wip2->id, 'parent_qty' => 1, 'parent_uom' => 'PCS',
            'child_part_id' => $wip1->id, 'uom_rm' => 'PCS', 'child_qty' => 1, 'qty_required' => 10,
        ]);
        WorkOrderItem::create([
            'work_order_id' => $wo->id, 'sequence' => 3, 'machine_id' => $tpl->id,
            'parent_part_id' => $wip3->id, 'parent_qty' => 1, 'parent_uom' => 'PCS',
            'child_part_id' => $wip2->id, 'uom_rm' => 'PCS', 'child_qty' => 1, 'qty_required' => 10,
        ]);
        WorkOrderItem::create([
            'work_order_id' => $wo->id, 'sequence' => 4, 'machine_id' => $assy->id,
            'parent_part_id' => $fg->id, 'parent_qty' => 1, 'parent_uom' => 'PCS',
            'child_part_id' => $wip3->id, 'uom_rm' => 'PCS', 'child_qty' => 1, 'qty_required' => 10,
        ]);

        // Raw material sudah diterima di TPL.
        $issue = MaterialIssue::create([
            'issue_no' => 'MI-TPL-'.uniqid(), 'work_order_id' => $wo->id,
            'issue_date' => now()->toDateString(), 'status' => 'posted',
        ]);
        $issueItem = MaterialIssueItem::create([
            'material_issue_id' => $issue->id, 'work_order_item_id' => $item1->id,
            'part_id' => $rm->id, 'tag' => 'T-TPL-'.uniqid(), 'qty' => 10, 'uom' => 'KGM',
        ]);
        ProductionMaterialReceipt::create([
            'material_issue_item_id' => $issueItem->id, 'tag' => $issueItem->tag,
            'part_id' => $rm->id, 'machine_id' => $tpl->id,
            'work_order_id' => $wo->id, 'work_order_item_id' => $item1->id,
            'received_at' => now(), 'qty' => 10, 'qty_consumed' => 0,
            'uom' => 'KGM', 'transfer_status' => 'transferred',
        ]);

        $actorId = (int) User::where('email', 'admin@geumcheon.local')->value('id');
        $service = app(ProductionResultService::class);

        // TPL melaporkan step terakhir (WIP3) saja; raw material dikonsumsi langsung.
        $service->report($wo, $wip3->id, [
            'qty_good' => 10, 'qty_reject' => 0, 'machine_id' => $tpl->id,
            'result_date' => now()->toDateString(), 'shift' => '1',
        ], $actorId);

        // ASSY memakai WIP3 untuk menghasilkan FG.
        $service->report($wo, $fg->id, [
            'qty_good' => 10, 'qty_reject' => 0, 'machine_id' => $assy->id,
            'result_date' => now()->toDateString(), 'shift' => '1',
        ], $actorId);

        $today = now()->toDateString();
        $wipRows = app(WipInventoryService::class)
            ->forRange($today, $today, $tpl->id, WipInventoryService::CATEGORY_WIP_FG);

        $wipRow = collect($wipRows)->firstWhere('part_id', $wip3->id);
        $this->assertNotNull($wipRow);
        $this->assertSame($tpl->id, $wipRow['machine_id']);
        $this->assertEqualsWithDelta(10.0, (float) $wipRow['received_qty'], 1e-6);
        $this->assertEqualsWithDelta(10.0, (float) $wipRow['issued_qty'], 1e-6);
        $this->assertEqualsWithDelta(0.0, (float) $wipRow['ending_qty'], 1e-6);

        // Step perantara yang tidak dilaporkan tidak muncul sebagai baris stok.
        $this->assertFalse(collect($wipRows)->contains('part_id', $wip1->id));
        $this->assertFalse(collect($wipRows)->contains('part_id', $wip2->id));

        // FG lahir di ASSY.
        $fgRows = app(WipInventoryService::class)
            ->forRange($today, $today, $assy->id, WipInventoryService::CATEGORY_WIP_FG);
        $fgRow = collect($fgRows)->firstWhere('part_id', $fg->id);
        $this->assertNotNull($fgRow);
        $this->assertEqualsWithDelta(10.0, (float) $fgRow['received_qty'], 1e-6);
        $this->assertEqualsWithDelta(0.0, (float) $fgRow['issued_qty'], 1e-6);

        // Raw material yang dikonsumsi langsung di TPL masuk tab Raw Material.
        $rmRows = app(WipInventoryService::class)
            ->forRange($today, $today, $tpl->id, WipInventoryService::CATEGORY_MATERIAL);
        $rmRow = collect($rmRows)->firstWhere('part_id', $rm->id);
        $this->assertNotNull($rmRow);
        $this->assertEqualsWithDelta(10.0, (float) $rmRow['received_qty'], 1e-6);
        $this->assertEqualsWithDelta(10.0, (float) $rmRow['issued_qty'], 1e-6);
        $this->assertEqualsWithDelta(0.0, (float) $rmRow['ending_qty'], 1e-6);
    }

    public function test_backfill_fills_invoice_on_consumptions_that_predate_the_fix(): void
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        // Konsumsi lama: punya receipt, tapi invoice belum tersalin.
        $receipt = ProductionMaterialReceipt::create([
            'material_issue_item_id' => MaterialIssueItem::create([
                'material_issue_id' => MaterialIssue::create([
                    'issue_no' => 'MI-BF-'.uniqid(), 'work_order_id' => $this->wo->id,
                    'issue_date' => now()->toDateString(), 'status' => 'posted',
                ])->id,
                'work_order_item_id' => $this->item->id, 'part_id' => $this->part->id,
                'tag' => 'BF-1', 'qty' => 100, 'uom' => 'KGM',
            ])->id,
            'work_order_id' => $this->wo->id, 'work_order_item_id' => $this->item->id,
            'part_id' => $this->part->id, 'part_stock_id' => 1, 'machine_id' => $this->machine->id,
            'qty' => 100, 'qty_consumed' => 40, 'uom' => 'KGM', 'transfer_status' => 'transferred',
            'invoice' => 'HCGCI 26-0901', 'supplier' => 'YONGXIN', 'received_at' => now(),
        ]);

        $consumption = WorkOrderConsumption::create([
            'work_order_id' => $this->wo->id, 'work_order_item_id' => $this->item->id,
            'part_stock_id' => 1, 'part_id' => $this->part->id, 'qty' => 40, 'uom' => 'KGM',
            'production_material_receipt_id' => $receipt->id,
        ]);
        $this->assertNull($consumption->fresh()->invoice_no, 'Awalnya kosong, seperti data lama');

        // Jalankan ulang backfill (idempoten).
        $migration = require database_path('migrations/2026_11_05_090000_backfill_invoice_on_old_consumptions.php');
        $migration->up();

        $fresh = $consumption->fresh();
        $this->assertSame('HCGCI 26-0901', $fresh->invoice_no);
        $this->assertSame('YONGXIN', $fresh->supplier_name);
    }
}
