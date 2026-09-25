<?php

namespace Tests\Feature\Api;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PartSubstitute;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MaterialBoardApiTest extends TestCase
{
    use DatabaseTransactions;

    private const MATERIAL = 'CBKG07256C';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function actingAsApi(): self
    {
        $this->actingAs($this->admin, 'sanctum');

        return $this;
    }

    private function plantDate(int $offsetDays = 0): string
    {
        return now('Asia/Jakarta')->addDays($offsetDays)->toDateString();
    }

    private function createWorkOrder(float $qty = 10): WorkOrder
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => $qty,
            'planned_date' => $this->plantDate(),
        ])->assertRedirect();

        return WorkOrder::latest('id')->firstOrFail();
    }

    /** @param array{d?:float|null,d1?:float|null,d2?:float|null} $targets */
    private function schedulePlanRow(WorkOrder $wo, string $planDate, array $targets, int $stepSequence = 1): ProductionPlanItem
    {
        $plan = ProductionPlan::firstOrCreate(['plan_date' => $planDate]);

        return ProductionPlanItem::create([
            'production_plan_id' => $plan->id,
            'work_order_id' => $wo->id,
            'step_sequence' => $stepSequence,
            'target_d' => $targets['d'] ?? null,
            'target_d1' => $targets['d1'] ?? null,
            'target_d2' => $targets['d2'] ?? null,
        ]);
    }

    private function partId(string $partNumber = self::MATERIAL): int
    {
        return (int) Part::where('part_number', $partNumber)->firstOrFail()->id;
    }

    private function itemFor(WorkOrder $wo, string $partNumber = self::MATERIAL): WorkOrderItem
    {
        return $wo->items()->where('child_part_id', $this->partId($partNumber))->firstOrFail();
    }

    /** Kebutuhan material leaf untuk satu WO, apa adanya dari item WO. */
    private function leafRequirement(WorkOrder $wo, string $partNumber = self::MATERIAL): float
    {
        return (float) $this->itemFor($wo, $partNumber)->qty_required;
    }

    /** @return array<string, mixed>|null */
    private function boardRow(int $partId, ?string $uom = null): ?array
    {
        return collect($this->getJson('/api/material-board')->assertOk()->json('data.materials'))
            ->when($uom !== null, fn ($rows) => $rows->where('uom', $uom))
            ->firstWhere('part_id', $partId);
    }

    /** @return list<int> */
    private function boardPartIds(): array
    {
        return collect($this->getJson('/api/material-board')->assertOk()->json('data.materials'))
            ->pluck('part_id')
            ->all();
    }

    public function test_material_board_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/material-board')->assertUnauthorized();
    }

    public function test_material_board_lists_a_bom_material_with_its_days_requirement(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);
        $required = $this->leafRequirement($wo);

        $row = $this->boardRow($this->partId());

        $this->assertNotNull($row);
        $this->assertSame($required, (float) $row['day_qty']);
        $this->assertSame(1, $row['day_wo_count']);
        $this->assertSame('KGM', $row['uom']);
    }

    public function test_material_board_reports_material_identity(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $part = Part::where('part_number', self::MATERIAL)->firstOrFail();
        $row = $this->boardRow($this->partId());

        $this->assertSame($part->part_number, $row['part_number']);
        $this->assertSame($part->part_name, $row['part_name']);
        $this->assertSame($part->model, $row['model']);
        $this->assertSame($part->size, $row['size']);
    }

    public function test_material_board_scales_the_requirement_by_the_day_share(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(100);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 40]);
        $required = $this->leafRequirement($wo);

        $row = $this->boardRow($this->partId());

        $this->assertSame(round($required * 0.4, 4), (float) $row['day_qty']);
        $this->assertNotSame($required, (float) $row['day_qty'], 'Kebutuhan harus diskalakan, bukan memakai qty WO penuh.');
    }

    public function test_material_board_sums_one_material_across_several_work_orders(): void
    {
        $this->actingAsApi();
        $first = $this->createWorkOrder(10);
        $this->schedulePlanRow($first, $this->plantDate(), ['d' => 10]);
        $second = $this->createWorkOrder(10);
        $this->schedulePlanRow($second, $this->plantDate(), ['d' => 10]);
        $required = $this->leafRequirement($first);

        $row = $this->boardRow($this->partId());

        $this->assertSame(round($required * 2, 4), (float) $row['day_qty']);
        $this->assertSame(2, $row['day_wo_count']);
    }

    public function test_material_board_reads_the_d1_column_of_yesterdays_plan_for_today(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(100);
        $this->schedulePlanRow($wo, $this->plantDate(-1), ['d' => 100, 'd1' => 50]);
        $required = $this->leafRequirement($wo);

        $row = $this->boardRow($this->partId());

        $this->assertSame(round($required * 0.5, 4), (float) $row['day_qty']);
        $this->assertSame(0.0, (float) $row['next_day_qty']);
    }

    public function test_material_board_reads_todays_d1_column_for_the_next_day(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(100);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 100, 'd1' => 50]);
        $required = $this->leafRequirement($wo);

        $row = $this->boardRow($this->partId());

        $this->assertSame(round($required, 4), (float) $row['day_qty']);
        $this->assertSame(round($required * 0.5, 4), (float) $row['next_day_qty']);
    }

    public function test_material_board_hides_a_material_scheduled_only_for_the_day_after_tomorrow(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(100);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d2' => 100]);

        $this->assertSame([], $this->boardPartIds());
    }

    public function test_material_board_hides_materials_without_a_scheduled_work_order(): void
    {
        $this->actingAsApi();
        $this->createWorkOrder(10);

        $this->assertSame([], $this->boardPartIds());
    }

    public function test_material_board_hides_materials_whose_requirement_is_fulfilled(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $item = $this->itemFor($wo);
        $issue = MaterialIssue::create([
            'issue_no' => 'ISS-BOARD-1',
            'work_order_id' => $wo->id,
            'issue_date' => $this->plantDate(),
            'status' => 'posted',
        ]);
        MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $item->id,
            'part_id' => $this->partId(),
            'qty' => (float) $item->qty_required,
            'uom' => $item->uom_rm,
        ]);

        $this->assertNull($this->boardRow($this->partId()));
    }

    public function test_material_board_reports_issued_and_remaining_qty(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $item = $this->itemFor($wo);
        $issue = MaterialIssue::create([
            'issue_no' => 'ISS-BOARD-2',
            'work_order_id' => $wo->id,
            'issue_date' => $this->plantDate(),
            'status' => 'posted',
        ]);
        MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $item->id,
            'part_id' => $this->partId(),
            'qty' => 4,
            'uom' => $item->uom_rm,
        ]);

        $row = $this->boardRow($this->partId());

        $this->assertSame(4.0, (float) $row['day_issued_qty']);
        $this->assertSame(round((float) $item->qty_required - 4, 4), (float) $row['day_remaining']);
    }

    public function test_material_board_ignores_cancelled_issue_documents(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $item = $this->itemFor($wo);
        $issue = MaterialIssue::create([
            'issue_no' => 'ISS-BOARD-3',
            'work_order_id' => $wo->id,
            'issue_date' => $this->plantDate(),
            'status' => 'cancelled',
        ]);
        MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $item->id,
            'part_id' => $this->partId(),
            'qty' => 4,
            'uom' => $item->uom_rm,
        ]);

        $this->assertSame(0.0, (float) $this->boardRow($this->partId())['day_issued_qty']);
    }

    public function test_material_board_keeps_the_same_material_in_two_units_separate(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $item = $this->itemFor($wo);
        WorkOrderItem::create([
            'work_order_id' => $wo->id,
            'sequence' => 99,
            'child_part_id' => $this->partId(),
            'child_part_name' => $item->child_part_name,
            'uom_rm' => 'PCS',
            'qty_required' => 7,
            'qty_consumed' => 0,
            'source' => 'Vendor',
        ]);

        $kgm = $this->boardRow($this->partId(), 'KGM');
        $pcs = $this->boardRow($this->partId(), 'PCS');

        $this->assertNotNull($kgm);
        $this->assertNotNull($pcs);
        $this->assertSame((float) $item->qty_required, (float) $kgm['day_qty']);
        $this->assertSame(7.0, (float) $pcs['day_qty']);
    }

    public function test_material_board_skips_work_orders_with_zero_qty(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);
        $wo->update(['qty' => 0]);

        $this->assertSame([], $this->boardPartIds());
    }

    public function test_material_board_reports_work_orders_waiting_for_a_schedule(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $wo->update(['status' => 'in_progress']);
        $this->schedulePlanRow($wo, $this->plantDate(), []);

        $data = $this->getJson('/api/material-board')->assertOk()->json('data');

        $this->assertSame(1, $data['waiting_for_plan']);
        $this->assertSame([$wo->wo_no], collect($data['waiting_work_orders'])->pluck('wo_no')->all());
    }

    public function test_material_board_returns_the_current_and_next_plant_date(): void
    {
        $this->actingAsApi();

        $data = $this->getJson('/api/material-board')->assertOk()->json('data');

        $this->assertSame($this->plantDate(), $data['date']);
        $this->assertSame($this->plantDate(1), $data['next_date']);
    }

    /** @param array<string, mixed> $query */
    private function details(int $partId, array $query = []): array
    {
        $qs = $query === [] ? '' : '?'.http_build_query($query);

        return $this->getJson("/api/material-board/{$partId}/details{$qs}")->assertOk()->json('data');
    }

    private function firstSubstituteId(int $partId): int
    {
        return (int) PartSubstitute::query()
            ->where('part_id', $partId)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('substitute_part_id');
    }

    private function addStockWithReceive(int $partId, string $tag, float $qty, ?string $uom, string $invoice, string $supplier): PartStock
    {
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARR-'.$tag,
            'invoice_no' => $invoice,
            'status' => 'pending',
            'is_local' => false,
            'supplier_id' => Supplier::create([
                'supplier_code' => 'SUP-'.$tag,
                'supplier_name' => $supplier,
            ])->id,
        ]);
        $arrivalItem = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $partId,
            'qty_goods' => $qty,
            'unit_goods' => $uom,
        ]);
        $receive = IncomingReceive::create([
            'arrival_item_id' => $arrivalItem->id,
            'part_id' => $partId,
            'tag' => $tag,
            'qty' => $qty,
            'qty_unit' => $uom,
            'invoice_no' => $invoice,
        ]);

        return PartStock::create([
            'part_id' => $partId,
            'tag' => $tag,
            'qty' => $qty,
            'qty_unit' => $uom,
            'receive_id' => $receive->id,
            'received_at' => now(),
        ]);
    }

    public function test_material_details_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/material-board/'.$this->partId().'/details')->assertUnauthorized();
    }

    public function test_material_details_lists_the_work_orders_behind_a_material(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $data = $this->details($this->partId(), ['uom' => 'KGM']);

        $this->assertCount(1, $data['work_orders']);
        $this->assertSame($wo->wo_no, $data['work_orders'][0]['wo_no']);
        $this->assertSame($wo->id, $data['work_orders'][0]['id']);
        $this->assertSame(10.0, (float) $data['work_orders'][0]['planned_qty']);
        $this->assertSame($this->leafRequirement($wo), (float) $data['work_orders'][0]['required_qty']);
    }

    public function test_material_details_scales_the_work_order_requirement_by_the_day_share(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(100);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 40]);
        $required = $this->leafRequirement($wo);

        $data = $this->details($this->partId(), ['uom' => 'KGM']);

        $this->assertSame(round($required * 0.4, 4), (float) $data['work_orders'][0]['required_qty']);
        $this->assertNotSame($required, (float) $data['work_orders'][0]['required_qty']);
    }

    public function test_material_details_reports_issued_and_remaining_per_work_order(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $item = $this->itemFor($wo);
        $issue = MaterialIssue::create([
            'issue_no' => 'ISS-DETAIL-1',
            'work_order_id' => $wo->id,
            'issue_date' => $this->plantDate(),
            'status' => 'posted',
        ]);
        MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $item->id,
            'part_id' => $this->partId(),
            'qty' => 3,
            'uom' => $item->uom_rm,
        ]);

        $row = $this->details($this->partId(), ['uom' => 'KGM'])['work_orders'][0];

        $this->assertSame(3.0, (float) $row['issued_qty']);
        $this->assertSame(round((float) $item->qty_required - 3, 4), (float) $row['remaining_qty']);
    }

    public function test_material_details_reports_the_material_summary(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $part = Part::where('part_number', self::MATERIAL)->firstOrFail();
        $data = $this->details($this->partId(), ['uom' => 'KGM']);

        $this->assertSame($part->part_number, $data['material']['part_number']);
        $this->assertSame($part->part_name, $data['material']['part_name']);
        $this->assertSame($part->model, $data['material']['model']);
        $this->assertSame($part->size, $data['material']['size']);
        $this->assertSame('KGM', $data['material']['uom']);
        $this->assertSame($this->leafRequirement($wo), (float) $data['material']['required_qty']);
    }

    public function test_material_details_lists_substitutes_with_invoice_and_supplier(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $substituteId = $this->firstSubstituteId($this->partId());
        $this->addStockWithReceive($substituteId, 'SUB-TAG-1', 25, 'KGM', 'INV-8801', 'PT Sumber Logam');

        $data = $this->details($this->partId(), ['uom' => 'KGM']);
        $substitute = collect($data['substitutes'])->firstWhere('part_id', $substituteId);

        $this->assertNotNull($substitute);
        $this->assertSame(25.0, (float) $substitute['available_qty']);
        $this->assertSame('SUB-TAG-1', $substitute['tags'][0]['tag']);
        $this->assertSame('INV-8801', $substitute['tags'][0]['invoice']);
        $this->assertSame('PT Sumber Logam', $substitute['tags'][0]['supplier']);
    }

    public function test_material_details_returns_an_empty_work_order_list_without_a_schedule(): void
    {
        $this->actingAsApi();
        $this->createWorkOrder(10);

        $data = $this->details($this->partId(), ['uom' => 'KGM']);

        $this->assertSame([], $data['work_orders']);
        $this->assertSame(0.0, (float) $data['material']['required_qty']);
    }

    public function test_material_details_filters_by_unit(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $item = $this->itemFor($wo);
        WorkOrderItem::create([
            'work_order_id' => $wo->id,
            'sequence' => 99,
            'child_part_id' => $this->partId(),
            'child_part_name' => $item->child_part_name,
            'uom_rm' => 'PCS',
            'qty_required' => 7,
            'qty_consumed' => 0,
            'source' => 'Vendor',
        ]);

        $kgm = $this->details($this->partId(), ['uom' => 'KGM']);
        $pcs = $this->details($this->partId(), ['uom' => 'PCS']);

        $this->assertSame('KGM', $kgm['work_orders'][0]['uom']);
        $this->assertSame(7.0, (float) $pcs['work_orders'][0]['required_qty']);
    }

    public function test_material_details_accepts_an_explicit_date(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder(10);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d1' => 10]);

        $tomorrow = $this->details($this->partId(), ['uom' => 'KGM', 'date' => $this->plantDate(1)]);
        $today = $this->details($this->partId(), ['uom' => 'KGM', 'date' => $this->plantDate()]);

        $this->assertSame($this->plantDate(1), $tomorrow['date']);
        $this->assertCount(1, $tomorrow['work_orders']);
        $this->assertSame([], $today['work_orders']);
    }
}
