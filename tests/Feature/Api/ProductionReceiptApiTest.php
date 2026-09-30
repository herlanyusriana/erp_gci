<?php

namespace Tests\Feature\Api;

use App\Models\Machine;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductionReceiptApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Machine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum');

        $this->machine = Machine::create([
            'machine_code' => 'TEST-MC-'.uniqid(),
            'machine_name' => 'Mesin Test Receipt',
            'is_active' => true,
        ]);
    }

    /** Bangun satu item issue untuk testing. */
    private function seedReceiptFixture(string $tag): MaterialIssueItem
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $wo = WorkOrder::create([
            'wo_no' => 'WO-RCPT-'.uniqid(),
            'part_id' => $fg->id,
            'qty' => 10,
            'status' => 'in_progress',
            'planned_date' => now()->toDateString(),
        ]);

        $woItem = WorkOrderItem::create([
            'work_order_id' => $wo->id,
            'sequence' => 1,
            'child_part_id' => $fg->id,
            'child_part_name' => $fg->part_name,
            'uom_rm' => 'PCS',
            'qty_required' => 10,
            'qty_consumed' => 0,
            'source' => 'Vendor',
        ]);

        $issue = MaterialIssue::create([
            'issue_no' => 'ISS-RCPT-'.$tag,
            'work_order_id' => $wo->id,
            'issue_date' => now()->toDateString(),
            'status' => 'posted',
        ]);

        return MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $woItem->id,
            'part_id' => $fg->id,
            'tag' => $tag,
            'qty' => 10,
            'uom' => 'PCS',
        ]);
    }

    public function test_confirm_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/receipts/confirm', [
            'tag' => 'SOME-TAG',
            'machine_id' => $this->machine->id,
        ])->assertUnauthorized();
    }

    public function test_confirm_creates_a_receipt(): void
    {
        $issueItem = $this->seedReceiptFixture('TEST-R1');

        $response = $this->postJson('/api/receipts/confirm', [
            'tag' => 'TEST-R1',
            'machine_id' => $this->machine->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['data' => ['id', 'tag', 'part', 'machine_id', 'received_at']]);

        $this->assertDatabaseHas('production_material_receipts', [
            'material_issue_item_id' => $issueItem->id,
            'machine_id' => $this->machine->id,
            'tag' => 'TEST-R1',
        ]);
    }

    public function test_confirm_rejects_unknown_tag(): void
    {
        $this->postJson('/api/receipts/confirm', [
            'tag' => 'TIDAK-ADA-SAMA-SEKALI',
            'machine_id' => $this->machine->id,
        ])->assertStatus(422);
    }

    public function test_confirm_rejects_inactive_machine(): void
    {
        $this->seedReceiptFixture('TEST-R2');
        $this->machine->update(['is_active' => false]);

        $this->postJson('/api/receipts/confirm', [
            'tag' => 'TEST-R2',
            'machine_id' => $this->machine->id,
        ])->assertStatus(422);
    }

    public function test_confirm_rejects_duplicate_tag_per_machine(): void
    {
        $this->seedReceiptFixture('TEST-R3');

        $this->postJson('/api/receipts/confirm', [
            'tag' => 'TEST-R3',
            'machine_id' => $this->machine->id,
        ])->assertOk();

        $this->postJson('/api/receipts/confirm', [
            'tag' => 'TEST-R3',
            'machine_id' => $this->machine->id,
        ])->assertStatus(422);
    }

    public function test_index_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/receipts?machine_id='.$this->machine->id)->assertUnauthorized();
    }

    public function test_index_lists_receipts_for_a_machine(): void
    {
        $this->seedReceiptFixture('TEST-L1');
        $this->postJson('/api/receipts/confirm', [
            'tag' => 'TEST-L1',
            'machine_id' => $this->machine->id,
        ]);

        $this->getJson('/api/receipts?machine_id='.$this->machine->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_date(): void
    {
        $this->seedReceiptFixture('TEST-L2');
        $this->postJson('/api/receipts/confirm', [
            'tag' => 'TEST-L2',
            'machine_id' => $this->machine->id,
        ]);

        $yesterday = now()->subDay()->toDateString();
        $this->getJson('/api/receipts?machine_id='.$this->machine->id.'&date='.$yesterday)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}