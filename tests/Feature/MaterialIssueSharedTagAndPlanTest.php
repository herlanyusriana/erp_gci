<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\IncomingReceivePacking;
use App\Models\Machine;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionMaterialReceipt;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderConsumption;
use App\Models\WorkOrderItem;
use App\Services\DailyScheduleService;
use App\Services\ProductionReceiptService;
use App\Services\ProductionResultService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MaterialIssueSharedTagAndPlanTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Part $part;

    private Part $fg;

    private Machine $machine;

    private PartStock $stock;

    private IncomingReceive $receive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);

        $supplier = Supplier::where('is_active', true)
            ->whereHas('partSubstitutes', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();
        $this->part = $supplier->partSubstitutes()->where('is_active', true)->firstOrFail()->substitutePart;
        $this->fg = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['fg']))->firstOrFail();
        $this->machine = Machine::where('is_active', true)->orderBy('id')->firstOrFail();

        $po = PurchaseOrder::create([
            'po_no' => 'PO-SHARE-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'confirmed',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'part_id' => $this->part->id,
            'qty' => 3430,
            'unit' => 'KGM',
        ]);

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-SHARE-'.uniqid(),
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'is_local' => false,
            'status' => 'pending',
            'iqc_status' => 'passed',
        ]);
        $arrivalItem = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $this->part->id,
            'material_group' => 'COIL-SHARE',
            'size' => 'STD',
            'qty_goods' => 3430,
            'unit_goods' => 'KGM',
            'weight_nett' => 3430,
            'unit_weight' => 'KGM',
        ]);
        $tag = 'TAG-SH-'.uniqid();
        $this->receive = IncomingReceive::create([
            'arrival_item_id' => $arrivalItem->id,
            'part_id' => $this->part->id,
            'tag' => $tag,
            'qty' => 3430,
            'qty_unit' => 'KGM',
            'bundle_qty' => 1,
            'bundle_unit' => 'PALLET',
            'packing_count' => 3,
            'packing_type' => 'COIL',
            'net_weight' => 3430,
            'qc_status' => 'pass',
            'ata_date' => now(),
        ]);
        foreach ([1 => 1143.33, 2 => 1143.33, 3 => 1143.34] as $seq => $coilQty) {
            IncomingReceivePacking::create([
                'receive_id' => $this->receive->id,
                'packing_no' => "19-{$seq}",
                'sequence' => $seq,
                'qty' => $coilQty,
                'uom' => 'KGM',
                'net_weight' => $coilQty,
            ]);
        }
        $this->stock = PartStock::create([
            'part_id' => $this->part->id,
            'tag' => $tag,
            'qty' => 3430,
            'qty_unit' => 'KGM',
            'receive_id' => $this->receive->id,
            'received_at' => now(),
        ]);
    }

    private function makeWoWithPlan(float $woQty, float $woRequired, ?float $planTodayQty = null, ?Machine $machine = null): array
    {
        $targetMachine = $machine ?? $this->machine;
        $wo = WorkOrder::create([
            'wo_no' => 'WO-SH-'.uniqid(),
            'part_id' => $this->fg->id,
            'qty' => $woQty,
            'status' => 'planned',
        ]);
        $item = WorkOrderItem::create([
            'work_order_id' => $wo->id,
            'sequence' => 1,
            'machine_id' => $targetMachine->id,
            'parent_part_id' => $this->fg->id,
            'child_part_id' => $this->part->id,
            'selected_part_id' => $this->part->id,
            'parent_qty' => 1,
            'child_qty' => $woQty > 0 ? round($woRequired / $woQty, 6) : 1,
            'qty_required' => $woRequired,
            'qty_consumed' => 0,
            'uom_rm' => 'KGM',
        ]);

        if ($planTodayQty !== null) {
            $today = app(DailyScheduleService::class)->plantToday();
            $plan = ProductionPlan::firstOrCreate(['plan_date' => $today]);
            ProductionPlanItem::create([
                'production_plan_id' => $plan->id,
                'work_order_id' => $wo->id,
                'machine_id' => $targetMachine->id,
                'fg_part_id' => $this->fg->id,
                'wip_part_id' => $this->fg->id,
                'target_d' => $planTodayQty,
                'sequence' => 1,
                'sequence_d' => 1,
            ]);
        }

        return [$wo, $item];
    }

    public function test_issue_plan_uses_daily_plan_qty_and_tracks_issued_packings_across_two_work_orders(): void
    {
        // WO #1 total 1000 PCS (1452 KGM), tapi Plan hari ini 500 PCS -> 726 KGM.
        [$wo1, $item1] = $this->makeWoWithPlan(1000, 1452, 500);
        [$wo2, $item2] = $this->makeWoWithPlan(1000, 1452, 500);

        $res1 = $this->getJson("/api/work-orders/{$wo1->id}/items/{$item1->id}/issue-plan?tag={$this->receive->tag}")
            ->assertOk()
            ->assertJsonPath('data.has_daily_plan', true)
            ->assertJsonPath('data.planned_qty', 500)
            ->assertJsonPath('data.plan_required_qty', 726)
            ->assertJsonPath('data.net_requirement', 726)
            ->assertJsonPath('data.packings.0.packing_no', '19-1')
            ->assertJsonPath('data.packings.0.checked', true)
            ->assertJsonPath('data.packings.0.issued', false)
            ->assertJsonPath('data.packings.1.checked', false);

        // Mengeluarkan 2 coil sekaligus (2286.66 KGM) untuk 726 KGM ditolak karena lebih dari 1 kemasan utuh.
        $this->postJson("/api/work-orders/{$wo1->id}/release", [
            'idempotency_key' => 'idem-over-'.uniqid(),
            'items' => [[
                'work_order_item_id' => $item1->id,
                'bundle_plan' => true,
                'scans' => [[
                    'tag' => $this->receive->tag,
                    'part_id' => $this->part->id,
                    'qty' => 2286.66,
                    'packing_nos' => ['19-1', '19-2'],
                ]],
            ]],
        ])->assertStatus(422);

        // Mengeluarkan 1 coil utuh 19-1 (1143.33 KGM) untuk kebutuhan 726 KGM diizinkan (toleransi 1 kemasan utuh).
        $this->postJson("/api/work-orders/{$wo1->id}/release", [
            'idempotency_key' => 'idem-wo1-'.uniqid(),
            'items' => [[
                'work_order_item_id' => $item1->id,
                'bundle_plan' => true,
                'scans' => [[
                    'tag' => $this->receive->tag,
                    'part_id' => $this->part->id,
                    'qty' => 1143.33,
                    'packing_nos' => ['19-1'],
                ]],
            ]],
        ])->assertOk();

        // Saat WO #2 membuka tag yang sama, 19-1 sudah terkunci (issued=true) dan 19-2 otomatis tercentang!
        $this->getJson("/api/work-orders/{$wo2->id}/items/{$item2->id}/issue-plan?tag={$this->receive->tag}")
            ->assertOk()
            ->assertJsonPath('data.packings.0.packing_no', '19-1')
            ->assertJsonPath('data.packings.0.issued', true)
            ->assertJsonPath('data.packings.0.issued_to_wo', $wo1->wo_no)
            ->assertJsonPath('data.packings.0.checked', false)
            ->assertJsonPath('data.packings.1.packing_no', '19-2')
            ->assertJsonPath('data.packings.1.issued', false)
            ->assertJsonPath('data.packings.1.checked', true);

        // Mencoba issue ulang packing 19-1 pada WO #2 ditolak server.
        $this->postJson("/api/work-orders/{$wo2->id}/release", [
            'idempotency_key' => 'idem-dup-pack-'.uniqid(),
            'items' => [[
                'work_order_item_id' => $item2->id,
                'bundle_plan' => true,
                'scans' => [[
                    'tag' => $this->receive->tag,
                    'part_id' => $this->part->id,
                    'qty' => 1143.33,
                    'packing_nos' => ['19-1'],
                ]],
            ]],
        ])->assertStatus(422);
    }

    public function test_second_wo_can_release_with_shared_packing_surplus_immediately_and_cross_machine(): void
    {
        $otherMachine = Machine::where('is_active', true)
            ->where('id', '!=', $this->machine->id)
            ->orderBy('id')
            ->firstOrFail();

        // WO #1 di Mesin A butuh 600 KGM, keluar 1 coil utuh 19-1 (1143.33 KGM) -> surplus = 543.33 KGM.
        [$wo1, $item1] = $this->makeWoWithPlan(600, 600, 600, $this->machine);
        // WO #2 di Mesin B (lintas mesin!) butuh 500 KGM -> tertutup penuh oleh surplus 543.33 KGM!
        [$wo2, $item2] = $this->makeWoWithPlan(500, 500, 500, $otherMachine);
        // WO #3 butuh 400 KGM -> setelah WO #2 klaim 500, sisa surplus tinggal 43.33 KGM (tidak boleh double-claim).
        [$wo3, $item3] = $this->makeWoWithPlan(400, 400, 400, $otherMachine);

        $this->postJson("/api/work-orders/{$wo1->id}/release", [
            'idempotency_key' => 'idem-sh1-'.uniqid(),
            'items' => [[
                'work_order_item_id' => $item1->id,
                'bundle_plan' => true,
                'scans' => [[
                    'tag' => $this->receive->tag,
                    'part_id' => $this->part->id,
                    'qty' => 1143.33,
                    'packing_nos' => ['19-1'],
                ]],
            ]],
        ])->assertOk();

        // BAHKAN SEBELUM produksi terima coil di mesin, WO #2 lintas mesin langsung melihat surplus 543.33 KGM dari 19-1!
        $this->getJson("/api/work-orders/{$wo2->id}/release-context")
            ->assertOk()
            ->assertJsonPath('data.work_order.can_release_with_machine_stock', true)
            ->assertJsonPath('data.work_order.machine_surplus_qty', 543.33)
            ->assertJsonPath('data.work_order.machine_surplus_packings.0', "19-1 · {$wo1->wo_no}")
            ->assertJsonPath('data.items.0.shared_machine_stock', 543.33)
            ->assertJsonPath('data.items.0.remaining', 0);

        // Release WO #2 tanpa scan tag baru memakai stok surplus packing 19-1.
        $this->postJson("/api/work-orders/{$wo2->id}/release", [
            'idempotency_key' => 'idem-sh2-'.uniqid(),
            'use_machine_stock' => true,
            'items' => [],
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        // WO #3 hanya melihat sisa surplus 43.33 KGM (543.33 - 500), bukan 543.33 lagi!
        $this->getJson("/api/work-orders/{$wo3->id}/release-context")
            ->assertOk()
            ->assertJsonPath('data.work_order.can_release_with_machine_stock', false)
            ->assertJsonPath('data.items.0.shared_machine_stock', 43.33)
            ->assertJsonPath('data.items.0.remaining', 356.67);

        // Terima coil 19-1 di Mesin A untuk WO #1.
        $issue1 = MaterialIssue::where('work_order_id', $wo1->id)->firstOrFail();
        $issueItem1 = MaterialIssueItem::where('material_issue_id', $issue1->id)->firstOrFail();
        app(ProductionReceiptService::class)->confirm(null, (int) $this->machine->id, materialIssueItemId: $issueItem1->id);

        // Laporan Production Result untuk WO #2 di Mesin B (lintas mesin!) sukses memotong sisa coil 19-1 milik WO #1!
        app(ProductionResultService::class)->report($wo2->fresh(), $this->fg->id, [
            'qty_good' => 200,
            'qty_reject' => 0,
            'result_date' => now()->toDateString(),
            'machine_id' => $otherMachine->id,
        ], $this->admin->id);

        $wo2Consumed = (float) WorkOrderConsumption::query()
            ->where('work_order_id', $wo2->id)
            ->where('part_id', $this->part->id)
            ->sum('qty');
        $receiptConsumed = (float) ProductionMaterialReceipt::query()
            ->where('work_order_id', $wo1->id)
            ->where('machine_id', $this->machine->id)
            ->sum('qty_consumed');

        $this->assertEqualsWithDelta(200.0, $wo2Consumed, 0.001);
        $this->assertEqualsWithDelta(200.0, $receiptConsumed, 0.001);
    }

    public function test_two_work_orders_can_split_same_packing_number_partially(): void
    {
        // WO #1 butuh 600 KGM, ambil sebagian (600 dari 1143.33 KGM) pada packing 19-1.
        [$wo1, $item1] = $this->makeWoWithPlan(600, 600, 600);
        // WO #2 butuh 500 KGM -> sisa packing 19-1 = 543.33 KGM masih bisa dipilih oleh WO #2!
        [$wo2, $item2] = $this->makeWoWithPlan(500, 500, 500);
        [$wo3, $item3] = $this->makeWoWithPlan(400, 400, 400);

        $this->postJson("/api/work-orders/{$wo1->id}/release", [
            'idempotency_key' => 'idem-sp1-'.uniqid(),
            'items' => [[
                'work_order_item_id' => $item1->id,
                'bundle_plan' => true,
                'scans' => [[
                    'tag' => $this->receive->tag,
                    'part_id' => $this->part->id,
                    'qty' => 600,
                    'packing_nos' => ['19-1'],
                ]],
            ]],
        ])->assertOk();

        // Di issue-plan WO #2, packing 19-1 belum habis: sisa 543.33 KGM aktif & otomatis tercentang!
        $this->getJson("/api/work-orders/{$wo2->id}/items/{$item2->id}/issue-plan?tag={$this->receive->tag}")
            ->assertOk()
            ->assertJsonPath('data.packings.0.packing_no', '19-1')
            ->assertJsonPath('data.packings.0.issued', false)
            ->assertJsonPath('data.packings.0.original_qty', 1143.33)
            ->assertJsonPath('data.packings.0.issued_qty', 600)
            ->assertJsonPath('data.packings.0.remaining_qty', 543.33)
            ->assertJsonPath('data.packings.0.qty', 543.33)
            ->assertJsonPath('data.packings.0.checked', true)
            ->assertJsonPath('data.packings.0.issued_to_wo', $wo1->wo_no);

        // Mengeluarkan 600 KGM (> sisa 543.33) dari 19-1 untuk WO #2 ditolak.
        $this->postJson("/api/work-orders/{$wo2->id}/release", [
            'idempotency_key' => 'idem-sp2-over-'.uniqid(),
            'items' => [[
                'work_order_item_id' => $item2->id,
                'bundle_plan' => true,
                'scans' => [[
                    'tag' => $this->receive->tag,
                    'part_id' => $this->part->id,
                    'qty' => 600,
                    'packing_nos' => ['19-1'],
                ]],
            ]],
        ])->assertStatus(422);

        // Mengeluarkan seluruh sisa 543.33 KGM dari 19-1 untuk WO #2 (butuh 500) diizinkan (1 sisa kemasan utuh)!
        $this->postJson("/api/work-orders/{$wo2->id}/release", [
            'idempotency_key' => 'idem-sp2-ok-'.uniqid(),
            'items' => [[
                'work_order_item_id' => $item2->id,
                'bundle_plan' => true,
                'scans' => [[
                    'tag' => $this->receive->tag,
                    'part_id' => $this->part->id,
                    'qty' => 543.33,
                    'packing_nos' => ['19-1'],
                ]],
            ]],
        ])->assertOk();

        // Sekarang 19-1 benar-benar habis (600 + 543.33 = 1143.33): terkunci di WO #3, dan 19-2 otomatis tercentang!
        $this->getJson("/api/work-orders/{$wo3->id}/items/{$item3->id}/issue-plan?tag={$this->receive->tag}")
            ->assertOk()
            ->assertJsonPath('data.packings.0.packing_no', '19-1')
            ->assertJsonPath('data.packings.0.issued', true)
            ->assertJsonPath('data.packings.0.remaining_qty', 0)
            ->assertJsonPath('data.packings.0.checked', false)
            ->assertJsonPath('data.packings.1.packing_no', '19-2')
            ->assertJsonPath('data.packings.1.checked', true);

        // Mencoba issue 19-1 lagi di WO #3 ditolak server.
        $this->postJson("/api/work-orders/{$wo3->id}/release", [
            'idempotency_key' => 'idem-sp3-dup-'.uniqid(),
            'items' => [[
                'work_order_item_id' => $item3->id,
                'bundle_plan' => true,
                'scans' => [[
                    'tag' => $this->receive->tag,
                    'part_id' => $this->part->id,
                    'qty' => 100,
                    'packing_nos' => ['19-1'],
                ]],
            ]],
        ])->assertStatus(422);
    }
}
