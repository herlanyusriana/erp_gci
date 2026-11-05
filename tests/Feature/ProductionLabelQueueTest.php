<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\ProductionResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderConsumption;
use App\Models\WorkOrderItem;
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

    private function woItemId(): int
    {
        return (int) WorkOrderItem::firstOrCreate(
            [
                'work_order_id' => $this->wo->id,
                'parent_part_id' => $this->part->id,
                'sequence' => 99,
            ],
            [
                'child_part_name' => 'COIL',
                'uom_rm' => 'KGM',
                'qty_required' => 1000,
                'qty_consumed' => 0,
                'source' => 'Vendor',
            ],
        )->id;
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

        // Default menampilkan seluruh baris hari itu agar data yang sudah
        // pernah dibuka cetak tidak mendadak hilang dari pandangan pengguna.
        $this->get(route('production-labels.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Production/Label/Index')
                ->has('rows', 2));

        $this->get(route('production-labels.index', ['status' => 'pending']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.wo_no', $this->wo->wo_no)
                ->where('rows.0.qty_good', 500)
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

        $this->get(route('production-labels.print', [$result, 'good']))
            ->assertOk()
            ->assertSee('LABEL WIP')
            ->assertSee($this->wo->wo_no)
            ->assertSee('TAG # :')
            ->assertSee('QUANTITY (PCS - BOX)')
            ->assertSee('window.print()', false);

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

    public function test_label_lists_every_invoice_consumed_by_the_period(): void
    {
        $result = $this->makeResult(352, 0);

        WorkOrderConsumption::create([
            'work_order_id' => $this->wo->id, 'work_order_item_id' => $this->woItemId(),
            'part_stock_id' => 1, 'part_id' => $this->part->id,
            'qty' => 260, 'uom' => 'KGM',
            'invoice_no' => 'HCGCI 26-0901', 'supplier_name' => 'YONGXIN METAL',
            'production_result_id' => $result->id,
        ]);
        WorkOrderConsumption::create([
            'work_order_id' => $this->wo->id, 'work_order_item_id' => $this->woItemId(),
            'part_stock_id' => 2, 'part_id' => $this->part->id,
            'qty' => 92, 'uom' => 'KGM',
            'invoice_no' => 'HCGCI 26-0902', 'supplier_name' => 'TAIZHOU',
            'production_result_id' => $result->id,
        ]);

        $this->get(route('production-labels.print', [$result, 'good']))
            ->assertOk()
            ->assertSee('HCGCI 26-0901')
            ->assertSee('HCGCI 26-0902');
    }

    public function test_label_shows_a_single_invoice_without_listing_when_only_one_source(): void
    {
        $result = $this->makeResult(120, 0);

        WorkOrderConsumption::create([
            'work_order_id' => $this->wo->id, 'work_order_item_id' => $this->woItemId(),
            'part_stock_id' => 1, 'part_id' => $this->part->id,
            'qty' => 120, 'uom' => 'KGM',
            'invoice_no' => 'HCGCI 26-0901', 'supplier_name' => 'YONGXIN METAL',
            'production_result_id' => $result->id,
        ]);

        $this->get(route('production-labels.print', [$result, 'good']))
            ->assertOk()
            ->assertSee('HCGCI 26-0901')
            ->assertDontSee('SUMBER:');
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

    public function test_multi_container_printing_generates_numbered_label_sheets(): void
    {
        $result = $this->makeResult(352, 0);

        // 352 pcs kemasan BOX @ 100 dengan sisa dicetak sekarang -> 4 label
        $this->get(route('production-labels.print', [
            'result' => $result,
            'kind' => 'good',
            'packing_type' => 'BOX',
            'packing_qty' => 100,
            'defer_remainder' => false,
        ]))
            ->assertOk()
            ->assertSee('LABEL 1 / 4')
            ->assertSee('LABEL 2 / 4')
            ->assertSee('LABEL 3 / 4')
            ->assertSee('LABEL 4 / 4')
            ->assertSee('QUANTITY (PCS - BOX)');
    }

    public function test_deferring_remainder_records_carry_over_qty_and_prints_only_full_packs(): void
    {
        $result = $this->makeResult(352, 0);

        // 352 pcs kemasan BOX @ 100 dengan sisa ditunda -> hanya 3 label @ 100
        $this->get(route('production-labels.print', [
            'result' => $result,
            'kind' => 'good',
            'packing_type' => 'BOX',
            'packing_qty' => 100,
            'defer_remainder' => true,
        ]))
            ->assertOk()
            ->assertSee('LABEL 1 / 3')
            ->assertSee('LABEL 3 / 3')
            ->assertDontSee('LABEL 4 /');

        $this->assertEqualsWithDelta(52.0, (float) $result->fresh()->label_carried_over_qty, 0.001);
    }

    public function test_next_period_absorbs_carried_over_qty_from_previous_period(): void
    {
        $p1 = $this->makeResult(352, 0);
        $p1->update(['label_carried_over_qty' => 52]);

        $p2 = $this->makeResult(248, 0);

        // Periode 2 (248 pcs) menyerap sisa 52 pcs dari periode 1 -> total 300 pcs @ 100 = 3 label pas
        $this->get(route('production-labels.print', [
            'result' => $p2,
            'kind' => 'good',
            'packing_type' => 'BOX',
            'packing_qty' => 100,
        ]))
            ->assertOk()
            ->assertSee('LABEL 1 / 3')
            ->assertSee('LABEL 3 / 3')
            ->assertSee('100');

        $this->assertEqualsWithDelta(0.0, (float) $p1->fresh()->label_carried_over_qty, 0.001);
        $this->assertSame($p1->id, $p2->fresh()->label_carry_over_source_id);
    }

    public function test_accumulated_ng_prints_total_rejects_across_periods(): void
    {
        $this->makeResult(100, 3);
        $this->makeResult(120, 5);

        $this->get(route('production-labels.print-accumulated-ng', [
            'date' => now()->toDateString(),
            'work_order_id' => $this->wo->id,
        ]))
            ->assertOk()
            ->assertSee('LABEL WIP (NG)')
            ->assertSee('AKUMULASI NG')
            ->assertSee('8'); // 3 + 5
    }
}
