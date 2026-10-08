<?php

use App\Models\Machine;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionMaterialReceipt;
use App\Models\ProductionResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderConsumption;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderMaterialBooking;
use App\Services\ProductionReceiptService;
use App\Services\ProductionResultService;
use App\Services\WoService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfInterceptor;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function machineMaterialFixture(float $issued = 20, ?Machine $machine = null, ?PartStock $stock = null): array
{
    $material = Part::where('part_number', 'CBKG07256C')->firstOrFail();
    $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();
    $machine ??= Machine::create(['machine_code' => 'MC-'.uniqid(), 'machine_name' => 'Machine ledger', 'is_active' => true]);
    $wo = WorkOrder::create(['wo_no' => 'WO-MC-'.uniqid(), 'part_id' => $fg->id, 'qty' => 100, 'status' => 'in_progress']);
    $item = WorkOrderItem::create([
        'work_order_id' => $wo->id, 'sequence' => 1, 'machine_id' => $machine->id,
        'parent_part_id' => $fg->id, 'parent_qty' => 1, 'parent_uom' => 'PCS',
        'child_part_id' => $material->id, 'selected_part_id' => $material->id,
        'child_qty' => 1, 'uom_rm' => 'KGM', 'qty_required' => 100,
    ]);
    $stock ??= PartStock::create(['part_id' => $material->id, 'tag' => 'MC-'.uniqid(), 'qty' => 100, 'qty_unit' => 'KGM', 'received_at' => now()]);
    WorkOrderMaterialBooking::create([
        'work_order_id' => $wo->id, 'work_order_item_id' => $item->id,
        'part_id' => $material->id, 'part_stock_id' => $stock->id, 'tag' => $stock->tag,
        'qty' => $issued, 'uom' => 'KGM', 'status' => 'booked', 'booked_at' => now(),
    ]);
    $issue = MaterialIssue::create(['issue_no' => 'MI-MC-'.uniqid(), 'work_order_id' => $wo->id, 'issue_date' => now(), 'status' => 'posted']);
    $issueItem = MaterialIssueItem::create([
        'material_issue_id' => $issue->id, 'work_order_item_id' => $item->id,
        'part_stock_id' => $stock->id, 'part_id' => $material->id, 'tag' => $stock->tag,
        'qty' => $issued, 'uom' => 'KGM', 'invoice' => 'INV-MACHINE', 'supplier' => 'Supplier trace',
    ]);

    return compact('wo', 'item', 'stock', 'machine', 'issueItem', 'fg', 'material');
}

function receiveMachineMaterial(array $fixture): ProductionMaterialReceipt
{
    return app(ProductionReceiptService::class)->confirm(null, $fixture['machine']->id, materialIssueItemId: $fixture['issueItem']->id);
}

it('consumes good plus reject at the receiving machine without decrementing warehouse again', function () {
    $f = machineMaterialFixture();
    $receipt = receiveMachineMaterial($f);
    expect($f['stock']->fresh()->qty)->toBe(80.0);

    app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5, 'qty_reject' => 1]);

    expect($f['stock']->fresh()->qty)->toBe(80.0);
    expect($receipt->fresh()->qty_consumed)->toBe(6.0);
    expect($f['item']->fresh()->qty_consumed)->toBe(6.0);
    expect(PartStock::where('part_id', $f['fg']->id)->sum('qty'))->toEqual(5);
    expect($receipt->fresh()->invoice)->toBe('INV-MACHINE');

    app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 2]);
    expect($receipt->fresh()->qty_consumed)->toBe(8.0);
    expect($f['stock']->fresh()->qty)->toBe(80.0);
});

it('rejects missing wrong machine and unverified legacy receipts atomically', function (string $case) {
    $f = machineMaterialFixture();
    if ($case !== 'missing') {
        $receipt = receiveMachineMaterial($f);
        if ($case === 'legacy') {
            $receipt->update(['transfer_status' => 'legacy_unverified', 'qty' => null]);
        }
    }
    $machineId = $case === 'wrong_machine'
        ? Machine::create(['machine_code' => 'WRONG-'.uniqid(), 'machine_name' => 'Wrong', 'is_active' => true])->id
        : $f['machine']->id;

    expect(fn () => app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5, 'machine_id' => $machineId]))
        ->toThrow(ValidationException::class);

    expect(ProductionResult::where('work_order_id', $f['wo']->id)->count())->toBe(0);
    expect(WorkOrderConsumption::where('work_order_id', $f['wo']->id)->count())->toBe(0);
    expect($f['item']->fresh()->qty_consumed)->toBe(0.0);
    expect($f['stock']->fresh()->qty)->toBe($case === 'missing' ? 100.0 : 80.0);
    expect(PartStock::where('part_id', $f['fg']->id)->count())->toBe(0);
})->with(['missing', 'wrong_machine', 'legacy']);

it('does not share receipt balances between two work orders for the same part and machine', function () {
    $first = machineMaterialFixture(20);
    $second = machineMaterialFixture(2, $first['machine'], $first['stock']);
    $receipt = receiveMachineMaterial($first);
    $otherReceipt = receiveMachineMaterial($second);

    expect(fn () => app(ProductionResultService::class)->report($second['wo'], $second['fg']->id, ['qty_good' => 3]))->toThrow(ValidationException::class);

    expect($receipt->fresh()->qty_consumed)->toBe(0.0);
    expect($otherReceipt->fresh()->qty_consumed)->toBe(0.0);
    expect($first['stock']->fresh()->qty)->toBe(78.0);
    expect(ProductionResult::where('work_order_id', $second['wo']->id)->count())->toBe(0);
});

it('consumes the issued substitute across partial receipts even after the source stock is soft deleted', function () {
    $f = machineMaterialFixture(3);
    $main = Part::where('part_number', 'PINCB01')->firstOrFail();
    $f['item']->update(['child_part_id' => $main->id]);
    $firstReceipt = receiveMachineMaterial($f);
    $second = machineMaterialFixture(4, $f['machine']);
    WorkOrderMaterialBooking::where('work_order_item_id', $second['item']->id)->update(['work_order_id' => $f['wo']->id, 'work_order_item_id' => $f['item']->id]);
    $second['issueItem']->materialIssue->update(['work_order_id' => $f['wo']->id]);
    $second['issueItem']->update(['work_order_item_id' => $f['item']->id]);
    $secondReceipt = receiveMachineMaterial($second);
    $f['stock']->delete();
    $second['stock']->delete();

    $result = app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5, 'qty_reject' => 1]);

    expect($firstReceipt->fresh()->qty_consumed)->toBe(3.0);
    expect($secondReceipt->fresh()->qty_consumed)->toBe(3.0);
    expect(WorkOrderConsumption::where('production_result_id', $result->id)->pluck('part_id')->unique()->all())->toBe([$f['material']->id]);
    expect(PartStock::withTrashed()->find($f['stock']->id)->qty)->toBe(97.0);
    expect(fn () => app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 2]))->toThrow(ValidationException::class);
    expect($firstReceipt->fresh()->qty_consumed)->toBe(3.0);
    expect($secondReceipt->fresh()->qty_consumed)->toBe(3.0);
    expect(ProductionResult::where('work_order_id', $f['wo']->id)->count())->toBe(1);
});

it('reverses a web result to the exact machine receipt and retains its consumption audit', function () {
    $f = machineMaterialFixture();
    $receipt = receiveMachineMaterial($f);
    $result = app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5, 'qty_reject' => 1]);
    $user = User::where('email', 'admin@geumcheon.local')->firstOrFail();
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(ValidateCsrfInterceptor::class);
    $this->actingAs($user)->delete(route('production-results.destroy', [$f['wo'], $result]))->assertSessionHasNoErrors();

    expect($receipt->fresh()->qty_consumed)->toBe(0.0);
    expect($f['stock']->fresh()->qty)->toBe(80.0);
    expect($f['item']->fresh()->qty_consumed)->toBe(0.0);
    expect(PartStock::where('part_id', $f['fg']->id)->sum('qty'))->toEqual(0);
    $audit = WorkOrderConsumption::withTrashed()->where('production_result_id', $result->id)->sole();
    expect($audit->trashed())->toBeTrue();
    expect($audit->qty)->toBe(6.0);
    expect($audit->reversed_by)->toBe($user->id);
    app(ProductionResultService::class)->reverse($f['wo'], $result);
    expect($receipt->fresh()->qty_consumed)->toBe(0.0);
});

it('rolls back all machine inputs when a later item has insufficient balance', function () {
    $f = machineMaterialFixture();
    $receipt = receiveMachineMaterial($f);
    $later = $f['item']->replicate();
    $later->sequence = 2;
    $later->save();

    expect(fn () => app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5]))->toThrow(ValidationException::class);

    expect($receipt->fresh()->qty_consumed)->toBe(0.0);
    expect($f['item']->fresh()->qty_consumed)->toBe(0.0);
    expect($f['stock']->fresh()->qty)->toBe(80.0);
    expect(ProductionResult::where('work_order_id', $f['wo']->id)->count())->toBe(0);
    expect(WorkOrderConsumption::where('work_order_id', $f['wo']->id)->count())->toBe(0);
});

it('reverses WIP FIFO before upstream machine consumption and blocks upstream reversal while output is in use', function () {
    $f = machineMaterialFixture();
    $receipt = receiveMachineMaterial($f);
    $wip = Part::where('part_number', 'PINCB01')->firstOrFail();
    $f['item']->update(['parent_part_id' => $wip->id]);
    $next = WorkOrderItem::create([
        'work_order_id' => $f['wo']->id, 'sequence' => 2, 'parent_part_id' => $f['fg']->id,
        'child_part_id' => $wip->id, 'child_qty' => 1, 'uom_rm' => 'PCS', 'parent_uom' => 'PCS',
    ]);
    $first = app(ProductionResultService::class)->report($f['wo'], $wip->id, ['qty_good' => 6]);
    $last = app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5, 'qty_reject' => 1]);

    expect(fn () => app(ProductionResultService::class)->reverse($f['wo'], $first))->toThrow(ValidationException::class);
    app(ProductionResultService::class)->reverse($f['wo'], $last);
    expect(PartStock::findOrFail($first->output_part_stock_id)->qty)->toBe(6.0);
    expect($next->fresh()->qty_consumed)->toBe(0.0);
    app(ProductionResultService::class)->reverse($f['wo'], $first);
    expect($receipt->fresh()->qty_consumed)->toBe(0.0);
    expect($f['stock']->fresh()->qty)->toBe(80.0);
});

it('keeps a fully transferred receipt usable when its zero warehouse source is soft deleted', function () {
    $f = machineMaterialFixture(100);
    $receipt = receiveMachineMaterial($f);
    expect($f['stock']->fresh()->qty)->toBe(0.0);
    $f['stock']->delete();

    $result = app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5, 'qty_reject' => 1]);
    expect($receipt->fresh()->qty_consumed)->toBe(6.0);
    app(ProductionResultService::class)->reverse($f['wo'], $result);

    expect($receipt->fresh()->qty_consumed)->toBe(0.0);
    expect(PartStock::withTrashed()->findOrFail($f['stock']->id)->trashed())->toBeTrue();
    expect(PartStock::withTrashed()->findOrFail($f['stock']->id)->qty)->toBe(0.0);
});

it('preserves the existing WO child quantity ratio for good and reject', function () {
    $f = machineMaterialFixture(20);
    $f['item']->update(['parent_qty' => 2, 'child_qty' => 2]);
    $receipt = receiveMachineMaterial($f);

    app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5, 'qty_reject' => 1]);

    expect($receipt->fresh()->qty_consumed)->toBe(12.0);
    expect($f['stock']->fresh()->qty)->toBe(80.0);
});

it('fails closed for reversal of an unlinked legacy production result', function () {
    $f = machineMaterialFixture();
    $legacy = ProductionResult::create([
        'work_order_id' => $f['wo']->id, 'parent_part_id' => $f['fg']->id,
        'qty_good' => 5, 'qty_reject' => 0, 'result_date' => now(), 'uom' => 'PCS',
    ]);

    expect(fn () => app(ProductionResultService::class)->reverse($f['wo'], $legacy))->toThrow(ValidationException::class);

    expect($legacy->fresh()->trashed())->toBeFalse();
    expect($f['stock']->fresh()->qty)->toBe(100.0);
});

it('rolls back WIP consumption when later machine material is missing', function () {
    $f = machineMaterialFixture();
    $wip = Part::where('part_number', 'PINCB01')->firstOrFail();
    $f['item']->update(['parent_part_id' => $wip->id]);
    receiveMachineMaterial($f);
    $upstream = app(ProductionResultService::class)->report($f['wo'], $wip->id, ['qty_good' => 5]);
    WorkOrderItem::create([
        'work_order_id' => $f['wo']->id, 'sequence' => 2, 'parent_part_id' => $f['fg']->id,
        'child_part_id' => $wip->id, 'child_qty' => 1, 'uom_rm' => 'PCS', 'machine_id' => $f['machine']->id,
    ]);
    $later = $f['item']->replicate();
    $later->fill(['sequence' => 3, 'parent_part_id' => $f['fg']->id])->save();
    $stock = PartStock::findOrFail($upstream->output_part_stock_id);

    expect(fn () => app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5]))->toThrow(ValidationException::class);

    expect($stock->fresh()->qty)->toBe(5.0);
    expect($stock->fresh()->trashed())->toBeFalse();
    expect(ProductionResult::where('work_order_id', $f['wo']->id)->count())->toBe(1);
    expect(WorkOrderConsumption::where('work_order_id', $f['wo']->id)->count())->toBe(1);
});

it('uses the fresh locked work order status rather than a stale report object', function () {
    $f = machineMaterialFixture();
    $receipt = receiveMachineMaterial($f);
    WorkOrder::whereKey($f['wo']->id)->update(['status' => 'cancelled']);

    expect(fn () => app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5]))
        ->toThrow(HttpException::class);

    expect($receipt->fresh()->qty_consumed)->toBe(0.0);
    expect($f['stock']->fresh()->qty)->toBe(80.0);
});

it('requires a receipt even for a positive BOM input below four decimal places', function () {
    $f = machineMaterialFixture();
    $f['item']->update(['child_qty' => 0.000001]);

    expect(fn () => app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 1]))->toThrow(ValidationException::class);
    expect(ProductionResult::where('work_order_id', $f['wo']->id)->count())->toBe(0);
});

it('conserves tiny BOM input for split reports and one report down to the product precision', function () {
    $split = machineMaterialFixture();
    $single = machineMaterialFixture();
    $split['item']->update(['child_qty' => 0.000001]);
    $single['item']->update(['child_qty' => 0.000001]);
    $splitReceipt = receiveMachineMaterial($split);
    $singleReceipt = receiveMachineMaterial($single);

    for ($i = 0; $i < 10; $i++) {
        app(ProductionResultService::class)->report($split['wo'], $split['fg']->id, ['qty_good' => 0.0001]);
    }
    app(ProductionResultService::class)->report($single['wo'], $single['fg']->id, ['qty_good' => 0.001]);

    expect($splitReceipt->fresh()->qty_consumed)->toBe(0.000000001);
    expect($singleReceipt->fresh()->qty_consumed)->toBe(0.000000001);
    expect((float) WorkOrderConsumption::where('work_order_id', $split['wo']->id)->sum('qty'))->toBe(0.000000001);
});

it('rejects corrupt verified receipt balances rather than consuming another valid receipt', function (float $consumed) {
    $f = machineMaterialFixture();
    $receipt = receiveMachineMaterial($f);
    $receipt->update(['qty_consumed' => $consumed]);

    expect(fn () => app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 1]))->toThrow(ValidationException::class);
    expect($receipt->fresh()->qty_consumed)->toBe($consumed);
    expect(ProductionResult::where('work_order_id', $f['wo']->id)->count())->toBe(0);
})->with([-1.0, 21.0]);

it('rejects reversal when item consumption would become negative and rolls back output and receipt', function () {
    $f = machineMaterialFixture();
    $receipt = receiveMachineMaterial($f);
    $result = app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5]);
    $f['item']->update(['qty_consumed' => 4]);

    expect(fn () => app(ProductionResultService::class)->reverse($f['wo'], $result))->toThrow(ValidationException::class);
    expect($receipt->fresh()->qty_consumed)->toBe(5.0);
    expect(PartStock::findOrFail($result->output_part_stock_id)->qty)->toBe(5.0);
    expect($result->fresh()->trashed())->toBeFalse();
});

it('rejects inactive and ineligible reporting machines even when a receipt balance exists', function (string $case) {
    $f = machineMaterialFixture();
    $receipt = receiveMachineMaterial($f);
    if ($case === 'inactive') {
        $f['machine']->update(['is_active' => false]);
    } else {
        $other = Machine::create(['machine_code' => 'BAD-'.uniqid(), 'machine_name' => 'XYZ other group', 'is_active' => true]);
        $receipt->update(['machine_id' => $other->id]);
        $f['machine'] = $other;
    }

    expect(fn () => app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5, 'machine_id' => $f['machine']->id]))->toThrow(ValidationException::class);
    expect($receipt->fresh()->qty_consumed)->toBe(0.0);
})->with(['inactive', 'ineligible']);

it('rejects receipt at a machine outside the item routing group', function () {
    $f = machineMaterialFixture();
    $other = Machine::create(['machine_code' => 'BAD-'.uniqid(), 'machine_name' => 'XYZ other group', 'is_active' => true]);

    expect(fn () => app(ProductionReceiptService::class)->confirm(null, $other->id, materialIssueItemId: $f['issueItem']->id))->toThrow(ValidationException::class);
    expect($f['stock']->fresh()->qty)->toBe(100.0);
    expect(ProductionMaterialReceipt::where('material_issue_item_id', $f['issueItem']->id)->count())->toBe(0);
});

it('allows another active machine in the routing name prefix group', function () {
    $f = machineMaterialFixture();
    $other = Machine::create(['machine_code' => 'SAME-'.uniqid(), 'machine_name' => 'Machine sibling', 'is_active' => true]);
    $receipt = app(ProductionReceiptService::class)->confirm(null, $other->id, materialIssueItemId: $f['issueItem']->id);

    app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5, 'machine_id' => $other->id]);

    expect($receipt->fresh()->qty_consumed)->toBe(5.0);
    expect($f['stock']->fresh()->qty)->toBe(80.0);
});

it('excludes foreign work order and unlinked legacy WIP from production input', function (string $case) {
    $f = machineMaterialFixture();
    $wip = Part::where('part_number', 'PINCB01')->firstOrFail();
    $f['item']->update(['parent_part_id' => $wip->id]);
    WorkOrderItem::create(['work_order_id' => $f['wo']->id, 'sequence' => 2, 'parent_part_id' => $f['fg']->id, 'child_part_id' => $wip->id, 'child_qty' => 1, 'uom_rm' => 'PCS']);
    $stock = PartStock::create(['part_id' => $wip->id, 'tag' => 'FOREIGN-WIP', 'qty' => 5, 'qty_unit' => 'PCS', 'received_at' => now()]);
    if ($case === 'foreign') {
        $other = machineMaterialFixture();
        ProductionResult::create(['work_order_id' => $other['wo']->id, 'parent_part_id' => $wip->id, 'qty_good' => 5, 'result_date' => now(), 'output_part_stock_id' => $stock->id]);
    }

    expect(fn () => app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5]))->toThrow(ValidationException::class);
    expect($stock->fresh()->qty)->toBe(5.0);
    expect(ProductionResult::where('work_order_id', $f['wo']->id)->count())->toBe(0);
})->with(['foreign', 'legacy']);

it('validates current locked status during complete and cancel rather than trusting a stale WO', function (string $operation) {
    $f = machineMaterialFixture();
    $currentStatus = $operation === 'complete' ? 'cancelled' : 'completed';
    WorkOrder::whereKey($f['wo']->id)->update(['status' => $currentStatus]);

    expect(fn () => app(WoService::class)->{$operation}($f['wo']))->toThrow(HttpException::class);

    expect($f['wo']->fresh()->status)->toBe($currentStatus);
    expect(WorkOrderMaterialBooking::where('work_order_id', $f['wo']->id)->where('status', 'booked')->sum('qty'))->toEqual(20);
})->with(['complete', 'cancel']);

it('rejects result quantities finer than output precision instead of silently persisting zero', function () {
    $f = machineMaterialFixture();
    $receipt = receiveMachineMaterial($f);

    expect(fn () => app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 0.0000000000001]))->toThrow(ValidationException::class);
    expect($receipt->fresh()->qty_consumed)->toBe(0.0);
    expect(ProductionResult::where('work_order_id', $f['wo']->id)->count())->toBe(0);
});

it('rejects reversal even when output shortage is below the previous four decimal precision', function () {
    $f = machineMaterialFixture();
    $receipt = receiveMachineMaterial($f);
    $result = app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 5]);
    PartStock::whereKey($result->output_part_stock_id)->update(['qty' => '4.9999999999']);

    expect(fn () => app(ProductionResultService::class)->reverse($f['wo'], $result))->toThrow(ValidationException::class);
    expect($receipt->fresh()->qty_consumed)->toBe(5.0);
    expect($result->fresh()->trashed())->toBeFalse();
});

it('conserves tiny WIP consumption and reversal against a large linked output without float cancellation', function () {
    $f = machineMaterialFixture(1000);
    $f['stock']->update(['qty' => 10000]);
    $f['wo']->update(['qty' => 1000000000]);
    $wip = Part::where('part_number', 'PINCB01')->firstOrFail();
    $f['item']->update(['parent_part_id' => $wip->id, 'child_qty' => 0.000001]);
    receiveMachineMaterial($f);
    $upstream = app(ProductionResultService::class)->report($f['wo'], $wip->id, ['qty_good' => 1000000000]);
    WorkOrderItem::create([
        'work_order_id' => $f['wo']->id, 'sequence' => 2, 'parent_part_id' => $f['fg']->id,
        'child_part_id' => $wip->id, 'child_qty' => 0.000001, 'uom_rm' => 'PCS',
    ]);

    $result = app(ProductionResultService::class)->report($f['wo'], $f['fg']->id, ['qty_good' => 0.0001]);

    expect(PartStock::findOrFail($upstream->output_part_stock_id)->getRawOriginal('qty'))->toBe('999999999.9999999999');
    app(ProductionResultService::class)->reverse($f['wo'], $result);
    expect(PartStock::findOrFail($upstream->output_part_stock_id)->getRawOriginal('qty'))->toBe('1000000000.0000000000');
});
