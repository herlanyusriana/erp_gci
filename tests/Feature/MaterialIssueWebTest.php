<?php

namespace Tests\Feature;

use App\Models\MaterialIssue;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MaterialIssueWebTest extends TestCase
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

    private function postedIssue(): MaterialIssue
    {
        $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $this->post(route('work-orders.store'), [
            'part_id' => $fg->id,
            'qty' => 10,
            'planned_date' => now()->toDateString(),
        ])->assertRedirect();

        /** @var WorkOrder $wo */
        $wo = WorkOrder::latest('id')->firstOrFail();

        $items = $this->getJson("/api/work-orders/{$wo->id}/release-context")->assertOk()->json('data.items');

        $scans = [];
        foreach ($items as $item) {
            $main = collect($item['allowed_parts'])->firstWhere('kind', 'main');
            $tag = 'W-'.$item['work_order_item_id'];
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

        $this->postJson("/api/work-orders/{$wo->id}/release", ['items' => $scans])->assertOk();

        return MaterialIssue::latest('id')->firstOrFail();
    }

    public function test_material_issue_pages_render(): void
    {
        $issue = $this->postedIssue();

        $this->get(route('material-issues.index'))->assertOk();
        $this->get(route('material-issues.show', $issue))->assertOk();
        $this->get(route('material-issues.print', $issue))
            ->assertOk()
            ->assertSee($issue->issue_no);
    }

    public function test_outgoing_launcher_renders(): void
    {
        $this->get(route('outgoing-data'))->assertOk();
    }

    public function test_material_issue_pages_require_stock_issue_permission(): void
    {
        $issue = $this->postedIssue();
        $qc = User::where('email', 'qc@geumcheon.local')->firstOrFail();

        $this->actingAs($qc);

        $this->get(route('material-issues.index'))->assertForbidden();
        $this->get(route('material-issues.show', $issue))->assertForbidden();
        $this->get(route('material-issues.print', $issue))->assertForbidden();
    }

    public function test_issue_out_channel_allows_stock_issue_users_only(): void
    {
        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-issue-out-monitoring',
        ])->assertOk()->assertJsonStructure(['auth']);

        $qc = User::where('email', 'qc@geumcheon.local')->firstOrFail();
        $this->actingAs($qc);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-issue-out-monitoring',
        ])->assertForbidden();
    }

    public function test_material_issue_index_exposes_wib_filters_and_summary(): void
    {
        $todayIssue = $this->postedIssue();
        $yesterdayIssue = $this->postedIssue();
        $today = now('Asia/Jakarta')->toDateString();
        $yesterday = now('Asia/Jakarta')->subDay()->toDateString();

        $todayIssue->update(['issue_date' => $today]);
        $yesterdayIssue->update(['issue_date' => $yesterday]);

        $this->get(route('material-issues.index', [
            'date_from' => $today,
            'date_to' => $today,
            'operator_id' => $this->admin->id,
            'status' => 'posted',
            'search' => $todayIssue->issue_no,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Outgoing/MaterialIssue/Index')
                ->where('filters.date_from', $today)
                ->where('filters.date_to', $today)
                ->where('filters.operator_id', (string) $this->admin->id)
                ->where('filters.status', 'posted')
                ->where('filters.search', $todayIssue->issue_no)
                ->where('issues.total', 1)
                ->where('summary.issue_count', 1)
                ->where('summary.work_order_count', 1)
                ->where('summary.tag_count', $todayIssue->items()->whereNotNull('tag')->distinct('tag')->count('tag'))
                ->has('summary.qty_by_uom')
                ->has('operators'));
    }
}
