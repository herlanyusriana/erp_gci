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
        // Seluruh 7 periode shift selalu dikirim agar operator melihat shift
        // utuh; status tiap periode menentukan mana yang boleh dilaporkan.
        $this->assertCount(7, $data['periods']);
        $this->assertSame('07:30 - 08:30', $data['periods'][0]['label']);
        $this->assertSame('08:30 - 09:30', $data['periods'][1]['label']);
        $this->assertTrue($data['periods'][0]['can_report']);
        $this->assertTrue($data['periods'][1]['can_report']);
        $this->assertFalse($data['periods'][2]['can_report']);

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

    public function test_periods_that_have_not_ended_are_flagged_not_reportable(): void
    {
        // 08:00 WIB = 01:00 UTC: belum ada periode yang selesai di shift 1, dan
        // periode 07:30-08:30 sedang berjalan.
        $this->travelTo(Carbon::parse('2026-01-15 01:00:00', 'UTC'));

        $data = $this->getJson("/api/machines/{$this->machine->id}/production-session")->assertOk()->json('data');

        $this->assertSame('1', $data['shift']);
        // Semua periode tetap dikirim supaya operator melihat shift utuh.
        $this->assertCount(7, $data['periods']);
        $this->assertFalse($data['periods'][0]['can_report']);
        $this->assertTrue($data['periods'][0]['in_progress']);
        // Periode yang belum mulai: belum bisa dilapor dan bukan sedang berjalan.
        $this->assertFalse($data['periods'][6]['can_report']);
        $this->assertFalse($data['periods'][6]['in_progress']);

        $reportable = array_values(array_filter($data['periods'], fn (array $p): bool => $p['can_report']));
        $this->assertSame([], $reportable);
    }

    public function test_unknown_machine_returns_not_found(): void
    {
        $this->getJson('/api/machines/999999/production-session')->assertNotFound();
    }

    public function test_shift_can_be_chosen_explicitly(): void
    {
        // 09:30 WIB: shift berjalan = 1, tetapi operator memilih shift 2.
        $this->travelTo(Carbon::parse('2026-01-15 02:30:00', 'UTC'));

        $data = $this->getJson("/api/machines/{$this->machine->id}/production-session?shift=2")
            ->assertOk()->json('data');

        $this->assertSame('2', $data['shift']);
    }

    public function test_chosen_shift_holds_no_reportable_period_while_running(): void
    {
        // 16:00 WIB: di shift 2, periode 15:30-16:30 belum selesai.
        $this->travelTo(Carbon::parse('2026-01-15 09:00:00', 'UTC'));

        $data = $this->getJson("/api/machines/{$this->machine->id}/production-session?shift=2")
            ->assertOk()->json('data');

        $this->assertSame('2', $data['shift']);
        $this->assertCount(7, $data['periods']);
        $this->assertFalse($data['periods'][0]['can_report']);
        $this->assertTrue($data['periods'][0]['in_progress']);
    }

    public function test_an_unknown_shift_is_rejected(): void
    {
        $this->getJson("/api/machines/{$this->machine->id}/production-session?shift=9")
            ->assertStatus(422)
            ->assertJsonValidationErrors('shift');
    }

    public function test_all_shifts_are_returned_so_the_app_can_offer_a_chooser(): void
    {
        $data = $this->getJson("/api/machines/{$this->machine->id}/production-session")
            ->assertOk()->json('data');

        $this->assertSame(['1', '2', '3'], $data['shifts']);
    }

    public function test_tpl_machine_only_returns_final_wip_part_and_excludes_intermediate_steps(): void
    {
        $tpl = Machine::create([
            'machine_code' => 'TPL-DONGSHIN-'.uniqid(),
            'machine_name' => 'TPL DONGSHIN',
            'is_active' => true,
        ]);

        $wip1 = Part::where('part_number', 'AGU30018303-WIP1')->firstOrFail();
        $wip2 = Part::where('part_number', 'AGU30018303-WIP2')->firstOrFail();
        $wip3 = Part::where('part_number', 'AGU30018303-WIP3')->firstOrFail();
        $rm = Part::where('part_number', 'BPSH0257021487')->firstOrFail();

        $wo = WorkOrder::create([
            'wo_no' => 'WO-TPL-'.uniqid(),
            'part_id' => Part::where('part_number', 'AGU30018303')->value('id'),
            'qty' => 500,
            'status' => 'in_progress',
        ]);

        // Step 1: RM -> WIP1 di TPL
        WorkOrderItem::create([
            'work_order_id' => $wo->id, 'sequence' => 1, 'machine_id' => $tpl->id,
            'parent_part_id' => $wip1->id, 'child_part_id' => $rm->id, 'uom_rm' => 'SHEET', 'qty_required' => 500,
        ]);
        // Step 2: WIP1 -> WIP2 di TPL
        WorkOrderItem::create([
            'work_order_id' => $wo->id, 'sequence' => 2, 'machine_id' => $tpl->id,
            'parent_part_id' => $wip2->id, 'child_part_id' => $wip1->id, 'uom_rm' => 'PCS', 'qty_required' => 500,
        ]);
        // Step 3: WIP2 -> WIP3 di TPL (Final step pada TPL ini)
        WorkOrderItem::create([
            'work_order_id' => $wo->id, 'sequence' => 3, 'machine_id' => $tpl->id,
            'parent_part_id' => $wip3->id, 'child_part_id' => $wip2->id, 'uom_rm' => 'PCS', 'qty_required' => 500,
        ]);

        $response = $this->getJson("/api/machines/{$tpl->id}/production-session")->assertOk();
        $parts = $response->json('data.parts');

        // Dropdown APK harus HANYA berisi WIP3 (final WIP), WIP1 dan WIP2 harus otomatis tersembunyi
        $this->assertCount(1, $parts);
        $this->assertSame($wip3->id, $parts[0]['part_id']);
        $this->assertSame('AGU30018303-WIP3', $parts[0]['part_number']);
    }

    public function test_stores_lists_and_deletes_qdc_and_downtime_for_a_machine_session(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 03:00:00', 'UTC')); // 10:00 WIB -> shift 1

        $res = $this->postJson("/api/machines/{$this->machine->id}/downtimes", [
            'shift' => '1',
            'category' => 'qdc',
            'start_time' => '08:40',
            'end_time' => '09:15',
            'work_order_id' => $this->wo->id,
            'part_id' => $this->parentPart->id,
            'notes' => 'Ganti dies PINCB01',
        ])->assertOk();

        $downtimeId = $res->json('data.id');
        $this->assertSame(35, $res->json('data.duration_minutes'));
        $this->assertSame('qdc', $res->json('data.category'));
        $this->assertSame('qdc', $res->json('data.reason'));

        $session = $this->getJson("/api/machines/{$this->machine->id}/production-session?shift=1")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $session['downtimes']);
        $this->assertSame($downtimeId, $session['downtimes'][0]['id']);
        $this->assertSame('08:40', $session['downtimes'][0]['start_time']);
        $this->assertSame('09:15', $session['downtimes'][0]['end_time']);
        $this->assertSame(35, $session['downtimes'][0]['duration_minutes']);

        $this->deleteJson("/api/machines/{$this->machine->id}/downtimes/{$downtimeId}")
            ->assertOk();

        $afterDelete = $this->getJson("/api/machines/{$this->machine->id}/production-session?shift=1")
            ->assertOk()
            ->json('data');
        $this->assertSame([], $afterDelete['downtimes']);
    }

    public function test_shift_3_downtime_supports_crossing_midnight_and_rejects_invalid_range(): void
    {
        $this->travelTo(Carbon::parse('2026-01-14 18:00:00', 'UTC')); // 01:00 WIB -> shift 3

        $res = $this->postJson("/api/machines/{$this->machine->id}/downtimes", [
            'shift' => '3',
            'category' => 'downtime',
            'reason' => 'die_trouble',
            'start_time' => '23:50',
            'end_time' => '00:25',
            'notes' => 'Sensor die macet',
        ])->assertOk();

        $this->assertSame(35, $res->json('data.duration_minutes'));
        $this->assertSame('die_trouble', $res->json('data.reason'));

        // Di shift 1, jam selesai sebelum jam mulai harus ditolak.
        $this->postJson("/api/machines/{$this->machine->id}/downtimes", [
            'shift' => '1',
            'category' => 'downtime',
            'reason' => 'machine_breakdown',
            'start_time' => '10:30',
            'end_time' => '09:30',
        ])->assertStatus(422)->assertJsonValidationErrors('end_time');
    }
}
