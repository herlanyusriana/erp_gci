<?php

namespace Tests\Feature\Api;

use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionResult;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WipCandidatesApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Part $wipPart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum');
        $this->wipPart = Part::where('part_number', 'PINCB01')->firstOrFail();
    }

    private function woWithStock(float $good, float $stockQty): WorkOrder
    {
        $wo = WorkOrder::create([
            'wo_no' => 'WO-CAND-'.uniqid(),
            'part_id' => Part::where('part_number', 'AAN30056405')->value('id'),
            'qty' => 1000,
            'status' => 'in_progress',
        ]);

        $result = ProductionResult::create([
            'work_order_id' => $wo->id,
            'parent_part_id' => $this->wipPart->id,
            'result_date' => now()->toDateString(),
            'shift' => '1',
            'qty_good' => $good,
            'qty_reject' => 0,
            'uom' => 'PCS',
        ]);

        $stock = PartStock::create([
            'part_id' => $this->wipPart->id,
            'tag' => 'WIP-CAND-'.uniqid(),
            'qty' => $stockQty,
            'qty_unit' => 'PCS',
            'received_at' => now(),
        ]);

        $result->update(['output_part_stock_id' => $stock->id]);

        return $wo;
    }

    public function test_returns_candidates_when_more_than_one_work_order_supplies_the_part(): void
    {
        $first = $this->woWithStock(500, 500);
        $second = $this->woWithStock(300, 300);

        $response = $this->getJson("/api/wip-candidates?part_id={$this->wipPart->id}")->assertOk();

        $rows = $response->json('data.candidates');

        $this->assertCount(2, $rows);
        $this->assertTrue($response->json('data.is_ambiguous'));
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            array_column($rows, 'work_order_id'),
        );
    }

    public function test_is_not_ambiguous_with_a_single_candidate(): void
    {
        $only = $this->woWithStock(500, 500);

        $response = $this->getJson("/api/wip-candidates?part_id={$this->wipPart->id}")->assertOk();

        $this->assertFalse($response->json('data.is_ambiguous'));
        $this->assertSame([$only->id], array_column($response->json('data.candidates'), 'work_order_id'));
    }

    public function test_returns_no_candidates_when_stock_is_gone(): void
    {
        $wo = $this->woWithStock(500, 500);
        PartStock::where('part_id', $this->wipPart->id)->update(['qty' => 0]);

        $response = $this->getJson("/api/wip-candidates?part_id={$this->wipPart->id}")->assertOk();

        $this->assertSame([], $response->json('data.candidates'));
        $this->assertFalse($response->json('data.is_ambiguous'));
    }

    public function test_validates_part_id(): void
    {
        $this->getJson('/api/wip-candidates')
            ->assertStatus(422)
            ->assertJsonValidationErrors('part_id');
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/wip-candidates?part_id={$this->wipPart->id}")
            ->assertUnauthorized();
    }
}
