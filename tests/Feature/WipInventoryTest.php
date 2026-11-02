<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\ProductionMaterialReceipt;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderConsumption;
use App\Models\WorkOrderItem;
use App\Services\WipInventoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
