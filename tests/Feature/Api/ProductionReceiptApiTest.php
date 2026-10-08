<?php

namespace Tests\Feature\Api;

use App\Models\Machine;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionMaterialReceipt;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderMaterialBooking;
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
    private function seedReceiptFixture(string $tag, ?Part $material = null): MaterialIssueItem
    {
        $fg = $material ?? Part::where('part_number', 'AAN30056405')->firstOrFail();

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
            'issue_no' => 'ISS-RCPT-'.uniqid(),
            'work_order_id' => $wo->id,
            'issue_date' => now()->toDateString(),
            'status' => 'posted',
        ]);

        $stock = PartStock::create([
            'part_id' => $fg->id, 'tag' => $tag, 'qty' => 10,
            'qty_unit' => 'PCS', 'received_at' => now(),
        ]);
        WorkOrderMaterialBooking::create([
            'work_order_id' => $wo->id, 'work_order_item_id' => $woItem->id,
            'part_id' => $fg->id, 'part_stock_id' => $stock->id, 'tag' => $tag,
            'qty' => 10, 'uom' => 'PCS', 'status' => 'booked', 'booked_at' => now(),
        ]);

        return MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $woItem->id,
            'part_id' => $fg->id,
            'part_stock_id' => $stock->id,
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

    public function test_fully_booked_tag_resolves_for_receipt_but_not_free_stock(): void
    {
        $item = $this->seedReceiptFixture('BOOKED');
        $this->postJson('/api/stock-tags/resolve', ['tag' => 'BOOKED', 'part_id' => $item->part_id])->assertNotFound();
        $this->postJson('/api/receipts/resolve', ['tag' => ' booked ', 'part_id' => $item->part_id])
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.material_issue_item_id', $item->id)
            ->assertJsonPath('data.0.qty', 10)
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_repeated_tags_return_candidates_and_confirm_only_selected_item(): void
    {
        $first = $this->seedReceiptFixture('REPEAT');
        $second = $this->seedReceiptFixture('REPEAT', Part::where('part_number', 'CBKG07256C')->firstOrFail());
        $third = $this->seedReceiptFixture('REPEAT');
        $this->postJson('/api/receipts/resolve', ['tag' => 'REPEAT'])->assertOk()->assertJsonCount(3, 'data');
        $this->postJson('/api/receipts/resolve', ['tag' => 'REPEAT', 'part_id' => $first->part_id])->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/receipts/confirm', ['tag' => 'REPEAT', 'machine_id' => $this->machine->id])->assertUnprocessable();
        $this->postJson('/api/receipts/confirm', ['material_issue_item_id' => $first->id, 'machine_id' => $this->machine->id])->assertOk();
        $this->assertDatabaseHas('part_stocks', ['id' => $first->part_stock_id, 'qty' => 0]);
        $this->assertDatabaseHas('part_stocks', ['id' => $second->part_stock_id, 'qty' => 10, 'deleted_at' => null]);
        $this->assertDatabaseHas('work_order_material_bookings', ['part_stock_id' => $third->part_stock_id, 'qty' => 10, 'status' => 'booked']);
        $this->assertDatabaseHas('production_material_receipts', ['material_issue_item_id' => $first->id]);
        $this->assertDatabaseMissing('production_material_receipts', ['material_issue_item_id' => $second->id]);
    }

    public function test_partial_issue_consumes_only_issued_quantity_and_retains_booking_remainder(): void
    {
        $item = $this->seedReceiptFixture('PARTIAL');
        $item->update(['qty' => 4]);
        $this->postJson('/api/receipts/confirm', ['material_issue_item_id' => $item->id, 'machine_id' => $this->machine->id])->assertOk();
        $this->assertDatabaseHas('part_stocks', ['id' => $item->part_stock_id, 'qty' => 6, 'deleted_at' => null]);
        $this->assertDatabaseHas('work_order_material_bookings', ['part_stock_id' => $item->part_stock_id, 'qty' => 6, 'status' => 'booked']);
    }

    public function test_duplicate_on_another_machine_does_not_decrement_twice(): void
    {
        $item = $this->seedReceiptFixture('DUPLICATE');
        $item->update(['qty' => 4]);
        $this->postJson('/api/receipts/confirm', ['tag' => $item->tag, 'material_issue_item_id' => $item->id, 'machine_id' => $this->machine->id])->assertOk();
        $other = Machine::create(['machine_code' => 'OTHER-'.uniqid(), 'machine_name' => 'Other', 'is_active' => true]);
        foreach ([$this->machine->id, $other->id] as $machineId) {
            $this->postJson('/api/receipts/confirm', ['tag' => $item->tag, 'material_issue_item_id' => $item->id, 'machine_id' => $machineId])->assertUnprocessable();
        }
        $this->assertDatabaseHas('part_stocks', ['id' => $item->part_stock_id, 'qty' => 6]);
        $this->assertSame(1, ProductionMaterialReceipt::where('material_issue_item_id', $item->id)->count());
    }

    public function test_invalid_context_fails_without_receipt_or_stock_change(): void
    {
        foreach (['cancelled_issue', 'cancelled_wo', 'completed_wo', 'null_stock', 'missing_booking', 'insufficient_booking', 'insufficient_stock', 'wrong_wo', 'wrong_uom', 'wrong_tag', 'deleted_stock', 'wrong_item_uom', 'zero_qty'] as $failure) {
            $item = $this->seedReceiptFixture('INVALID-'.$failure);
            $stockId = $item->part_stock_id;
            $booking = WorkOrderMaterialBooking::where('part_stock_id', $stockId)->firstOrFail();
            match ($failure) {
                'cancelled_issue' => $item->materialIssue->update(['status' => 'cancelled']),
                'cancelled_wo' => $item->materialIssue->workOrder->update(['status' => 'cancelled']),
                'completed_wo' => $item->materialIssue->workOrder->update(['status' => 'completed']),
                'null_stock' => $item->update(['part_stock_id' => null]),
                'missing_booking' => $booking->delete(),
                'insufficient_booking' => $booking->update(['qty' => 3]),
                'insufficient_stock' => PartStock::findOrFail($stockId)->update(['qty' => 3]),
                'wrong_wo' => $booking->update(['work_order_id' => $this->seedReceiptFixture('OTHER-WO')->materialIssue->work_order_id]),
                'wrong_uom' => $booking->update(['uom' => 'KGM']),
                'wrong_tag' => null,
                'deleted_stock' => PartStock::findOrFail($stockId)->delete(),
                'wrong_item_uom' => $item->workOrderItem->update(['uom_rm' => 'KGM']),
                'zero_qty' => $item->update(['qty' => 0]),
            };
            $this->postJson('/api/receipts/confirm', [
                'tag' => $failure === 'wrong_tag' ? 'MISMATCH' : $item->tag,
                'material_issue_item_id' => $item->id, 'machine_id' => $this->machine->id,
            ])->assertUnprocessable();
            $this->assertDatabaseMissing('production_material_receipts', ['material_issue_item_id' => $item->id]);
            $this->assertSame($failure === 'insufficient_stock' ? 3.0 : 10.0, PartStock::withTrashed()->findOrFail($stockId)->qty);
            $this->assertSame($failure === 'deleted_stock', PartStock::withTrashed()->findOrFail($stockId)->trashed());
        }
    }

    public function test_receipt_endpoints_require_permission(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/receipts/resolve', ['tag' => 'ANY'])->assertForbidden();
        $this->postJson('/api/receipts/confirm', ['tag' => 'ANY', 'machine_id' => $this->machine->id])->assertForbidden();
        $this->getJson('/api/receipts?machine_id='.$this->machine->id)->assertForbidden();
    }

    public function test_receipt_resolve_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/receipts/resolve', ['tag' => 'ANY'])->assertUnauthorized();
    }

    public function test_receipt_does_not_consume_another_wo_booking_on_the_same_stock(): void
    {
        $decoy = $this->seedReceiptFixture('SHARED');
        $item = $this->seedReceiptFixture('SHARED');
        $item->update(['qty' => 4, 'part_stock_id' => $decoy->part_stock_id]);
        WorkOrderMaterialBooking::where('work_order_item_id', $item->work_order_item_id)->update(['part_stock_id' => $decoy->part_stock_id, 'qty' => 4]);
        PartStock::findOrFail($decoy->part_stock_id)->update(['qty' => 14]);
        $this->postJson('/api/receipts/confirm', ['material_issue_item_id' => $item->id, 'machine_id' => $this->machine->id])->assertOk();
        $this->assertDatabaseHas('part_stocks', ['id' => $decoy->part_stock_id, 'qty' => 10]);
        $this->assertDatabaseHas('work_order_material_bookings', ['work_order_item_id' => $decoy->work_order_item_id, 'qty' => 10, 'status' => 'booked']);
        $this->assertDatabaseHas('work_order_material_bookings', ['work_order_item_id' => $item->work_order_item_id, 'qty' => 4, 'status' => 'transferred']);
    }

    public function test_resolve_excludes_received_cancelled_and_completed_items(): void
    {
        $received = $this->seedReceiptFixture('FILTERED');
        $cancelled = $this->seedReceiptFixture('FILTERED');
        $completed = $this->seedReceiptFixture('FILTERED');
        $pending = $this->seedReceiptFixture('FILTERED');
        $cancelled->materialIssue->update(['status' => 'cancelled']);
        $completed->materialIssue->workOrder->update(['status' => 'completed']);
        $this->postJson('/api/receipts/confirm', ['material_issue_item_id' => $received->id, 'machine_id' => $this->machine->id])->assertOk();
        $this->postJson('/api/receipts/resolve', ['tag' => 'FILTERED'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.material_issue_item_id', $pending->id);
        $this->postJson('/api/receipts/resolve', ['tag' => 'UNKNOWN'])->assertOk()->assertJsonCount(0, 'data');
    }
}
