<?php

namespace Tests\Feature;

use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\MaterialIssueSummaryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MaterialIssueSummaryTest extends TestCase
{
    use DatabaseTransactions;

    private WorkOrder $wo;

    private Part $mainPart;

    private Part $auxPart;

    private WorkOrderItem $mainItem;

    private WorkOrderItem $auxItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());

        $this->mainPart = Part::where('part_number', 'CBKG07256C')->firstOrFail();
        $this->auxPart = Part::where('part_number', 'PINCB01')->firstOrFail();

        $this->wo = WorkOrder::create([
            'wo_no' => 'WO-MIS-'.uniqid(),
            'part_id' => Part::where('part_number', 'AAN30056405')->value('id'),
            'qty' => 100,
            'status' => 'in_progress',
        ]);

        $this->mainItem = WorkOrderItem::create([
            'work_order_id' => $this->wo->id,
            'sequence' => 1,
            'parent_part_id' => $this->wo->part_id,
            'parent_qty' => 1,
            'parent_uom' => 'PCS',
            'child_part_id' => $this->mainPart->id,
            'selected_part_id' => $this->mainPart->id,
            'child_qty' => 1,
            'uom_rm' => 'KGM',
            'qty_required' => 100,
        ]);

        $this->auxItem = WorkOrderItem::create([
            'work_order_id' => $this->wo->id,
            'sequence' => 2,
            'parent_part_id' => $this->wo->part_id,
            'parent_qty' => 1,
            'parent_uom' => 'PCS',
            'child_part_id' => $this->auxPart->id,
            'selected_part_id' => $this->auxPart->id,
            'child_qty' => 1,
            'uom_rm' => 'PCS',
            'qty_required' => 20,
        ]);
    }

    private function issue(string $issueNo, string $date, array $lines): MaterialIssue
    {
        $issue = MaterialIssue::create([
            'issue_no' => $issueNo,
            'work_order_id' => $this->wo->id,
            'issue_date' => $date,
            'status' => 'posted',
        ]);

        foreach ($lines as [$item, $part, $qty, $uom, $tag]) {
            MaterialIssueItem::create([
                'material_issue_id' => $issue->id,
                'work_order_item_id' => $item->id,
                'part_id' => $part->id,
                'tag' => $tag,
                'qty' => $qty,
                'uom' => $uom,
                'invoice' => 'INV-'.$tag,
                'supplier' => 'Supplier '.$tag,
            ]);
        }

        return $issue;
    }

    public function test_multiple_slips_of_one_work_order_collapse_into_single_row(): void
    {
        $this->issue('MI-A', '2026-10-29', [[$this->mainItem, $this->mainPart, 40, 'KGM', 'T1']]);
        $this->issue('MI-B', '2026-10-29', [[$this->mainItem, $this->mainPart, 35, 'KGM', 'T2']]);

        $rows = app(MaterialIssueSummaryService::class)->byWorkOrder('2026-10-29', '2026-10-29');

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($this->wo->wo_no, $row['wo_no']);
        $this->assertSame(2, $row['slip_count']);
    }

    public function test_status_compares_issued_qty_against_total_required(): void
    {
        $this->issue('MI-C', '2026-10-29', [
            [$this->mainItem, $this->mainPart, 50, 'KGM', 'T3'],
            [$this->auxItem, $this->auxPart, 5, 'PCS', 'T4'],
        ]);

        $row = app(MaterialIssueSummaryService::class)->byWorkOrder('2026-10-29', '2026-10-29')[0];

        $status = collect($row['status_by_uom']);

        $this->assertEqualsWithDelta(50.0, (float) $status->firstWhere('uom', 'KGM')['issued_qty'], 1e-6);
        $this->assertEqualsWithDelta(100.0, (float) $status->firstWhere('uom', 'KGM')['required_qty'], 1e-6);
        $this->assertEqualsWithDelta(5.0, (float) $status->firstWhere('uom', 'PCS')['issued_qty'], 1e-6);
        $this->assertEqualsWithDelta(20.0, (float) $status->firstWhere('uom', 'PCS')['required_qty'], 1e-6);
    }

    public function test_item_column_accumulates_distinct_material_part_numbers(): void
    {
        $this->issue('MI-D', '2026-10-29', [
            [$this->mainItem, $this->mainPart, 10, 'KGM', 'T5'],
            [$this->mainItem, $this->mainPart, 10, 'KGM', 'T6'],
            [$this->auxItem, $this->auxPart, 4, 'PCS', 'T7'],
        ]);

        $row = app(MaterialIssueSummaryService::class)->byWorkOrder('2026-10-29', '2026-10-29')[0];

        $this->assertCount(2, $row['materials']);
        $parts = collect($row['materials'])->pluck('part_number')->all();
        $this->assertContains($this->mainPart->part_number, $parts);
        $this->assertContains($this->auxPart->part_number, $parts);
    }

    public function test_last_issued_date_reflects_the_latest_slip_for_the_work_order(): void
    {
        $this->issue('MI-E', '2026-10-29', [[$this->mainItem, $this->mainPart, 10, 'KGM', 'T8']]);
        $this->issue('MI-F', '2026-10-31', [[$this->mainItem, $this->mainPart, 20, 'KGM', 'T9']]);

        $row = app(MaterialIssueSummaryService::class)->byWorkOrder('2026-10-29', '2026-10-31')[0];

        $this->assertSame('2026-10-31', $row['last_issued_date']);
    }

    public function test_date_filter_restricts_which_slips_are_counted(): void
    {
        $this->issue('MI-G', '2026-10-28', [[$this->mainItem, $this->mainPart, 10, 'KGM', 'T10']]);
        $this->issue('MI-H', '2026-10-30', [[$this->mainItem, $this->mainPart, 20, 'KGM', 'T11']]);

        $row = app(MaterialIssueSummaryService::class)->byWorkOrder('2026-10-30', '2026-10-30')[0];

        $this->assertSame(1, $row['slip_count']);
        $this->assertEqualsWithDelta(20.0, (float) collect($row['status_by_uom'])->firstWhere('uom', 'KGM')['issued_qty'], 1e-6);
    }

    public function test_details_for_work_order_list_each_slip_line_with_tag_and_uom(): void
    {
        $this->issue('MI-I', '2026-10-29', [
            [$this->mainItem, $this->mainPart, 15, 'KGM', 'T12'],
            [$this->auxItem, $this->auxPart, 3, 'PCS', 'T13'],
        ]);

        $details = app(MaterialIssueSummaryService::class)->detailsForWorkOrder($this->wo->id, '2026-10-29', '2026-10-29');

        $this->assertCount(2, $details);
        $this->assertSame('MI-I', $details[0]['slip_no']);
        $this->assertSame('T12', $details[0]['tag']);
        $this->assertSame('KGM', $details[0]['uom']);
        $this->assertEqualsWithDelta(15.0, (float) $details[0]['qty'], 1e-6);
        $this->assertSame('INV-T12', $details[0]['invoice']);
    }

    public function test_row_for_work_order_returns_a_single_summary_row(): void
    {
        $this->issue('MI-J', '2026-10-29', [[$this->mainItem, $this->mainPart, 25, 'KGM', 'T14']]);

        $row = app(MaterialIssueSummaryService::class)
            ->rowForWorkOrder($this->wo->id, '2026-10-29', '2026-10-29');

        $this->assertNotNull($row);
        $this->assertSame($this->wo->id, $row['work_order_id']);
        $this->assertSame(1, $row['slip_count']);
    }

    public function test_row_for_work_order_is_null_when_the_work_order_has_no_issue_in_range(): void
    {
        $this->issue('MI-K', '2026-10-29', [[$this->mainItem, $this->mainPart, 5, 'KGM', 'T15']]);

        $this->assertNull(
            app(MaterialIssueSummaryService::class)->rowForWorkOrder($this->wo->id, '2026-11-01', '2026-11-01'),
        );
    }
}
