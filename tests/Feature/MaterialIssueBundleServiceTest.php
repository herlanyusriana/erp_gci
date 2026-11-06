<?php

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\MaterialIssueBundleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function bundleIssueContext(float $woQty, float $requiredQty, float $receivedQty, int $bundleCount): array
{
    $supplier = Supplier::where('is_active', true)
        ->whereHas('partSubstitutes', fn ($q) => $q->where('is_active', true))
        ->firstOrFail();
    $part = $supplier->partSubstitutes()->where('is_active', true)->firstOrFail()->substitutePart;
    $fg = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['fg']))->firstOrFail();

    $po = PurchaseOrder::create([
        'po_no' => 'PO-BUNDLE-'.uniqid(),
        'supplier_id' => $supplier->id,
        'status' => 'confirmed',
    ]);
    PurchaseOrderItem::create([
        'purchase_order_id' => $po->id,
        'part_id' => $part->id,
        'qty' => $receivedQty,
        'unit' => 'KGM',
    ]);

    $arrival = IncomingArrival::create([
        'arrival_no' => 'ARV-BUNDLE-'.uniqid(),
        'supplier_id' => $supplier->id,
        'purchase_order_id' => $po->id,
        'is_local' => false,
        'status' => 'pending',
        'iqc_status' => 'passed',
    ]);
    $arrivalItem = IncomingArrivalItem::create([
        'arrival_id' => $arrival->id,
        'part_id' => $part->id,
        'material_group' => 'MAT-BUNDLE-01',
        'size' => 'STD',
        'qty_goods' => $receivedQty,
        'unit_goods' => 'KGM',
        'weight_nett' => $receivedQty,
        'unit_weight' => 'KGM',
    ]);
    $receive = IncomingReceive::create([
        'arrival_item_id' => $arrivalItem->id,
        'part_id' => $part->id,
        'tag' => 'TAG-BUNDLE-'.uniqid(),
        'qty' => $receivedQty,
        'qty_unit' => 'KGM',
        'bundle_qty' => $bundleCount,
        'bundle_unit' => 'PALLET',
        'net_weight' => $receivedQty,
        'qc_status' => 'pass',
        'ata_date' => now(),
    ]);
    $stock = PartStock::create([
        'part_id' => $part->id,
        'tag' => $receive->tag,
        'qty' => $receivedQty,
        'qty_unit' => 'KGM',
        'receive_id' => $receive->id,
        'received_at' => now(),
    ]);

    $wo = WorkOrder::create([
        'wo_no' => 'WO-BUNDLE-'.uniqid(),
        'part_id' => $fg->id,
        'qty' => $woQty,
        'status' => 'in_progress',
    ]);
    $item = WorkOrderItem::create([
        'work_order_id' => $wo->id,
        'sequence' => 1,
        'parent_part_id' => $fg->id,
        'child_part_id' => $part->id,
        'selected_part_id' => $part->id,
        'qty_required' => $requiredQty,
        'qty_consumed' => 0,
        'uom_rm' => 'KGM',
    ]);

    return [$wo, $item, $stock, $receive];
}

it('plans the reference scenario through the bundle service', function () {
    [$wo, $item, $stock, $receive] = bundleIssueContext(16000, 16000, 20000, 10);

    $plan = app(MaterialIssueBundleService::class)->forTag($item, $wo, $receive->tag);
    expect($plan['net_requirement'])->toBe(16000.0)
        ->and($plan['packing_qty'])->toBe(2000.0)
        ->and($plan['bundle_count'])->toBe(10)
        ->and($plan['bundle_qty'])->toBe(16000.0)
        ->and($plan['material_group'])->toBe('MAT-BUNDLE-01');

    $checked = array_values(array_filter($plan['bundles'], fn ($b) => $b['checked']));
    expect(array_column($checked, 'bundle_no'))->toBe(range(1, 8));
});

it('subtracts material already issued to the machine from the net requirement', function () {
    [$wo, $item, $stock, $receive] = bundleIssueContext(16000, 16000, 20000, 10);

    // Simulasikan 3.000 sudah di-issue dan belum dikonsumsi mesin.
    DB::table('material_issues')->insert([
        'issue_no' => 'MI-BUNDLE-'.uniqid(),
        'work_order_id' => $wo->id,
        'issue_date' => now()->toDateString(),
        'status' => 'posted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $issueId = (int) DB::table('material_issues')->max('id');
    DB::table('material_issue_items')->insert([
        'material_issue_id' => $issueId,
        'work_order_item_id' => $item->id,
        'part_id' => $item->child_part_id,
        'tag' => $receive->tag,
        'qty' => 3000,
        'uom' => 'KGM',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = app(MaterialIssueBundleService::class)->forTag($item->fresh(), $wo, $receive->tag);

    expect($plan['stock_at_machine'])->toBe(3000.0)
        ->and($plan['net_requirement'])->toBe(13000.0)
        ->and($plan['packing_qty'])->toBe(2000.0);

    $checked = array_values(array_filter($plan['bundles'], fn ($b) => $b['checked']));
    expect(array_column($checked, 'bundle_no'))->toBe(range(1, 6))
        ->and($plan['bundle_qty'])->toBe(12000.0);
});

it('serves the issue plan over the api', function () {
    [$wo, $item, $stock, $receive] = bundleIssueContext(16000, 16000, 20000, 10);
    $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());

    $this->getJson("/api/work-orders/{$wo->id}/items/{$item->id}/issue-plan?tag={$receive->tag}")
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.packing_qty', 2000)
        ->assertJsonPath('data.bundle_count', 10)
        ->assertJsonCount(10, 'data.bundles');
});

it('rejects a confirm whose total issue exceeds the net requirement when the bundle plan flag is set', function () {
    [$wo, $item, $stock, $receive] = bundleIssueContext(16000, 16000, 20000, 10);
    $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());

    $this->postJson("/api/work-orders/{$wo->id}/release", [
        'idempotency_key' => 'bundle-over-'.uniqid(),
        'items' => [[
            'work_order_item_id' => $item->id,
            'bundle_plan' => true,
            'scans' => [[
                'tag' => $receive->tag,
                'part_id' => $item->child_part_id,
                'qty' => 18000,
            ]],
        ]],
    ])->assertStatus(422);
});
