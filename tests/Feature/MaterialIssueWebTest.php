<?php

namespace Tests\Feature;

use App\Models\MaterialIssue;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
