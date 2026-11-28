<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Machine;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PartType;
use App\Models\Process;
use App\Models\ProductionPlanItem;
use App\Models\ProductionResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderConsumption;
use App\Models\WorkOrderItem;
use App\Services\ProductionReceiptService;
use App\Services\ProductionResultService;
use App\Services\WoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SharedOutputAndByproductBomTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Machine $tplMachine;

    private Process $pressProcess;

    private int $wipTypeId;

    private int $fgTypeId;

    private int $matTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);

        $this->tplMachine = Machine::whereRaw("UPPER(machine_name) LIKE 'TPL%'")
            ->where('is_active', true)
            ->firstOrFail();
        $this->pressProcess = Process::firstOrFail();
        $this->fgTypeId = (int) PartType::whereRaw("LOWER(code) = 'fg'")->value('id');
        $this->matTypeId = (int) PartType::whereRaw("LOWER(code) = 'material'")->value('id');
        $this->wipTypeId = (int) PartType::whereRaw("LOWER(code) = 'wip'")->value('id');
    }

    private function makePart(string $prefix, int $typeId): Part
    {
        return Part::create([
            'part_number' => $prefix.'-'.uniqid(),
            'part_name' => $prefix,
            'part_type_id' => $typeId,
            'is_active' => true,
        ]);
    }

    public function test_shared_output_bom_creates_single_input_demand_posts_both_wips_and_reverses_atomically(): void
    {
        $blankPart = $this->makePart('BLANK-OMEGA-A', $this->wipTypeId);
        $coil = $this->makePart('COIL-OMEGA', $this->matTypeId);
        $wip1 = $this->makePart('WIP1', $this->wipTypeId);
        $wip2 = $this->makePart('WIP2', $this->wipTypeId);
        $wip3 = $this->makePart('WIP3', $this->wipTypeId);
        $wip4Right = $this->makePart('WIP4-R', $this->wipTypeId);
        $wip4Left = $this->makePart('WIP4-L', $this->wipTypeId);

        // Master BOM menerima part WIP + output_type 'shared'.
        $this->post(route('boms.store'), [
            'part_id' => $blankPart->id,
            'version' => '1.0',
            'items' => [
                [
                    'sequence' => 1,
                    'process_id' => $this->pressProcess->id,
                    'machine_id' => $this->tplMachine->id,
                    'parent_part_id' => $wip1->id,
                    'parent_qty' => 1,
                    'parent_uom' => 'PCS',
                    'child_part_id' => $coil->id,
                    'child_qty' => 0.258,
                    'uom_rm' => 'KGM',
                    'source' => 'Vendor',
                    'output_type' => BomItem::OUTPUT_NORMAL,
                ],
                [
                    'sequence' => 2,
                    'process_id' => $this->pressProcess->id,
                    'machine_id' => $this->tplMachine->id,
                    'parent_part_id' => $wip2->id,
                    'parent_qty' => 1,
                    'parent_uom' => 'PCS',
                    'child_part_id' => $wip1->id,
                    'child_qty' => 1,
                    'uom_rm' => 'PCS',
                    'source' => 'PROD',
                    'output_type' => BomItem::OUTPUT_NORMAL,
                ],
                [
                    'sequence' => 3,
                    'process_id' => $this->pressProcess->id,
                    'machine_id' => $this->tplMachine->id,
                    'parent_part_id' => $wip3->id,
                    'parent_qty' => 1,
                    'parent_uom' => 'PCS',
                    'child_part_id' => $wip2->id,
                    'child_qty' => 1,
                    'uom_rm' => 'PCS',
                    'source' => 'PROD',
                    'output_type' => BomItem::OUTPUT_NORMAL,
                ],
                [
                    'sequence' => 4,
                    'process_id' => $this->pressProcess->id,
                    'machine_id' => $this->tplMachine->id,
                    'parent_part_id' => $wip4Right->id,
                    'parent_qty' => 1,
                    'parent_uom' => 'PCS',
                    'child_part_id' => $wip3->id,
                    'child_qty' => 1,
                    'uom_rm' => 'PCS',
                    'source' => 'PROD',
                    'output_type' => BomItem::OUTPUT_SHARED,
                ],
                [
                    'sequence' => 4,
                    'process_id' => $this->pressProcess->id,
                    'machine_id' => $this->tplMachine->id,
                    'parent_part_id' => $wip4Left->id,
                    'parent_qty' => 1,
                    'parent_uom' => 'PCS',
                    'child_part_id' => $wip3->id,
                    'child_qty' => 1,
                    'uom_rm' => 'PCS',
                    'source' => 'PROD',
                    'output_type' => BomItem::OUTPUT_SHARED,
                ],
            ],
        ])->assertRedirect();

        $bom = Bom::where('part_id', $blankPart->id)->firstOrFail();
        $this->assertSame(5, $bom->items()->count());

        // Stok coil cukup untuk 1.000 blank (258 KGM).
        $coilStock = PartStock::create([
            'part_id' => $coil->id,
            'tag' => 'TAG-COIL-'.uniqid(),
            'qty' => 300,
            'qty_unit' => 'KGM',
            'received_at' => now(),
        ]);

        // WorkOrderController juga mengizinkan part WIP yang punya BOM aktif.
        $this->post(route('work-orders.store'), [
            'part_id' => $blankPart->id,
            'qty' => 1000,
        ])->assertRedirect();

        $wo = WorkOrder::where('part_id', $blankPart->id)->latest('id')->firstOrFail();

        // Total kebutuhan coil tepat 258 KGM (1× stroke, bukan 516 KGM!).
        $coilItem = WorkOrderItem::where('work_order_id', $wo->id)
            ->where('child_part_id', $coil->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(258.0, (float) $coilItem->qty_required, 0.0001);

        // Hanya baris pertama pada grup shared yang memegang qty_required WIP3.
        $sharedRows = WorkOrderItem::where('work_order_id', $wo->id)
            ->where('sequence', 4)
            ->orderBy('id')
            ->get();
        $this->assertEqualsWithDelta(1000.0, (float) $sharedRows[0]->qty_required, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $sharedRows[1]->qty_required, 0.0001);

        // Release WO + attach ke Production Plan -> hanya membuat 1 baris untuk grup TPL!
        $woService = app(WoService::class);
        $woService->releaseWorkOrder($wo->fresh(), $this->admin->id);
        $createdPlanRows = $woService->populatePlanItems($wo->fresh(), now()->toDateString(), $this->admin->id);

        $this->assertSame(1, $createdPlanRows);
        $this->assertSame(1, ProductionPlanItem::where('work_order_id', $wo->id)->count());

        $issue = MaterialIssue::create([
            'issue_no' => 'MI-SH-'.uniqid(),
            'work_order_id' => $wo->id,
            'issue_date' => now()->toDateString(),
            'status' => 'posted',
            'created_by' => $this->admin->id,
        ]);
        $issueItem = MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $coilItem->id,
            'part_id' => $coil->id,
            'part_stock_id' => $coilStock->id,
            'tag' => $coilStock->tag,
            'qty' => 258,
            'uom' => 'KGM',
        ]);
        app(ProductionReceiptService::class)->confirm(null, (int) $this->tplMachine->id, materialIssueItemId: $issueItem->id);

        // Lapor hasil 1.000 pada WIP4-R -> konsumsi 258 KGM coil 1x & lahir stok WIP4-R (1000) + WIP4-L (1000)!
        $resultService = app(ProductionResultService::class);
        $primaryResult = $resultService->report($wo->fresh(), $wip4Right->id, [
            'qty_good' => 1000,
            'qty_reject' => 0,
            'result_date' => now()->toDateString(),
            'machine_id' => $this->tplMachine->id,
        ], $this->admin->id);

        $coilConsumed = (float) WorkOrderConsumption::where('work_order_id', $wo->id)
            ->where('part_id', $coil->id)
            ->sum('qty');
        $this->assertEqualsWithDelta(258.0, $coilConsumed, 0.001);

        $rightStock = (float) PartStock::where('part_id', $wip4Right->id)->sum('qty');
        $leftStock = (float) PartStock::where('part_id', $wip4Left->id)->sum('qty');
        $this->assertEqualsWithDelta(1000.0, $rightStock, 0.001);
        $this->assertEqualsWithDelta(1000.0, $leftStock, 0.001);

        $companionResult = ProductionResult::where('parent_result_id', $primaryResult->id)->firstOrFail();
        $this->assertSame($wip4Left->id, (int) $companionResult->parent_part_id);

        // Membatalkan hasil pendamping langsung ditolak; harus lewat hasil utama.
        $this->expectException(ValidationException::class);
        try {
            $resultService->reverse($wo->fresh(), $companionResult, $this->admin->id);
        } finally {
            // Membatalkan hasil utama membalik kedua stok WIP dan konsumsi coil.
            $resultService->reverse($wo->fresh(), $primaryResult->fresh(), $this->admin->id);
            $this->assertEqualsWithDelta(0.0, (float) PartStock::where('part_id', $wip4Right->id)->sum('qty'), 0.001);
            $this->assertEqualsWithDelta(0.0, (float) PartStock::where('part_id', $wip4Left->id)->sum('qty'), 0.001);
            $this->assertEqualsWithDelta(0.0, (float) WorkOrderConsumption::where('work_order_id', $wo->id)->sum('qty'), 0.001);
        }
    }

    public function test_byproduct_row_outputs_secondary_part_without_extra_material_consumption(): void
    {
        $fgMain = $this->makePart('FG-MAIN-SCRAP', $this->fgTypeId);
        $fgScrap = $this->makePart('FG-BYPROD-SCRAP', $this->fgTypeId);
        $coil = $this->makePart('COIL-SCRAP', $this->matTypeId);

        $bom = Bom::create([
            'part_id' => $fgMain->id,
            'bom_no' => Bom::generateBomNo(),
            'version' => '1.0',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        BomItem::create([
            'bom_id' => $bom->id,
            'sequence' => 1,
            'process_id' => $this->pressProcess->id,
            'machine_id' => $this->tplMachine->id,
            'parent_part_id' => $fgMain->id,
            'parent_qty' => 1,
            'parent_uom' => 'PCS',
            'child_part_id' => $coil->id,
            'child_qty' => 1.0,
            'uom_rm' => 'KGM',
            'source' => 'Vendor',
            'output_type' => BomItem::OUTPUT_NORMAL,
            'is_active' => true,
        ]);
        BomItem::create([
            'bom_id' => $bom->id,
            'sequence' => 1,
            'process_id' => $this->pressProcess->id,
            'machine_id' => $this->tplMachine->id,
            'parent_part_id' => $fgScrap->id,
            'parent_qty' => 0.5,
            'parent_uom' => 'PCS',
            'child_part_id' => $coil->id,
            'child_qty' => 0,
            'uom_rm' => 'KGM',
            'source' => 'PROD',
            'output_type' => BomItem::OUTPUT_BYPRODUCT,
            'is_active' => true,
        ]);

        $coilStock = PartStock::create([
            'part_id' => $coil->id,
            'tag' => 'TAG-BYP-'.uniqid(),
            'qty' => 150,
            'qty_unit' => 'KGM',
            'received_at' => now(),
        ]);

        [$woId] = app(WoService::class)->createWorkOrder($fgMain->id, 100, null, null, $this->admin->id);
        $wo = WorkOrder::findOrFail($woId);
        app(WoService::class)->releaseWorkOrder($wo, $this->admin->id);

        $coilItem = WorkOrderItem::where('work_order_id', $wo->id)
            ->where('output_type', BomItem::OUTPUT_NORMAL)
            ->firstOrFail();

        $issue = MaterialIssue::create([
            'issue_no' => 'MI-BYP-'.uniqid(),
            'work_order_id' => $wo->id,
            'issue_date' => now()->toDateString(),
            'status' => 'posted',
            'created_by' => $this->admin->id,
        ]);
        $issueItem = MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $coilItem->id,
            'part_id' => $coil->id,
            'part_stock_id' => $coilStock->id,
            'tag' => $coilStock->tag,
            'qty' => 100,
            'uom' => 'KGM',
        ]);
        app(ProductionReceiptService::class)->confirm(null, (int) $this->tplMachine->id, materialIssueItemId: $issueItem->id);

        app(ProductionResultService::class)->report($wo->fresh(), $fgMain->id, [
            'qty_good' => 100,
            'qty_reject' => 0,
            'result_date' => now()->toDateString(),
            'machine_id' => $this->tplMachine->id,
        ], $this->admin->id);

        $this->assertEqualsWithDelta(100.0, (float) PartStock::where('part_id', $fgMain->id)->sum('qty'), 0.001);
        $this->assertEqualsWithDelta(50.0, (float) PartStock::where('part_id', $fgScrap->id)->sum('qty'), 0.001);
        $this->assertEqualsWithDelta(100.0, (float) WorkOrderConsumption::where('work_order_id', $wo->id)->sum('qty'), 0.001);
    }
}
