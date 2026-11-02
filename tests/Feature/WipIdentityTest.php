<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WipIdentityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WipIdentityTest extends TestCase
{
    use DatabaseTransactions;

    private WorkOrder $wo;

    private Part $wipPart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());

        $this->wipPart = Part::where('part_number', 'PINCB01')->firstOrFail();
        $this->wo = WorkOrder::create([
            'wo_no' => 'WO-WIPID-'.uniqid(),
            'part_id' => Part::where('part_number', 'AAN30056405')->value('id'),
            'qty' => 1000,
            'status' => 'in_progress',
        ]);
    }

    private function addResult(float $good, ?string $date = null, ?string $shift = '1'): ProductionResult
    {
        return ProductionResult::create([
            'work_order_id' => $this->wo->id,
            'parent_part_id' => $this->wipPart->id,
            'result_date' => $date ?? now()->toDateString(),
            'shift' => $shift,
            'qty_good' => $good,
            'qty_reject' => 0,
            'uom' => 'PCS',
        ]);
    }

    private function stock(float $qty, string $tag = 'WIP-TAG'): PartStock
    {
        return PartStock::create([
            'part_id' => $this->wipPart->id,
            'tag' => $tag.'-'.uniqid(),
            'qty' => $qty,
            'qty_unit' => 'PCS',
            'received_at' => now(),
        ]);
    }

    /** Hasil produksi + stok WIP yang lahir darinya (tautan eksplisit). */
    private function addResultWithStock(float $good, float $stockQty): array
    {
        $result = $this->addResult($good);
        $stock = $this->stock($stockQty);
        $result->update(['output_part_stock_id' => $stock->id]);

        return [$result, $stock];
    }

    public function test_several_periods_in_one_work_order_add_to_the_same_wip_identity(): void
    {
        $this->addResult(200, shift: '1');
        $this->addResult(180, shift: '1');
        $this->addResult(170, shift: '2');

        $rows = app(WipIdentityService::class)->forWorkOrder($this->wo->id);

        $row = collect($rows)->firstWhere('part_id', $this->wipPart->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(550.0, (float) $row['produced_qty'], 1e-6);
        $this->assertSame(3, $row['result_count']);
    }

    public function test_different_work_orders_keep_separate_identities_for_the_same_part(): void
    {
        $otherWo = WorkOrder::create([
            'wo_no' => 'WO-WIPID2-'.uniqid(),
            'part_id' => $this->wo->part_id,
            'qty' => 500,
            'status' => 'in_progress',
        ]);

        $this->addResult(200);
        ProductionResult::create([
            'work_order_id' => $otherWo->id,
            'parent_part_id' => $this->wipPart->id,
            'result_date' => now()->toDateString(),
            'shift' => '1',
            'qty_good' => 90,
            'qty_reject' => 0,
            'uom' => 'PCS',
        ]);

        $service = app(WipIdentityService::class);

        $this->assertEqualsWithDelta(200.0, (float) collect($service->forWorkOrder($this->wo->id))->firstWhere('part_id', $this->wipPart->id)['produced_qty'], 1e-6);
        $this->assertEqualsWithDelta(90.0, (float) collect($service->forWorkOrder($otherWo->id))->firstWhere('part_id', $this->wipPart->id)['produced_qty'], 1e-6);
    }

    public function test_identity_reports_remaining_stock_after_consumption(): void
    {
        $this->addResultWithStock(500, 500);

        $row = collect(app(WipIdentityService::class)->forWorkOrder($this->wo->id))
            ->firstWhere('part_id', $this->wipPart->id);

        $this->assertEqualsWithDelta(500.0, (float) $row['produced_qty'], 1e-6);
        $this->assertEqualsWithDelta(500.0, (float) $row['available_qty'], 1e-6);
    }

    public function test_identity_is_empty_when_the_work_order_has_no_result(): void
    {
        $this->assertSame([], app(WipIdentityService::class)->forWorkOrder($this->wo->id));
    }

    public function test_candidates_for_a_part_list_only_work_orders_with_available_stock(): void
    {
        $this->addResultWithStock(500, 500);

        $emptyWo = WorkOrder::create([
            'wo_no' => 'WO-WIPID3-'.uniqid(),
            'part_id' => $this->wo->part_id,
            'qty' => 100,
            'status' => 'in_progress',
        ]);
        ProductionResult::create([
            'work_order_id' => $emptyWo->id,
            'parent_part_id' => $this->wipPart->id,
            'result_date' => now()->toDateString(),
            'shift' => '1',
            'qty_good' => 50,
            'qty_reject' => 0,
            'uom' => 'PCS',
        ]);

        $candidates = app(WipIdentityService::class)->candidatesForPart($this->wipPart->id);

        $this->assertCount(1, $candidates);
        $this->assertSame($this->wo->id, $candidates[0]['work_order_id']);
    }
}
