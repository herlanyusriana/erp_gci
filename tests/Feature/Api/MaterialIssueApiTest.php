<?php

namespace Tests\Feature\Api;

use App\Events\MaterialIssuePosted;
use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\Location;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderItemAllocation;
use App\Models\WorkOrderMaterialBooking;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MaterialIssueApiTest extends TestCase
{
    use DatabaseTransactions;

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

    private function createWorkOrder(): WorkOrder
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 10,
            'planned_date' => now()->toDateString(),
        ])->assertRedirect();

        return WorkOrder::latest('id')->firstOrFail();
    }

    /** @return array<int, array{work_order_item_id:int, scans: array<int, array{tag:string, qty:float, part_id:int}>}> */
    private function seedStockAndBuildScans(WorkOrder $wo): array
    {
        $items = $this->getJson("/api/work-orders/{$wo->id}/release-context")
            ->assertOk()
            ->json('data.items');

        $this->assertNotEmpty($items);

        $scans = [];
        foreach ($items as $item) {
            $main = collect($item['allowed_parts'])->firstWhere('kind', 'main');
            $tag = 'T-'.$item['work_order_item_id'];
            PartStock::create([
                'part_id' => $main['id'],
                'tag' => $tag,
                'qty' => $item['required'],
                'qty_unit' => $item['uom'],
                'received_at' => now(),
            ]);
            $scans[] = [
                'work_order_item_id' => $item['work_order_item_id'],
                'scans' => [['tag' => $tag, 'qty' => (float) $item['required'], 'part_id' => (int) $main['id']]],
            ];
        }

        return $scans;
    }

    public function test_work_orders_list_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/work-orders')->assertUnauthorized();
    }

    private function plantDate(int $offsetDays = 0): string
    {
        return now('Asia/Jakarta')->addDays($offsetDays)->toDateString();
    }

    private function makeWorkOrder(string $woNo, float $qty = 10, string $status = 'planned'): WorkOrder
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        return WorkOrder::create([
            'wo_no' => $woNo,
            'part_id' => $fg->id,
            'qty' => $qty,
            'status' => $status,
            'planned_date' => $this->plantDate(),
        ]);
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

    /** @return list<string> */
    private function listedWoNumbers(string $query = ''): array
    {
        return collect($this->getJson('/api/work-orders'.$query)->assertOk()->json('data'))
            ->pluck('wo_no')
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function listedRow(string $woNo): ?array
    {
        return collect($this->getJson('/api/work-orders')->assertOk()->json('data'))
            ->firstWhere('wo_no', $woNo);
    }

    /** @return array<string, mixed> */
    private function firstReleaseItem(WorkOrder $wo): array
    {
        $items = $this->getJson("/api/work-orders/{$wo->id}/release-context")->assertOk()->json('data.items');
        $this->assertNotEmpty($items);

        return $items[0];
    }

    /** @return array<string, mixed> */
    private function releaseItemWithSubstitutes(WorkOrder $wo, int $minimum = 2): array
    {
        $items = $this->getJson("/api/work-orders/{$wo->id}/release-context")->assertOk()->json('data.items');

        $match = collect($items)->first(
            fn (array $item) => count($this->substituteIds($item)) >= $minimum,
        );
        $this->assertNotNull($match, "Data uji butuh item dengan minimal {$minimum} substitute aktif.");

        return $match;
    }

    /** @param array<string, mixed> $item */
    private function substituteIds(array $item): array
    {
        return collect($item['allowed_parts'])
            ->where('kind', 'substitute')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $item */
    private function mainPartId(array $item): int
    {
        return (int) collect($item['allowed_parts'])->firstWhere('kind', 'main')['id'];
    }

    private function allocate(int $workOrderItemId, int $partId, float $qty): WorkOrderItemAllocation
    {
        return WorkOrderItemAllocation::create([
            'work_order_item_id' => $workOrderItemId,
            'part_id' => $partId,
            'qty' => $qty,
        ]);
    }

    private function addStock(int $partId, string $tag, float $qty, ?string $uom): PartStock
    {
        return PartStock::create([
            'part_id' => $partId,
            'tag' => $tag,
            'qty' => $qty,
            'qty_unit' => $uom,
            'received_at' => now(),
        ]);
    }

    /** Tag stok lengkap dengan rantai receive → arrival → supplier. */
    private function addStockWithReceive(
        int $partId,
        string $tag,
        float $qty,
        ?string $uom,
        ?string $invoiceNo,
        ?string $supplierName,
        ?string $invoiceDate = null,
        ?Carbon $receivedAt = null,
    ): PartStock {
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARR-'.$tag,
            'invoice_no' => $invoiceNo,
            'invoice_date' => $invoiceDate,
            'status' => 'pending',
            'is_local' => false,
            'supplier_id' => $supplierName === null ? null : Supplier::create([
                'supplier_code' => 'SUP-'.$tag,
                'supplier_name' => $supplierName,
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
            'invoice_no' => $invoiceNo,
        ]);

        return PartStock::create([
            'part_id' => $partId,
            'tag' => $tag,
            'qty' => $qty,
            'qty_unit' => $uom,
            'receive_id' => $receive->id,
            'received_at' => $receivedAt ?? now(),
        ]);
    }

    private function bookStock(WorkOrder $wo, PartStock $stock, int $partId, int $workOrderItemId, float $qty, ?string $uom): WorkOrderMaterialBooking
    {
        return WorkOrderMaterialBooking::create([
            'work_order_id' => $wo->id,
            'work_order_item_id' => $workOrderItemId,
            'part_id' => $partId,
            'part_stock_id' => $stock->id,
            'tag' => $stock->tag,
            'qty' => $qty,
            'uom' => $uom,
            'status' => 'booked',
            'booked_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $item */
    private function materialFor(array $item, int $partId): ?array
    {
        return collect($item['materials'])->firstWhere('part_id', $partId);
    }

    public function test_work_orders_list_returns_work_orders_scheduled_for_today(): void
    {
        $this->actingAsApi();
        $wo = $this->makeWorkOrder('WO-SCHED-1');
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 120]);

        $row = $this->listedRow('WO-SCHED-1');

        $this->assertNotNull($row);
        $this->assertSame(120.0, (float) $row['planned_qty']);
        $this->assertSame($this->plantDate(), $row['plan_date']);
    }

    public function test_work_orders_list_hides_work_orders_without_a_plan(): void
    {
        $this->actingAsApi();
        $this->makeWorkOrder('WO-NO-PLAN');

        $this->assertSame([], $this->listedWoNumbers());
    }

    public function test_work_orders_list_hides_work_orders_with_a_zero_target(): void
    {
        $this->actingAsApi();
        $wo = $this->makeWorkOrder('WO-ZERO-D');
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 0, 'd1' => 40]);

        $this->assertSame([], $this->listedWoNumbers());
    }

    public function test_work_orders_list_reads_the_d1_column_for_a_plan_dated_yesterday(): void
    {
        $this->actingAsApi();
        $wo = $this->makeWorkOrder('WO-D1');
        $this->schedulePlanRow($wo, $this->plantDate(-1), ['d' => 500, 'd1' => 40]);

        $row = $this->listedRow('WO-D1');

        $this->assertNotNull($row);
        $this->assertSame(40.0, (float) $row['planned_qty']);
        $this->assertSame($this->plantDate(-1), $row['plan_date']);
    }

    public function test_work_orders_list_reads_the_d2_column_for_a_plan_dated_two_days_ago(): void
    {
        $this->actingAsApi();
        $wo = $this->makeWorkOrder('WO-D2');
        $this->schedulePlanRow($wo, $this->plantDate(-2), ['d' => 500, 'd1' => 400, 'd2' => 25]);

        $row = $this->listedRow('WO-D2');

        $this->assertNotNull($row);
        $this->assertSame(25.0, (float) $row['planned_qty']);
    }

    public function test_work_orders_list_hides_work_orders_outside_the_three_day_window(): void
    {
        $this->actingAsApi();
        $wo = $this->makeWorkOrder('WO-TOO-OLD');
        $this->schedulePlanRow($wo, $this->plantDate(-3), ['d' => 100, 'd1' => 100, 'd2' => 100]);

        $this->assertSame([], $this->listedWoNumbers());
    }

    public function test_work_orders_list_uses_the_largest_target_across_machine_rows(): void
    {
        $this->actingAsApi();
        $wo = $this->makeWorkOrder('WO-MULTI-ROW');
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 100], stepSequence: 1);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 60], stepSequence: 2);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 80], stepSequence: 3);

        $row = $this->listedRow('WO-MULTI-ROW');

        $this->assertNotNull($row);
        $this->assertSame(100.0, (float) $row['planned_qty'], 'Qty D harus nilai terbesar antar baris mesin, bukan penjumlahan.');
    }

    public function test_work_orders_list_reports_issued_and_remaining_qty(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder();
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $item = $wo->items()->firstOrFail();
        $issue = MaterialIssue::create([
            'issue_no' => 'ISS-SCHED-1',
            'work_order_id' => $wo->id,
            'issue_date' => $this->plantDate(),
            'status' => 'posted',
        ]);
        MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $item->id,
            'part_id' => $wo->part_id,
            'qty' => 4,
        ]);

        $row = $this->listedRow($wo->wo_no);

        $this->assertNotNull($row);
        $this->assertSame(4.0, (float) $row['issued_qty']);
        $this->assertSame(6.0, (float) $row['remaining_qty']);
    }

    public function test_work_orders_list_floors_remaining_qty_at_zero(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder();
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $item = $wo->items()->firstOrFail();
        $issue = MaterialIssue::create([
            'issue_no' => 'ISS-SCHED-2',
            'work_order_id' => $wo->id,
            'issue_date' => $this->plantDate(),
            'status' => 'posted',
        ]);
        MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $item->id,
            'part_id' => $wo->part_id,
            'qty' => 15,
        ]);

        $row = $this->listedRow($wo->wo_no);

        $this->assertNotNull($row);
        $this->assertSame(0.0, (float) $row['remaining_qty']);
    }

    public function test_work_orders_list_ignores_cancelled_issue_documents(): void
    {
        $this->actingAsApi();
        $wo = $this->createWorkOrder();
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $item = $wo->items()->firstOrFail();
        $issue = MaterialIssue::create([
            'issue_no' => 'ISS-SCHED-CANCELLED',
            'work_order_id' => $wo->id,
            'issue_date' => $this->plantDate(),
            'status' => 'cancelled',
        ]);
        MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $item->id,
            'part_id' => $wo->part_id,
            'qty' => 4,
        ]);

        $row = $this->listedRow($wo->wo_no);

        $this->assertNotNull($row);
        $this->assertSame(0.0, (float) $row['issued_qty']);
        $this->assertSame(10.0, (float) $row['remaining_qty']);
    }

    public function test_work_orders_list_hides_completed_and_cancelled_work_orders(): void
    {
        $this->actingAsApi();
        $completed = $this->makeWorkOrder('WO-DONE', status: 'completed');
        $this->schedulePlanRow($completed, $this->plantDate(), ['d' => 10]);
        $cancelled = $this->makeWorkOrder('WO-CANCELLED', status: 'cancelled');
        $this->schedulePlanRow($cancelled, $this->plantDate(), ['d' => 10]);

        $this->assertSame([], $this->listedWoNumbers());
    }

    public function test_work_orders_list_includes_in_progress_work_orders(): void
    {
        $this->actingAsApi();
        $wo = $this->makeWorkOrder('WO-RUNNING', status: 'in_progress');
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $this->assertSame(['WO-RUNNING'], $this->listedWoNumbers());
    }

    public function test_work_orders_list_orders_by_routing_step_then_wo_number(): void
    {
        $this->actingAsApi();
        // WO dengan step terkecil dibuat paling akhir, supaya urutan routing
        // berbeda dari urutan pembuatan.
        $earlierStep = $this->makeWorkOrder('WO-ZZ');
        $this->schedulePlanRow($earlierStep, $this->plantDate(), ['d' => 10], stepSequence: 1);
        $laterStep = $this->makeWorkOrder('WO-AA');
        $this->schedulePlanRow($laterStep, $this->plantDate(), ['d' => 10], stepSequence: 5);

        $this->assertSame(['WO-ZZ', 'WO-AA'], $this->listedWoNumbers());
    }

    public function test_work_orders_list_searches_by_work_order_number_and_fg_part_number(): void
    {
        $this->actingAsApi();
        $wo = $this->makeWorkOrder('WO-SEARCH-77');
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $this->assertSame(['WO-SEARCH-77'], $this->listedWoNumbers('?search=SEARCH-77'));
        $this->assertSame(['WO-SEARCH-77'], $this->listedWoNumbers('?search=AAN30056405'));
        $this->assertSame([], $this->listedWoNumbers('?search=TIDAK-ADA-SAMA-SEKALI'));
    }

    public function test_work_orders_list_uses_the_plant_timezone_for_today(): void
    {
        $this->actingAsApi();
        // 17:30 UTC = 00:30 WIB keesokan harinya.
        $this->travelTo(Carbon::parse('2026-03-10 17:30:00', 'UTC'));

        $plantDay = $this->makeWorkOrder('WO-PLANT-DAY');
        $this->schedulePlanRow($plantDay, '2026-03-11', ['d' => 100]);

        $utcDay = $this->makeWorkOrder('WO-UTC-DAY');
        $this->schedulePlanRow($utcDay, '2026-03-10', ['d' => 100]);

        $this->assertSame(['WO-PLANT-DAY'], $this->listedWoNumbers());
    }

    public function test_work_orders_list_keeps_legacy_response_fields(): void
    {
        $this->actingAsApi();
        $wo = $this->makeWorkOrder('WO-LEGACY-1', qty: 25);
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);

        $row = $this->getJson('/api/work-orders')->assertOk()->json('data.0');

        $this->assertSame($wo->id, $row['id']);
        $this->assertSame('planned', $row['status']);
        $this->assertSame(25.0, (float) $row['qty']);
        $this->assertSame($this->plantDate(), $row['planned_date']);
        $this->assertSame('AAN30056405', $row['part']['part_number']);
    }

    public function test_work_orders_list_includes_the_fg_part_model(): void
    {
        $this->actingAsApi();
        $wo = $this->makeWorkOrder('WO-MODEL-1');
        $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 10]);
        Part::whereKey($wo->part_id)->update(['model' => 'MDL-TEST-9']);

        $row = $this->getJson('/api/work-orders')->assertOk()->json('data.0');

        $this->assertSame('MDL-TEST-9', $row['part']['model']);
    }

    public function test_work_orders_list_is_not_truncated_at_twenty_rows(): void
    {
        $this->actingAsApi();
        for ($i = 1; $i <= 25; $i++) {
            $wo = $this->makeWorkOrder('WO-BULK-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
            $this->schedulePlanRow($wo, $this->plantDate(), ['d' => 5]);
        }

        $this->assertCount(25, $this->getJson('/api/work-orders')->assertOk()->json('data'));
    }

    public function test_work_orders_list_reports_released_work_orders_waiting_for_a_schedule(): void
    {
        $this->actingAsApi();
        $wo = $this->makeWorkOrder('WO-WAITING-1', status: 'in_progress');
        $this->schedulePlanRow($wo, $this->plantDate(), []);

        $meta = $this->getJson('/api/work-orders')->assertOk()->json('meta');

        $this->assertSame(1, $meta['waiting_for_plan']);
        $this->assertSame(['WO-WAITING-1'], collect($meta['waiting_work_orders'])->pluck('wo_no')->all());
    }

    public function test_work_orders_list_reports_waiting_work_order_number_and_fg_part(): void
    {
        $this->actingAsApi();
        $wo = $this->makeWorkOrder('WO-WAITING-2', status: 'in_progress');
        $this->schedulePlanRow($wo, $this->plantDate(), []);

        $row = collect($this->getJson('/api/work-orders')->json('meta.waiting_work_orders'))->first();

        $this->assertSame($wo->id, $row['id']);
        $this->assertSame('WO-WAITING-2', $row['wo_no']);
        $this->assertSame('AAN30056405', $row['part']['part_number']);
        $this->assertSame('BASE ASSEMBLY,COMPRESSOR', $row['part']['part_name']);
    }

    public function test_work_orders_list_does_not_report_scheduled_work_orders_as_waiting(): void
    {
        $this->actingAsApi();
        $scheduled = $this->makeWorkOrder('WO-SCHEDULED', status: 'in_progress');
        $this->schedulePlanRow($scheduled, $this->plantDate(), ['d' => 10]);
        $waiting = $this->makeWorkOrder('WO-WAITING-OTHER', status: 'in_progress');
        $this->schedulePlanRow($waiting, $this->plantDate(), []);

        $meta = $this->getJson('/api/work-orders')->assertOk()->json('meta');

        $this->assertSame(1, $meta['waiting_for_plan']);
        $this->assertSame(['WO-WAITING-OTHER'], collect($meta['waiting_work_orders'])->pluck('wo_no')->all());
        $this->assertSame(['WO-SCHEDULED'], $this->listedWoNumbers());
    }

    public function test_work_orders_list_does_not_report_work_orders_without_plan_rows_as_waiting(): void
    {
        $this->actingAsApi();
        $this->makeWorkOrder('WO-NO-ROWS', status: 'in_progress');
        $waiting = $this->makeWorkOrder('WO-WAITING-3', status: 'in_progress');
        $this->schedulePlanRow($waiting, $this->plantDate(), []);

        $meta = $this->getJson('/api/work-orders')->assertOk()->json('meta');

        $this->assertSame(1, $meta['waiting_for_plan']);
        $this->assertSame(['WO-WAITING-3'], collect($meta['waiting_work_orders'])->pluck('wo_no')->all());
    }

    public function test_work_orders_list_does_not_report_unreleased_work_orders_as_waiting(): void
    {
        $this->actingAsApi();
        $planned = $this->makeWorkOrder('WO-STILL-PLANNED');
        $this->schedulePlanRow($planned, $this->plantDate(), []);
        $waiting = $this->makeWorkOrder('WO-WAITING-4', status: 'in_progress');
        $this->schedulePlanRow($waiting, $this->plantDate(), []);

        $meta = $this->getJson('/api/work-orders')->assertOk()->json('meta');

        $this->assertSame(1, $meta['waiting_for_plan']);
        $this->assertSame(['WO-WAITING-4'], collect($meta['waiting_work_orders'])->pluck('wo_no')->all());
    }

    public function test_release_context_returns_needs_and_fifo_tags(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();

        // Dua tag untuk substitute part pertama, received_at berbeda.
        $items = $this->getJson("/api/work-orders/{$wo->id}/release-context")->json('data.items');
        $first = $items[0];
        $main = collect($first['allowed_parts'])->firstWhere('kind', 'main');

        PartStock::create(['part_id' => $main['id'], 'tag' => 'NEWER', 'qty' => 5, 'qty_unit' => $first['uom'], 'received_at' => now()]);
        PartStock::create(['part_id' => $main['id'], 'tag' => 'OLDER', 'qty' => 5, 'qty_unit' => $first['uom'], 'received_at' => now()->subDay()]);

        $context = $this->getJson("/api/work-orders/{$wo->id}/release-context")->assertOk()->json('data.items');
        $tags = collect($context[0]['recommended_tags'])->pluck('tag')->all();

        $this->assertSame('OLDER', $tags[0], 'Rekomendasi harus urut FIFO (tertua dulu).');
    }

    public function test_release_context_returns_allocated_substitute_as_material(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->firstReleaseItem($wo);
        $substituteId = $this->substituteIds($item)[0];
        $this->allocate($item['work_order_item_id'], $substituteId, 60);
        $this->addStock($substituteId, 'ALLOC-TAG-1', 100, $item['uom']);

        $material = collect($this->firstReleaseItem($wo)['materials'])->firstWhere('part_id', $substituteId);

        $this->assertNotNull($material, 'Part hasil alokasi planner harus tampil sebagai material.');
        $this->assertSame('allocation', $material['source']);
        $this->assertSame(60.0, (float) $material['allocation_qty']);
        $this->assertSame('available', $this->firstReleaseItem($wo)['stock_state']);
    }

    public function test_release_context_never_lists_the_bom_main_part_as_material(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->firstReleaseItem($wo);
        $mainId = $this->mainPartId($item);
        $this->addStock($mainId, 'MAIN-TAG-1', 100, $item['uom']);

        $materials = collect($this->firstReleaseItem($wo)['materials']);

        $this->assertNotEmpty($materials);
        $this->assertSame([], $materials->where('part_id', $mainId)->values()->all(), 'Main part BOM tidak boleh tampil sebagai material walau punya stok.');
    }

    public function test_release_context_falls_back_to_substitutes_with_stock_when_no_allocation(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->releaseItemWithSubstitutes($wo);
        [$withStock, $withoutStock] = $this->substituteIds($item);
        $this->addStock($withStock, 'FALLBACK-TAG', 30, $item['uom']);

        $materials = collect($this->firstReleaseItem($wo)['materials']);

        $this->assertSame([$withStock], $materials->pluck('part_id')->all());
        $this->assertSame('substitute', $materials->first()['source']);
        $this->assertNull($materials->first()['allocation_qty']);
        $this->assertSame(0, $materials->where('part_id', $withoutStock)->count());
    }

    public function test_release_context_marks_stock_state_none_when_no_material_has_stock(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();

        $context = $this->firstReleaseItem($wo);

        $this->assertSame('none', $context['stock_state']);
        $this->assertNotEmpty($context['materials'], 'Daftar material sah tetap dikirim agar operator tahu harus melapor.');
    }

    public function test_release_context_reports_material_size_from_the_part(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->firstReleaseItem($wo);
        $substituteId = $this->substituteIds($item)[0];
        $this->allocate($item['work_order_item_id'], $substituteId, 10);
        Part::whereKey($substituteId)->update(['size' => 'SUB-9MM']);

        $material = collect($this->firstReleaseItem($wo)['materials'])->firstWhere('part_id', $substituteId);

        $this->assertSame('SUB-9MM', $material['size']);
    }

    public function test_release_context_falls_back_to_the_item_size_when_the_part_has_none(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->firstReleaseItem($wo);
        $substituteId = $this->substituteIds($item)[0];
        $this->allocate($item['work_order_item_id'], $substituteId, 10);
        Part::whereKey($substituteId)->update(['size' => null]);
        WorkOrderItem::whereKey($item['work_order_item_id'])->update(['size' => 'ITEM-12MM']);

        $material = collect($this->firstReleaseItem($wo)['materials'])->firstWhere('part_id', $substituteId);

        $this->assertSame('ITEM-12MM', $material['size']);
    }

    public function test_release_context_reports_issued_qty_per_item_and_material(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->firstReleaseItem($wo);
        $substituteId = $this->substituteIds($item)[0];
        $this->allocate($item['work_order_item_id'], $substituteId, 40);
        $this->addStock($substituteId, 'ISSUED-TAG', 40, $item['uom']);

        $issue = MaterialIssue::create([
            'issue_no' => 'ISS-CTX-1',
            'work_order_id' => $wo->id,
            'issue_date' => now()->toDateString(),
            'status' => 'posted',
        ]);
        MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $item['work_order_item_id'],
            'part_id' => $substituteId,
            'qty' => 15,
        ]);

        $context = $this->firstReleaseItem($wo);
        $material = collect($context['materials'])->firstWhere('part_id', $substituteId);

        $this->assertSame(15.0, (float) $context['issued_qty']);
        $this->assertSame(15.0, (float) $material['issued_qty']);
    }

    public function test_release_context_orders_materials_by_allocation_size_then_substitutes(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->releaseItemWithSubstitutes($wo);
        [$small, $large] = $this->substituteIds($item);
        $this->allocate($item['work_order_item_id'], $small, 20);
        $this->allocate($item['work_order_item_id'], $large, 80);

        $materials = collect($this->firstReleaseItem($wo)['materials']);

        $this->assertSame([$large, $small], $materials->pluck('part_id')->all());
    }

    public function test_release_context_keeps_legacy_material_fields(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();

        $context = $this->firstReleaseItem($wo);

        foreach (['work_order_item_id', 'required', 'consumed', 'remaining', 'uom', 'part', 'allowed_parts', 'recommended_tags'] as $key) {
            $this->assertArrayHasKey($key, $context);
        }
    }

    public function test_release_context_reports_part_number_on_recommended_tags(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->firstReleaseItem($wo);
        $substituteId = $this->substituteIds($item)[0];
        $this->addStock($substituteId, 'REC-TAG', 20, $item['uom']);
        $expected = Part::whereKey($substituteId)->value('part_number');

        $tag = collect($this->firstReleaseItem($wo)['recommended_tags'])->firstWhere('part_id', $substituteId);

        $this->assertNotNull($tag);
        $this->assertSame($expected, $tag['part_number']);
    }

    public function test_release_context_reports_invoice_and_supplier_on_material_tags(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->firstReleaseItem($wo);
        $substituteId = $this->substituteIds($item)[0];
        $this->addStockWithReceive($substituteId, 'INV-TAG-A', 20, $item['uom'], 'INV-7001', 'PT Sumber Logam');

        $tag = collect($this->materialFor($this->firstReleaseItem($wo), $substituteId)['tags'])
            ->firstWhere('tag', 'INV-TAG-A');

        $this->assertNotNull($tag);
        $this->assertSame('INV-7001', $tag['invoice']);
        $this->assertSame('PT Sumber Logam', $tag['supplier']);
    }

    public function test_release_context_orders_material_tags_by_invoice_date_as_tiebreaker(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->firstReleaseItem($wo);
        $substituteId = $this->substituteIds($item)[0];
        $sameArrival = now()->subDays(3);
        $this->addStockWithReceive($substituteId, 'LATE-INVOICE', 10, $item['uom'], 'INV-LATE', 'PT A', '2026-03-20', $sameArrival);
        $this->addStockWithReceive($substituteId, 'EARLY-INVOICE', 10, $item['uom'], 'INV-EARLY', 'PT B', '2026-03-10', $sameArrival);

        $tags = collect($this->materialFor($this->firstReleaseItem($wo), $substituteId)['tags'])->pluck('tag');
        $ordered = $tags->filter(fn ($tag) => in_array($tag, ['LATE-INVOICE', 'EARLY-INVOICE'], true))->values()->all();

        $this->assertSame(['EARLY-INVOICE', 'LATE-INVOICE'], $ordered);
    }

    public function test_release_context_keeps_material_tags_without_invoice(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->firstReleaseItem($wo);
        $substituteId = $this->substituteIds($item)[0];
        $this->addStock($substituteId, 'NO-INVOICE-TAG', 15, $item['uom']);

        $tag = collect($this->materialFor($this->firstReleaseItem($wo), $substituteId)['tags'])
            ->firstWhere('tag', 'NO-INVOICE-TAG');

        $this->assertNotNull($tag, 'Tag tanpa invoice tetap harus terkirim.');
        $this->assertNull($tag['invoice']);
        $this->assertNull($tag['supplier']);
    }

    public function test_release_context_returns_all_material_tags_without_truncation(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->firstReleaseItem($wo);
        $substituteId = $this->substituteIds($item)[0];
        $tags = [];
        for ($i = 1; $i <= 7; $i++) {
            $tags[] = 'BULK-TAG-'.$i;
            $this->addStock($substituteId, 'BULK-TAG-'.$i, 5, $item['uom']);
        }

        $material = $this->materialFor($this->firstReleaseItem($wo), $substituteId);

        $this->assertCount(7, collect($material['tags'])->whereIn('tag', $tags));
    }

    public function test_release_context_reduces_material_tag_qty_by_active_booking(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->firstReleaseItem($wo);
        $substituteId = $this->substituteIds($item)[0];
        $stock = $this->addStock($substituteId, 'PARTIAL-BOOK-TAG', 10, $item['uom']);
        $this->bookStock($wo, $stock, $substituteId, $item['work_order_item_id'], 4, $item['uom']);

        $tag = collect($this->materialFor($this->firstReleaseItem($wo), $substituteId)['tags'])
            ->firstWhere('tag', 'PARTIAL-BOOK-TAG');

        $this->assertNotNull($tag);
        $this->assertSame(6.0, (float) $tag['qty']);
    }

    public function test_release_context_excludes_material_tags_with_no_available_qty(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $item = $this->firstReleaseItem($wo);
        $substituteId = $this->substituteIds($item)[0];
        $stock = $this->addStock($substituteId, 'BOOKED-OUT-TAG', 10, $item['uom']);
        $this->bookStock($wo, $stock, $substituteId, $item['work_order_item_id'], 10, $item['uom']);

        $material = $this->materialFor($this->firstReleaseItem($wo), $substituteId);

        $this->assertNotContains('BOOKED-OUT-TAG', collect($material['tags'])->pluck('tag')->all());
    }

    public function test_resolve_tag_returns_stock_info(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $scans = $this->seedStockAndBuildScans($wo);
        $tag = $scans[0]['scans'][0]['tag'];

        $this->postJson('/api/stock-tags/resolve', ['tag' => $tag])
            ->assertOk()
            ->assertJsonPath('data.tag', $tag)
            ->assertJsonPath('data.qty', (float) $scans[0]['scans'][0]['qty']);

        $this->postJson('/api/stock-tags/resolve', ['tag' => 'TIDAK-ADA'])
            ->assertNotFound();
    }

    public function test_resolve_tag_requires_stock_issue_permission(): void
    {
        $qc = User::where('email', 'qc@geumcheon.local')->firstOrFail();
        $this->actingAs($qc, 'sanctum');

        $this->postJson('/api/stock-tags/resolve', ['tag' => 'APA-SAJA'])->assertForbidden();
    }

    public function test_release_requires_idempotency_key(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $scans = $this->seedStockAndBuildScans($wo);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'items' => $scans,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame('planned', $wo->fresh()->status);
    }

    public function test_login_limits_device_name_length(): void
    {
        $this->app['auth']->forgetGuards();

        $response = $this->postJson('/api/auth/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ], ['X-Device-Name' => str_repeat('A', 500)]);

        $response->assertOk();
        $tokenName = $this->admin->tokens()->latest('id')->firstOrFail()->name;
        $this->assertLessThanOrEqual(100, mb_strlen($tokenName));
    }

    public function test_release_consumes_scanned_tags_and_posts_output(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $scans = $this->seedStockAndBuildScans($wo);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'test-release-1',
            'received_by' => 'Operator',
            'items' => $scans,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $wo->refresh();
        $this->assertSame('in_progress', $wo->status);

        // Dokumen issue tercatat.
        $issue = MaterialIssue::where('idempotency_key', 'test-release-1')->firstOrFail();
        $this->assertGreaterThan(0, $issue->items()->count());

        // Release hanya BOOKING: stok tag belum berkurang, hanya dikunci.
        $firstTag = $scans[0]['scans'][0]['tag'];
        $this->assertGreaterThan(0.0, (float) PartStock::where('tag', $firstTag)->sum('qty'));
        $this->assertDatabaseHas('work_order_material_bookings', [
            'work_order_id' => $wo->id,
            'tag' => $firstTag,
            'status' => 'booked',
        ]);

        // Output FG lahir dari Production Result.
        $fgStock = PartStock::where('part_id', $wo->part_id)->where('qty', '>', 0)->sum('qty');
        $this->assertEqualsWithDelta(0.0, (float) $fgStock, 0.001);
    }

    public function test_release_dispatches_one_safe_issue_event_after_commit(): void
    {
        Event::fake();

        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $scans = $this->seedStockAndBuildScans($wo);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'test-event-1',
            'received_by' => 'Operator',
            'items' => $scans,
        ])->assertOk();

        Event::assertDispatched(MaterialIssuePosted::class, function (MaterialIssuePosted $event): bool {
            return $event->payload['status'] === 'posted'
                && $event->payload['item_count'] > 0
                && $event->payload['tag_count'] > 0
                && $event->payload['received_by'] === 'Operator'
                && isset($event->payload['qty_by_uom']);
        });
        Event::assertDispatchedTimes(MaterialIssuePosted::class, 1);
    }

    public function test_release_queues_one_broadcast_after_commit(): void
    {
        Queue::fake();

        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $scans = $this->seedStockAndBuildScans($wo);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'test-broadcast-1',
            'items' => $scans,
        ])->assertOk();

        Queue::assertPushed(BroadcastEvent::class, function (BroadcastEvent $job): bool {
            return $job->event instanceof MaterialIssuePosted
                && $job->event->broadcastAs() === 'material-issue.posted';
        });
    }

    public function test_release_is_idempotent(): void
    {
        Event::fake();

        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $scans = $this->seedStockAndBuildScans($wo);

        $payload = ['idempotency_key' => 'test-idem-1', 'items' => $scans];

        $first = $this->postJson("/api/work-orders/{$wo->id}/release", $payload)->assertOk()->json('data.issue_no');
        $second = $this->postJson("/api/work-orders/{$wo->id}/release", $payload)->assertOk()->json('data.issue_no');

        $this->assertSame($first, $second, 'Retry dengan idempotency_key sama harus mengembalikan issue yang sama.');
        $this->assertSame(1, MaterialIssue::where('idempotency_key', 'test-idem-1')->count());
        Event::assertDispatchedTimes(MaterialIssuePosted::class, 1);
    }

    public function test_release_rejects_unrelated_tag(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $items = $this->getJson("/api/work-orders/{$wo->id}/release-context")->json('data.items');

        $outsider = Part::where('part_number', '4000W4A003A')->firstOrFail();
        PartStock::create(['part_id' => $outsider->id, 'tag' => 'OUTSIDER', 'qty' => 99, 'qty_unit' => 'PCS', 'received_at' => now()]);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'test-unrelated-1',
            'items' => [[
                'work_order_item_id' => $items[0]['work_order_item_id'],
                'scans' => [['tag' => 'OUTSIDER', 'qty' => 1, 'part_id' => $outsider->id]],
            ]],
        ])->assertStatus(422);

        $this->assertSame('planned', $wo->fresh()->status);
    }

    public function test_release_rollback_does_not_dispatch_issue_event(): void
    {
        Event::fake();

        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $scans = $this->seedStockAndBuildScans($wo);
        $tag = $scans[0]['scans'][0]['tag'];
        PartStock::where('tag', $tag)->update(['qty' => 0]);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'test-rollback-event-1',
            'items' => $scans,
        ])->assertStatus(422);

        Event::assertNotDispatched(MaterialIssuePosted::class);
        $this->assertSame('planned', $wo->fresh()->status);
        $this->assertDatabaseMissing('material_issues', ['idempotency_key' => 'test-rollback-event-1']);
    }

    public function test_release_rejects_over_scan(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $items = $this->getJson("/api/work-orders/{$wo->id}/release-context")->json('data.items');

        $first = $items[0];
        $main = collect($first['allowed_parts'])->firstWhere('kind', 'main');
        PartStock::create(['part_id' => $main['id'], 'tag' => 'BIG', 'qty' => 999, 'qty_unit' => $first['uom'], 'received_at' => now()]);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'test-overscan-1',
            'items' => [[
                'work_order_item_id' => $first['work_order_item_id'],
                'scans' => [['tag' => 'BIG', 'qty' => (float) $first['required'] + 1, 'part_id' => (int) $main['id']]],
            ]],
        ])->assertStatus(422);

        $this->assertSame('planned', $wo->fresh()->status);
    }

    public function test_release_stores_invoice_and_supplier_from_receive(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $items = $this->getJson("/api/work-orders/{$wo->id}/release-context")->json('data.items');
        $first = $items[0];
        $main = collect($first['allowed_parts'])->firstWhere('kind', 'main');

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARR-INV-TEST',
            'invoice_no' => 'INV-9001',
            'status' => 'pending',
            'is_local' => false,
        ]);
        $arrivalItem = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $main['id'],
            'qty_goods' => 100,
            'unit_goods' => $first['uom'],
        ]);
        $receive = IncomingReceive::create([
            'arrival_item_id' => $arrivalItem->id,
            'part_id' => $main['id'],
            'tag' => 'INV-TAG',
            'qty' => (float) $first['required'],
            'qty_unit' => $first['uom'],
            'invoice_no' => 'INV-9001',
        ]);
        PartStock::create([
            'part_id' => $main['id'],
            'tag' => 'INV-TAG',
            'qty' => (float) $first['required'],
            'qty_unit' => $first['uom'],
            'receive_id' => $receive->id,
            'received_at' => now(),
        ]);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'test-invoice-1',
            'items' => [[
                'work_order_item_id' => $first['work_order_item_id'],
                'scans' => [['tag' => 'INV-TAG', 'qty' => (float) $first['required'], 'part_id' => (int) $main['id']]],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('material_issue_items', [
            'tag' => 'INV-TAG',
            'invoice' => 'INV-9001',
        ]);
    }

    public function test_resolve_location_returns_code_and_name(): void
    {
        $this->actingAsApi();
        Location::create(['code' => 'RAK-A1', 'name' => 'Rak A1']);

        $this->postJson('/api/locations/resolve', ['location_code' => 'rak-a1'])
            ->assertOk()
            ->assertJsonPath('data.location_code', 'RAK-A1')
            ->assertJsonPath('data.location_name', 'Rak A1');
    }

    public function test_resolve_location_returns_404_for_an_unknown_code(): void
    {
        $this->actingAsApi();

        $this->postJson('/api/locations/resolve', ['location_code' => 'TIDAK-ADA'])->assertNotFound();
    }

    public function test_resolve_location_returns_404_for_an_inactive_location(): void
    {
        $this->actingAsApi();
        Location::create(['code' => 'RAK-OFF', 'name' => 'Rak Nonaktif', 'is_active' => false]);

        $this->postJson('/api/locations/resolve', ['location_code' => 'RAK-OFF'])->assertNotFound();
    }

    public function test_resolve_location_requires_the_stock_issue_permission(): void
    {
        $qc = User::where('email', 'qc@geumcheon.local')->firstOrFail();
        $this->actingAs($qc, 'sanctum');

        $this->postJson('/api/locations/resolve', ['location_code' => 'RAK-A1'])->assertForbidden();
    }

    public function test_release_stores_the_scanned_location(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $scans = $this->seedStockAndBuildScans($wo);
        Location::create(['code' => 'RAK-B2', 'name' => 'Rak B2']);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'test-loc-1',
            'location_code' => 'RAK-B2',
            'items' => $scans,
        ])->assertOk();

        $this->assertDatabaseHas('material_issues', [
            'idempotency_key' => 'test-loc-1',
            'location_code' => 'RAK-B2',
        ]);
    }

    public function test_release_succeeds_without_a_location(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $scans = $this->seedStockAndBuildScans($wo);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'test-loc-2',
            'items' => $scans,
        ])->assertOk();

        $this->assertDatabaseHas('material_issues', [
            'idempotency_key' => 'test-loc-2',
            'location_code' => null,
        ]);
    }

    public function test_release_rejects_an_unknown_location(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $scans = $this->seedStockAndBuildScans($wo);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'test-loc-3',
            'location_code' => 'TIDAK-ADA',
            'items' => $scans,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');

        $this->assertSame('planned', $wo->fresh()->status);
    }

    public function test_release_allows_a_location_that_differs_from_receiving(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $items = $this->getJson("/api/work-orders/{$wo->id}/release-context")->assertOk()->json('data.items');
        $first = $items[0];
        $main = collect($first['allowed_parts'])->firstWhere('kind', 'main');

        Location::create(['code' => 'RAK-ASAL', 'name' => 'Rak Asal']);
        Location::create(['code' => 'RAK-LAIN', 'name' => 'Rak Lain']);
        $this->seedStockWithLocation($main['id'], 'LOC-TAG-1', (float) $first['required'], $first['uom'], 'RAK-ASAL');

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'test-loc-4',
            'location_code' => 'RAK-LAIN',
            'items' => [[
                'work_order_item_id' => $first['work_order_item_id'],
                'scans' => [['tag' => 'LOC-TAG-1', 'qty' => (float) $first['required'], 'part_id' => (int) $main['id']]],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('material_issues', [
            'idempotency_key' => 'test-loc-4',
            'location_code' => 'RAK-LAIN',
        ]);
    }

    public function test_resolve_tag_reports_the_expected_rack(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $items = $this->getJson("/api/work-orders/{$wo->id}/release-context")->assertOk()->json('data.items');
        $first = $items[0];
        $main = collect($first['allowed_parts'])->firstWhere('kind', 'main');

        $this->seedStockWithLocation($main['id'], 'LOC-TAG-2', 5, $first['uom'], 'RAK-ASAL');

        $this->postJson('/api/stock-tags/resolve', ['tag' => 'LOC-TAG-2'])
            ->assertOk()
            ->assertJsonPath('data.location_code', 'RAK-ASAL');
    }

    private function seedStockWithLocation(int $partId, string $tag, float $qty, ?string $uom, string $locationCode): PartStock
    {
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARR-'.$tag,
            'status' => 'pending',
            'is_local' => false,
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
            'location_code' => $locationCode,
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

    public function test_result_context_and_store_result(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $scans = $this->seedStockAndBuildScans($wo);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'idempotency_key' => 'res-ctx-1',
            'items' => $scans,
        ])->assertOk();

        $steps = $this->getJson("/api/work-orders/{$wo->id}/result-context")
            ->assertOk()
            ->json('data.steps');

        $this->assertNotEmpty($steps);
        $first = $steps[0];

        $this->postJson("/api/work-orders/{$wo->id}/results", [
            'parent_part_id' => $first['parent_part_id'],
            'qty_good' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('data.parent_part_id', $first['parent_part_id']);

        $after = $this->getJson("/api/work-orders/{$wo->id}/result-context")->json('data.steps');
        $this->assertEqualsWithDelta(10.0, (float) $after[0]['produced_qty'], 0.001);
    }
}
