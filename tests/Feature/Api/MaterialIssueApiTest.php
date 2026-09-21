<?php

namespace Tests\Feature\Api;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\MaterialIssue;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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

    public function test_release_is_idempotent(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $scans = $this->seedStockAndBuildScans($wo);

        $payload = ['idempotency_key' => 'test-idem-1', 'items' => $scans];

        $first = $this->postJson("/api/work-orders/{$wo->id}/release", $payload)->assertOk()->json('data.issue_no');
        $second = $this->postJson("/api/work-orders/{$wo->id}/release", $payload)->assertOk()->json('data.issue_no');

        $this->assertSame($first, $second, 'Retry dengan idempotency_key sama harus mengembalikan issue yang sama.');
        $this->assertSame(1, MaterialIssue::where('idempotency_key', 'test-idem-1')->count());
    }

    public function test_release_rejects_unrelated_tag(): void
    {
        $wo = $this->createWorkOrder();
        $this->actingAsApi();
        $items = $this->getJson("/api/work-orders/{$wo->id}/release-context")->json('data.items');

        $outsider = Part::where('part_number', '4000W4A003A')->firstOrFail();
        PartStock::create(['part_id' => $outsider->id, 'tag' => 'OUTSIDER', 'qty' => 99, 'qty_unit' => 'PCS', 'received_at' => now()]);

        $this->postJson("/api/work-orders/{$wo->id}/release", [
            'items' => [[
                'work_order_item_id' => $items[0]['work_order_item_id'],
                'scans' => [['tag' => 'OUTSIDER', 'qty' => 1, 'part_id' => $outsider->id]],
            ]],
        ])->assertStatus(422);

        $this->assertSame('planned', $wo->fresh()->status);
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
