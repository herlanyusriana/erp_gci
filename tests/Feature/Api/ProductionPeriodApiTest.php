<?php

namespace Tests\Feature\Api;

use App\Models\ProductionResult;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProductionPeriodApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum');

        require_once __DIR__.'/../MachineMaterialConsumptionTest.php';

        // Fixture yang sudah terbukti: WO + item + stok + issue + receipt di mesin.
        $this->fixture = machineMaterialFixture(50);
        receiveMachineMaterial($this->fixture);
    }

    private function wo(): WorkOrder
    {
        return $this->fixture['wo'];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'parent_part_id' => $this->fixture['fg']->id,
            'qty_good' => 5,
            'qty_reject' => 1,
            'machine_id' => $this->fixture['machine']->id,
            'shift' => '1',
            'period_start' => '08:30',
            'period_end' => '09:30',
        ], $overrides);
    }

    public function test_stores_good_and_reject_with_their_period(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 03:00:00', 'UTC'));

        $this->postJson("/api/work-orders/{$this->wo()->id}/results", $this->payload())->assertOk();

        $result = ProductionResult::where('work_order_id', $this->wo()->id)->sole();

        $this->assertEqualsWithDelta(5.0, (float) $result->qty_good, 1e-6);
        $this->assertEqualsWithDelta(1.0, (float) $result->qty_reject, 1e-6);
        $this->assertSame('1', $result->shift);
        $this->assertSame('08:30:00', (string) $result->period_start);
        $this->assertSame('09:30:00', (string) $result->period_end);
        $this->assertSame('pending', $result->label_status);
    }

    public function test_rejects_a_report_for_a_period_that_has_not_happened_yet(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 02:00:00', 'UTC'));

        $this->postJson("/api/work-orders/{$this->wo()->id}/results", $this->payload([
            'period_start' => '14:30',
            'period_end' => '15:30',
        ]))->assertStatus(422)->assertJsonValidationErrors('period_start');
    }

    public function test_rejects_a_shift_three_period_that_has_not_happened_yet(): void
    {
        // 23:45 WIB hari ini: shift 3 baru 15 menit. Periode dinihari 06:30-07:30
        // belum terjadi (besok pagi), jadi harus ditolak. Sebelum diperbaiki,
        // jam periode ditempel ke tanggal hari ini sehingga lolos ke database.
        $this->travelTo(Carbon::parse('2026-11-03 23:45:00', 'Asia/Jakarta'));

        $this->postJson("/api/work-orders/{$this->wo()->id}/results", $this->payload([
            'shift' => '3',
            'period_start' => '06:30',
            'period_end' => '07:30',
        ]))->assertStatus(422)->assertJsonValidationErrors('period_start');
    }

    public function test_accepts_a_shift_three_period_that_already_ended_across_midnight(): void
    {
        // 01:00 WIB: periode 23:30-00:30 milik shift 3 sudah tutup, boleh dilapor.
        $this->travelTo(Carbon::parse('2026-11-04 01:00:00', 'Asia/Jakarta'));

        $this->postJson("/api/work-orders/{$this->wo()->id}/results", $this->payload([
            'shift' => '3',
            'period_start' => '23:30',
            'period_end' => '00:30',
        ]))->assertOk();
    }

    public function test_rejects_a_period_whose_end_is_not_after_its_start(): void
    {
        $this->postJson("/api/work-orders/{$this->wo()->id}/results", $this->payload([
            'period_start' => '09:30',
            'period_end' => '09:30',
        ]))->assertStatus(422)->assertJsonValidationErrors('period_end');
    }

    public function test_marks_a_report_filled_after_its_period_as_backfilled(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 06:00:00', 'UTC'));

        $this->postJson("/api/work-orders/{$this->wo()->id}/results", $this->payload([
            'period_start' => '08:30',
            'period_end' => '09:30',
        ]))->assertOk();

        $this->assertTrue((bool) ProductionResult::where('work_order_id', $this->wo()->id)->sole()->is_backfilled);
    }

    public function test_does_not_mark_a_report_made_right_after_the_period_ends_as_backfilled(): void
    {
        // 09:30 WIB = 02:30 UTC: periode 08:30-09:30 baru saja berakhir.
        $this->travelTo(Carbon::parse('2026-01-15 02:30:00', 'UTC'));

        $this->postJson("/api/work-orders/{$this->wo()->id}/results", $this->payload([
            'period_start' => '08:30',
            'period_end' => '09:30',
        ]))->assertOk();

        $row = ProductionResult::where('work_order_id', $this->wo()->id)->sole();

        $this->assertFalse((bool) $row->is_backfilled);
    }

    public function test_accepts_a_legacy_report_without_period_columns(): void
    {
        $payload = $this->payload();
        unset($payload['period_start'], $payload['period_end'], $payload['shift']);

        $this->postJson("/api/work-orders/{$this->wo()->id}/results", $payload)->assertOk();

        $result = ProductionResult::where('work_order_id', $this->wo()->id)->sole();

        $this->assertNull($result->period_start);
        $this->assertFalse((bool) $result->is_backfilled);
    }

    public function test_result_context_flags_a_wip_step_whose_label_is_not_delivered(): void
    {
        // 09:30 WIB = 02:30 UTC: periode 08:30-09:30 baru saja berakhir.
        $this->travelTo(Carbon::parse('2026-01-15 02:30:00', 'UTC'));

        $payload = $this->payload(['qty_good' => 3, 'qty_reject' => 0]);
        $this->postJson("/api/work-orders/{$this->wo()->id}/results", $payload)->assertOk();

        $steps = $this->getJson("/api/work-orders/{$this->wo()->id}/result-context")->assertOk()->json('data.steps');

        $step = collect($steps)->firstWhere('parent_part_id', $this->fixture['fg']->id);

        // Label masih pending -> belum diantar.
        $this->assertFalse($step['label_delivered']);
    }

    public function test_result_context_reports_delivered_label_as_true(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 02:30:00', 'UTC'));

        $this->postJson("/api/work-orders/{$this->wo()->id}/results", $this->payload(['qty_good' => 3, 'qty_reject' => 0]))->assertOk();

        ProductionResult::where('work_order_id', $this->wo()->id)->update(['label_status' => 'delivered']);

        $steps = $this->getJson("/api/work-orders/{$this->wo()->id}/result-context")->assertOk()->json('data.steps');
        $step = collect($steps)->firstWhere('parent_part_id', $this->fixture['fg']->id);

        $this->assertTrue($step['label_delivered']);
    }

    public function test_result_context_leaves_label_flag_null_without_a_period(): void
    {
        $payload = $this->payload(['qty_good' => 3, 'qty_reject' => 0]);
        unset($payload['period_start'], $payload['period_end']);

        $this->postJson("/api/work-orders/{$this->wo()->id}/results", $payload)->assertOk();

        $steps = $this->getJson("/api/work-orders/{$this->wo()->id}/result-context")->assertOk()->json('data.steps');
        $step = collect($steps)->firstWhere('parent_part_id', $this->fixture['fg']->id);

        $this->assertNull($step['label_delivered']);
    }
}
