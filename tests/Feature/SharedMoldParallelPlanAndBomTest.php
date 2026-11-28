<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PartSubstitute;
use App\Models\ProductionMaterialReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderConsumption;
use App\Models\WorkOrderMaterialBooking;
use App\Services\ProductionPlanScheduleService;
use App\Services\ProductionReceiptService;
use App\Services\ProductionResultService;
use App\Services\WoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SharedMoldParallelPlanAndBomTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_reinforce_shared_mold_bom_uses_shared_blank_for_wo_requirement_and_consumption(): void
    {
        $blankPart = Part::where('part_number', 'AHF73710119A')->firstOrFail();
        $leftFg = Part::where('part_number', 'AHF73710119')->firstOrFail();
        $rightFg = Part::where('part_number', 'AHF73710120')->firstOrFail();

        $blankBom = Bom::where('part_id', $blankPart->id)->where('is_active', true)->firstOrFail();
        $leftBom = Bom::where('part_id', $leftFg->id)->where('is_active', true)->firstOrFail();
        $rightBom = Bom::where('part_id', $rightFg->id)->where('is_active', true)->firstOrFail();

        $this->assertSame(5, (int) $leftBom->items()->min('sequence'));
        $this->assertSame(5, (int) $rightBom->items()->min('sequence'));

        $blankStep1 = $blankBom->items()->where('sequence', 1)->firstOrFail();
        $blankSeq4Rows = $blankBom->items()->where('sequence', 4)->orderBy('id')->get();
        $this->assertCount(2, $blankSeq4Rows);
        $this->assertSame('shared', $blankSeq4Rows[0]->output_type);
        $this->assertSame('shared', $blankSeq4Rows[1]->output_type);
        $this->assertEqualsWithDelta(1.0, (float) $blankStep1->parent_qty, 0.0001);

        /** @var WoService $woService */
        $woService = app(WoService::class);
        [$blankWoId] = $woService->createWorkOrder($blankPart->id, 5000, now()->toDateString(), null, $this->admin->id);
        $blankWo = WorkOrder::findOrFail($blankWoId);

        $woStep1 = $blankWo->items()->where('sequence', 1)->firstOrFail();

        // 5000 strokes × 0.384 KGM = 1920 KGM (1× coil, tidak dobel untuk L+R).
        $this->assertEqualsWithDelta(1920.0, (float) $woStep1->qty_required, 0.001);

        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $subPart = PartSubstitute::query()
            ->where('part_id', $woStep1->child_part_id)
            ->where('is_active', true)
            ->first()?->substitutePart;

        if ($subPart === null) {
            $subPart = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['material']))
                ->where('id', '!=', $woStep1->child_part_id)
                ->firstOrFail();
            PartSubstitute::create([
                'part_id' => $woStep1->child_part_id,
                'substitute_part_id' => $subPart->id,
                'supplier_id' => $supplier->id,
                'is_active' => true,
            ]);
        }

        PartStock::create([
            'part_id' => $subPart->id,
            'tag' => 'TAG-RF-MOLD-'.$woStep1->id.'-'.uniqid(),
            'qty' => 20000,
            'qty_unit' => $woStep1->uom_rm ?: 'KGM',
            'received_at' => now(),
            'status' => 'available',
        ]);
        $woStep1->update(['selected_part_id' => $subPart->id]);

        $woService->releaseWorkOrder($blankWo->fresh());

        $booking = WorkOrderMaterialBooking::query()
            ->where('work_order_id', $blankWo->id)
            ->where('work_order_item_id', $woStep1->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(1920.0, (float) $booking->qty, 0.001);

        $issue = MaterialIssue::create([
            'issue_no' => 'MI-RF-MOLD-'.uniqid(),
            'work_order_id' => $blankWo->id,
            'issue_date' => now(),
            'status' => 'posted',
        ]);
        $issueItem = MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $woStep1->id,
            'part_id' => $booking->part_id,
            'part_stock_id' => $booking->part_stock_id,
            'tag' => $booking->tag,
            'qty' => $booking->qty,
            'uom' => $booking->uom,
        ]);
        app(ProductionReceiptService::class)->confirm(null, (int) $woStep1->machine_id, materialIssueItemId: $issueItem->id);

        $wip4Step = $blankWo->items()->where('sequence', 4)->firstOrFail();

        /** @var ProductionResultService $resultService */
        $resultService = app(ProductionResultService::class);
        $resultService->report($blankWo->fresh(), (int) $wip4Step->parent_part_id, [
            'qty_good' => 1000,
            'qty_reject' => 0,
            'result_date' => now()->toDateString(),
            'machine_id' => $wip4Step->machine_id,
        ], $this->admin->id);

        $consumed = (float) WorkOrderConsumption::query()
            ->where('work_order_id', $blankWo->id)
            ->where('part_id', $subPart->id)
            ->sum('qty');
        $receiptConsumed = (float) ProductionMaterialReceipt::query()
            ->where('work_order_id', $blankWo->id)
            ->where('part_id', $subPart->id)
            ->sum('qty_consumed');

        $this->assertEqualsWithDelta(384.0, $consumed, 0.001);
        $this->assertEqualsWithDelta(384.0, $receiptConsumed, 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) PartStock::where('part_id', Part::where('part_number', 'AHF73710119-WIP4')->value('id'))->sum('qty'), 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) PartStock::where('part_id', Part::where('part_number', 'AHF73710120-WIP4')->value('id'))->sum('qty'), 0.001);
    }

    public function test_same_sequence_entries_run_in_parallel_with_max_duration_and_single_qdc(): void
    {
        /** @var ProductionPlanScheduleService $scheduleService */
        $scheduleService = app(ProductionPlanScheduleService::class);
        $date = '2026-11-30';

        $single = $scheduleService->calculateDaySchedule($date, [
            [
                'key' => 'left',
                'sequence' => 1,
                'id' => 1,
                'qty' => 5000,
                'wip_part_id' => 101,
                'cycle_time_seconds' => 6.7,
                'qdc_minutes' => 25.0,
                'apply_qdc' => true,
            ],
        ], false, 3);

        $parallel = $scheduleService->calculateDaySchedule($date, [
            [
                'key' => 'left',
                'sequence' => 1,
                'id' => 1,
                'qty' => 5000,
                'wip_part_id' => 101,
                'cycle_time_seconds' => 6.7,
                'qdc_minutes' => 25.0,
                'apply_qdc' => true,
            ],
            [
                'key' => 'right',
                'sequence' => 1,
                'id' => 2,
                'qty' => 5000,
                'wip_part_id' => 102,
                'cycle_time_seconds' => 6.7,
                'qdc_minutes' => 25.0,
                'apply_qdc' => true,
            ],
            [
                'key' => 'next_seq',
                'sequence' => 2,
                'id' => 3,
                'qty' => 1000,
                'wip_part_id' => 103,
                'cycle_time_seconds' => 6.0,
                'qdc_minutes' => 20.0,
                'apply_qdc' => true,
            ],
        ], false, 3);

        // Waktu selesai Left & Right pada Seq 1 sama persis dengan saat hanya 1 part (tidak dobel!).
        $this->assertSame($single['left']['start_time'], $parallel['left']['start_time']);
        $this->assertSame($single['left']['end_time'], $parallel['left']['end_time']);
        $this->assertSame($parallel['left']['start_time'], $parallel['right']['start_time']);
        $this->assertSame($parallel['left']['end_time'], $parallel['right']['end_time']);
        $this->assertEqualsWithDelta(25.0, (float) $parallel['left']['qdc_applied_minutes'], 0.01);
        $this->assertEqualsWithDelta(25.0, (float) $parallel['right']['qdc_applied_minutes'], 0.01);
        $this->assertFalse($parallel['left']['is_overload']);
        $this->assertFalse($parallel['right']['is_overload']);

        // Seq 2 mulai setelah Seq 1 selesai (bukan setelah 2x durasi Seq 1).
        $this->assertNotNull($parallel['next_seq']['start_time']);
        $this->assertSame($parallel['left']['end_time'], $parallel['next_seq']['start_time']);
    }

    public function test_asymmetric_parallel_entries_advance_next_sequence_after_longest_part(): void
    {
        /** @var ProductionPlanScheduleService $scheduleService */
        $scheduleService = app(ProductionPlanScheduleService::class);
        $date = '2026-11-30';

        $schedule = $scheduleService->calculateDaySchedule($date, [
            [
                'key' => 'long_part',
                'sequence' => 1,
                'id' => 1,
                'qty' => 3000,
                'wip_part_id' => 201,
                'cycle_time_seconds' => 6.0,
                'qdc_minutes' => 25.0,
                'apply_qdc' => false,
            ],
            [
                'key' => 'short_part',
                'sequence' => 1,
                'id' => 2,
                'qty' => 1000,
                'wip_part_id' => 202,
                'cycle_time_seconds' => 6.0,
                'qdc_minutes' => 25.0,
                'apply_qdc' => false,
            ],
            [
                'key' => 'seq2',
                'sequence' => 2,
                'id' => 3,
                'qty' => 600,
                'wip_part_id' => 203,
                'cycle_time_seconds' => 6.0,
                'qdc_minutes' => 0.0,
                'apply_qdc' => false,
            ],
        ], false, 3);

        $this->assertSame($schedule['long_part']['start_time'], $schedule['short_part']['start_time']);
        $this->assertNotSame($schedule['long_part']['end_time'], $schedule['short_part']['end_time']);
        // Seq 2 mulai setelah part yang paling lama selesai (long_part), bukan short_part.
        $this->assertSame($schedule['long_part']['end_time'], $schedule['seq2']['start_time']);
    }
}
