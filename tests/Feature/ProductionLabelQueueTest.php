<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\ProductionResult;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductionLabelQueueTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private WorkOrder $wo;

    private Part $part;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);

        $this->part = Part::where('part_number', 'PINCB01')->firstOrFail();
        $this->wo = WorkOrder::create([
            'wo_no' => 'WO-LBL-'.uniqid(),
            'part_id' => Part::where('part_number', 'AAN30056405')->value('id'),
            'qty' => 1000,
            'status' => 'in_progress',
        ]);
    }

    private function makeResult(float $good, float $reject, string $status = 'pending', bool $backfilled = false): ProductionResult
    {
        return ProductionResult::create([
            'work_order_id' => $this->wo->id,
            'parent_part_id' => $this->part->id,
            'result_date' => now()->toDateString(),
            'shift' => '1',
            'period_start' => '07:30',
            'period_end' => '08:30',
            'is_backfilled' => $backfilled,
            'label_status' => $status,
            'qty_good' => $good,
            'qty_reject' => $reject,
            'uom' => 'PCS',
        ]);
    }

    public function test_queue_lists_reports_waiting_for_a_label(): void
    {
        $this->makeResult(500, 12);
        $this->makeResult(300, 0, status: 'delivered');

        $this->get(route('production-labels.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Production/Label/Index')
                ->has('rows', 1)
                ->where('rows.0.wo_no', $this->wo->wo_no)
                ->where('rows.0.qty_good', 500)
                ->where('rows.0.qty_reject', 12)
                ->where('rows.0.label_status', 'pending'));
    }

    public function test_queue_can_filter_by_label_status(): void
    {
        $this->makeResult(500, 12);
        $this->makeResult(300, 0, status: 'delivered');

        $this->get(route('production-labels.index', ['status' => 'delivered']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1));
    }

    public function test_queue_marks_a_backfilled_period(): void
    {
        $this->makeResult(100, 5, backfilled: true);

        $this->get(route('production-labels.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.is_backfilled', true)
                ->where('rows.0.period_label', '07:30 - 08:30'));
    }

    public function test_printing_good_moves_status_to_printed(): void
    {
        $result = $this->makeResult(500, 12);

        $this->get(route('production-labels.print', [$result, 'good']))->assertOk();

        $this->assertSame('printed', $result->fresh()->label_status);
    }

    public function test_printing_ng_also_moves_status_to_printed(): void
    {
        $result = $this->makeResult(500, 12);

        $this->get(route('production-labels.print', [$result, 'ng']))->assertOk();

        $this->assertSame('printed', $result->fresh()->label_status);
    }

    public function test_printing_is_rejected_when_that_side_has_no_quantity(): void
    {
        $result = $this->makeResult(500, 0);

        $this->get(route('production-labels.print', [$result, 'ng']))->assertNotFound();

        $this->assertSame('pending', $result->fresh()->label_status);
    }

    public function test_printing_an_unknown_kind_is_rejected(): void
    {
        $result = $this->makeResult(500, 12);

        $this->get(route('production-labels.print', [$result, 'other']))->assertNotFound();
    }

    public function test_marking_delivered_moves_status_from_printed_to_delivered(): void
    {
        $result = $this->makeResult(500, 12, status: 'printed');

        $this->post(route('production-labels.deliver', $result))->assertRedirect();

        $this->assertSame('delivered', $result->fresh()->label_status);
    }

    public function test_a_pending_label_cannot_be_marked_delivered_before_printing(): void
    {
        $result = $this->makeResult(500, 12);

        $this->post(route('production-labels.deliver', $result))->assertSessionHasErrors('label_status');

        $this->assertSame('pending', $result->fresh()->label_status);
    }

    public function test_queue_requires_permission(): void
    {
        $stranger = User::create([
            'name' => 'No Role',
            'email' => 'norole-'.uniqid().'@example.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->actingAs($stranger);

        $this->get(route('production-labels.index'))->assertForbidden();
    }
}
