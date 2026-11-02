<?php

namespace Tests\Feature\Api;

use App\Models\Machine;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProductionSessionApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Machine $machine;

    private WorkOrder $wo;

    private Part $parentPart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum');

        $this->machine = Machine::create([
            'machine_code' => 'SESS-'.uniqid(),
            'machine_name' => 'Session Machine',
            'is_active' => true,
        ]);

        $this->parentPart = Part::where('part_number', 'PINCB01')->firstOrFail();
        $this->wo = WorkOrder::create([
            'wo_no' => 'WO-SESS-'.uniqid(),
            'part_id' => Part::where('part_number', 'AAN30056405')->value('id'),
            'qty' => 1000,
            'status' => 'in_progress',
        ]);

        WorkOrderItem::create([
            'work_order_id' => $this->wo->id,
            'sequence' => 1,
            'machine_id' => $this->machine->id,
            'parent_part_id' => $this->parentPart->id,
            'parent_qty' => 1,
            'parent_uom' => 'PCS',
            'child_part_id' => Part::where('part_number', 'CBKG07256C')->value('id'),
            'selected_part_id' => Part::where('part_number', 'CBKG07256C')->value('id'),
            'child_qty' => 1,
            'uom_rm' => 'KGM',
            'qty_required' => 1000,
        ]);
    }

    public function test_returns_machine_parts_shift_and_periods(): void
    {
        // 09:30 WIB = 02:30 UTC.
        $this->travelTo(Carbon::parse('2026-01-15 02:30:00', 'UTC'));

        $response = $this->getJson("/api/machines/{$this->machine->id}/production-session")->assertOk();

        $data = $response->json('data');

        $this->assertSame($this->machine->id, $data['machine']['id']);
        $this->assertSame('1', $data['shift']);
        $this->assertNotEmpty($data['periods']);
        $this->assertSame('07:30 - 08:30', $data['periods'][0]['label']);
        $this->assertSame('08:30 - 09:30', $data['periods'][1]['label']);
        $this->assertCount(2, $data['periods']);

        $this->assertNotEmpty($data['parts']);
        $this->assertSame($this->parentPart->id, $data['parts'][0]['part_id']);
        $this->assertSame($this->wo->wo_no, $data['parts'][0]['wo_no']);
    }

    public function test_shift_follows_the_clock_at_the_plant_timezone(): void
    {
        // 20:00 WIB = 13:00 UTC -> shift 2.
        $this->travelTo(Carbon::parse('2026-01-15 13:00:00', 'UTC'));

        $data = $this->getJson("/api/machines/{$this->machine->id}/production-session")->assertOk()->json('data');

        $this->assertSame('2', $data['shift']);
        $this->assertSame('15:30 - 16:30', $data['periods'][0]['label']);
    }

    public function test_shift_3_applies_after_midnight(): void
    {
        // 01:00 WIB = 18:00 UTC hari sebelumnya.
        $this->travelTo(Carbon::parse('2026-01-14 18:00:00', 'UTC'));

        $data = $this->getJson("/api/machines/{$this->machine->id}/production-session")->assertOk()->json('data');

        $this->assertSame('3', $data['shift']);
    }

    public function test_only_periods_that_already_ended_are_offered(): void
    {
        // 08:00 WIB = 01:00 UTC: belum ada periode yang selesai di shift 1.
        $this->travelTo(Carbon::parse('2026-01-15 01:00:00', 'UTC'));

        $data = $this->getJson("/api/machines/{$this->machine->id}/production-session")->assertOk()->json('data');

        $this->assertSame('1', $data['shift']);
        $this->assertSame([], $data['periods']);
    }

    public function test_unknown_machine_returns_not_found(): void
    {
        $this->getJson('/api/machines/999999/production-session')->assertNotFound();
    }
}
