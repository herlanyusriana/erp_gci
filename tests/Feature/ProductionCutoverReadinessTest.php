<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterialBooking;
use App\Services\ProductionReceiptService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfInterceptor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionCutoverReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfInterceptor::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function releasedWorkOrder(): WorkOrder
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $fg->update(['model' => 'MD-CUTOVER-FG']);

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 10,
            'planned_date' => now()->toDateString(),
        ])->assertRedirect();

        $wo = WorkOrder::latest('id')->firstOrFail();

        foreach ([
            'CBKG07256C' => ['KGM', 20],
            '4000W4A003A' => ['PCS', 20],
            'PINCB01' => ['PCS', 80],
            '5040JA3071C' => ['PCS', 80],
        ] as $pn => $d) {
            PartStock::create([
                'part_id' => Part::where('part_number', $pn)->value('id'),
                'tag' => 'CUT-'.$pn.'-'.uniqid(),
                'qty' => $d[1],
                'qty_unit' => $d[0],
                'received_at' => now(),
            ]);
        }

        $this->post(route('work-orders.release', $wo))->assertRedirect();

        $issue = MaterialIssue::create([
            'issue_no' => 'MI-CUT-'.uniqid(),
            'work_order_id' => $wo->id,
            'issue_date' => now(),
            'status' => 'posted',
        ]);

        foreach (WorkOrderMaterialBooking::where('work_order_id', $wo->id)->where('status', 'booked')->get() as $booking) {
            $row = $wo->items()->findOrFail($booking->work_order_item_id);
            $machineId = $wo->items()->where('parent_part_id', $row->parent_part_id)->first()->machine_id;
            if ($machineId === null) {
                $machineId = Machine::where('is_active', true)->value('id');
                $wo->items()->where('parent_part_id', $row->parent_part_id)->update(['machine_id' => $machineId]);
            }
            $item = MaterialIssueItem::create([
                'material_issue_id' => $issue->id,
                'work_order_item_id' => $row->id,
                'part_id' => $booking->part_id,
                'part_stock_id' => $booking->part_stock_id,
                'tag' => $booking->tag,
                'qty' => $booking->qty,
                'uom' => $booking->uom,
            ]);
            app(ProductionReceiptService::class)->confirm(null, $machineId, materialIssueItemId: $item->id);
        }

        return $wo->fresh();
    }

    public function test_production_session_api_exposes_part_model_and_seven_periods(): void
    {
        $wo = $this->releasedWorkOrder();
        $fgItem = $wo->items()
            ->where('parent_part_id', $wo->part_id)
            ->whereNotNull('machine_id')
            ->firstOrFail();

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/machines/{$fgItem->machine_id}/production-session?shift=1")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.shift', '1')
            ->assertJsonCount(7, 'data.periods');

        $parts = collect($response->json('data.parts'));
        $matched = $parts->firstWhere('part_id', $wo->part_id);

        $this->assertNotNull($matched);
        $this->assertSame('MD-CUTOVER-FG', $matched['model']);
    }

    public function test_web_result_create_exposes_model_and_shift_periods_and_feeds_label_queue(): void
    {
        $wo = $this->releasedWorkOrder();
        $firstItem = $wo->items()->orderBy('sequence')->orderBy('id')->firstOrFail();
        $parentPart = Part::findOrFail($firstItem->parent_part_id);
        $parentPart->update(['model' => 'MD-STEP-WEB']);

        $this->get(route('production-results.create', $wo))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Production/Result/Create')
                ->where('workOrderPart.model', 'MD-CUTOVER-FG')
                ->has('shiftPeriods.1', 7)
                ->has('shiftPeriods.2', 7)
                ->has('shiftPeriods.3', 7)
                ->where('steps', fn ($steps) => collect($steps)
                    ->firstWhere('parent_part_id', $parentPart->id)['model'] === 'MD-STEP-WEB'));

        $today = now('Asia/Jakarta')->toDateString();

        $this->post(route('production-results.store', $wo), [
            'parent_part_id' => $parentPart->id,
            'qty_good' => 4,
            'qty_reject' => 1,
            'result_date' => $today,
            'shift' => '1',
            'period_start' => '07:30',
            'period_end' => '08:30',
        ])->assertRedirect(route('production-results.create', $wo));

        $result = ProductionResult::where('work_order_id', $wo->id)
            ->where('parent_part_id', $parentPart->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('1', $result->shift);
        $this->assertSame('07:30:00', $result->period_start);
        $this->assertSame('08:30:00', $result->period_end);

        $this->get(route('production-labels.index', ['date' => $today]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Production/Label/Index')
                ->where('rows', function ($rows) use ($result) {
                    $row = collect($rows)->firstWhere('id', $result->id);

                    return $row !== null
                        && $row['model'] === 'MD-STEP-WEB'
                        && $row['period_label'] === '07:30 - 08:30'
                        && $row['label_status'] === 'pending';
                }));

        $this->get(route('production-labels.print', ['result' => $result->id, 'kind' => 'good']))
            ->assertOk();

        $this->assertSame('printed', $result->fresh()->label_status);

        $this->post(route('production-labels.deliver', $result))
            ->assertRedirect();

        $this->assertSame('delivered', $result->fresh()->label_status);
    }

    public function test_web_result_store_rejects_incomplete_period_pair(): void
    {
        $wo = $this->releasedWorkOrder();
        $firstItem = $wo->items()->orderBy('sequence')->orderBy('id')->firstOrFail();

        $this->post(route('production-results.store', $wo), [
            'parent_part_id' => $firstItem->parent_part_id,
            'qty_good' => 2,
            'shift' => '1',
            'period_start' => '07:30',
        ])->assertSessionHasErrors('period_end');
    }

    public function test_results_index_includes_and_searches_part_model(): void
    {
        $wo = $this->releasedWorkOrder();

        $this->get(route('production-results.index', ['search' => 'MD-CUTOVER-FG']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Production/Result/Index')
                ->where('workOrders.data', function ($list) use ($wo) {
                    $row = collect($list)->firstWhere('id', $wo->id);

                    return $row !== null && ($row['part']['model'] ?? null) === 'MD-CUTOVER-FG';
                }));
    }
}
