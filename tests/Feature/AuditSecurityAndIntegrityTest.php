<?php

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryScan;
use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PartType;
use App\Models\Permission;
use App\Models\ProductionResult;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderMaterialBooking;
use App\Services\ProductionResultService;
use App\Services\ReceiveMaterialService;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

function createUserWithRole(string $roleName): User
{
    $role = Role::where('name', $roleName)->firstOrFail();

    $user = User::create([
        'name' => 'Audit '.ucfirst($roleName).' '.uniqid(),
        'email' => $roleName.'-'.uniqid().'@audit.test',
        'password' => bcrypt('password'),
        'is_active' => true,
    ]);
    $user->roles()->attach($role->id);

    return $user;
}

function createUserWithPermissions(array $permissionNames): User
{
    $role = Role::create([
        'name' => 'audit-role-'.uniqid(),
        'label' => 'Audit Temp Role',
    ]);

    $permissionIds = Permission::whereIn('name', $permissionNames)->pluck('id')->all();
    $role->permissions()->sync($permissionIds);

    $user = User::create([
        'name' => 'Audit Perm User '.uniqid(),
        'email' => 'perm-'.uniqid().'@audit.test',
        'password' => bcrypt('password'),
        'is_active' => true,
    ]);
    $user->roles()->attach($role->id);

    return $user;
}

test('BE-01: non-purchasing user receives 403 on purchase order store, update, and destroy', function () {
    $supplier = Supplier::where('is_active', true)->firstOrFail();
    $po = PurchaseOrder::create([
        'po_no' => 'PO-AUD-'.uniqid(),
        'supplier_id' => $supplier->id,
        'status' => 'draft',
    ]);

    $productionUser = createUserWithRole('production');

    $this->actingAs($productionUser)
        ->post(route('purchase-orders.store'), [
            'po_no' => 'PO-BLOCKED-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'items' => [],
        ])
        ->assertForbidden();

    $this->actingAs($productionUser)
        ->put(route('purchase-orders.update', $po), [
            'po_no' => $po->po_no,
            'supplier_id' => $supplier->id,
            'status' => 'confirmed',
            'items' => [],
        ])
        ->assertForbidden();

    $this->actingAs($productionUser)
        ->delete(route('purchase-orders.destroy', $po))
        ->assertForbidden();

    expect($po->fresh()->trashed())->toBeFalse();
});

test('BE-02: it-admin cannot assign super-admin role, edit or delete super-admin user, update super-admin role, or grant permissions it lacks', function () {
    $itAdmin = createUserWithRole('it-admin');
    $superAdmin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
    $superAdminRole = Role::where('name', 'super-admin')->firstOrFail();
    $productionRole = Role::where('name', 'production')->firstOrFail();

    $regularUser = User::create([
        'name' => 'Regular User',
        'email' => 'regular-'.uniqid().'@audit.test',
        'password' => bcrypt('password'),
        'is_active' => true,
    ]);

    $this->actingAs($itAdmin)
        ->post(route('users.store'), [
            'name' => 'Escalated User',
            'email' => 'escalated-'.uniqid().'@audit.test',
            'password' => 'Password123!',
            'is_active' => true,
            'roles' => [$superAdminRole->id],
        ])
        ->assertForbidden();

    $this->actingAs($itAdmin)
        ->put(route('users.update', $regularUser), [
            'name' => $regularUser->name,
            'email' => $regularUser->email,
            'is_active' => true,
            'roles' => [$superAdminRole->id],
        ])
        ->assertForbidden();

    $this->actingAs($itAdmin)
        ->put(route('users.update', $superAdmin), [
            'name' => 'Hijacked Admin',
            'email' => $superAdmin->email,
            'is_active' => false,
            'roles' => [$productionRole->id],
        ])
        ->assertForbidden();

    $this->actingAs($itAdmin)
        ->delete(route('users.destroy', $superAdmin))
        ->assertForbidden();

    $this->actingAs($itAdmin)
        ->put(route('roles.update', $superAdminRole), [
            'name' => 'super-admin',
            'label' => 'Tampered',
            'permissions' => [],
        ])
        ->assertForbidden();

    $itAdminPerms = $itAdmin->permissions();
    $unheldPerm = Permission::query()
        ->whereNotIn('name', $itAdminPerms)
        ->firstOrFail();

    $this->actingAs($itAdmin)
        ->post(route('roles.store'), [
            'name' => 'escalated-role-'.uniqid(),
            'label' => 'Escalated Role',
            'permissions' => [$unheldPerm->id],
        ])
        ->assertForbidden();

    $customRole = Role::create([
        'name' => 'custom-role-'.uniqid(),
        'label' => 'Custom Role',
    ]);

    $this->actingAs($itAdmin)
        ->put(route('roles.update', $customRole), [
            'name' => $customRole->name,
            'label' => 'Custom Role Updated',
            'permissions' => [$unheldPerm->id],
        ])
        ->assertForbidden();
});

test('BE-09: deactivating or deleting a user revokes their Sanctum tokens', function () {
    $admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();

    $target = User::create([
        'name' => 'Mobile Operator',
        'email' => 'mobile-'.uniqid().'@audit.test',
        'password' => bcrypt('password'),
        'is_active' => true,
    ]);
    $target->createToken('android-device');
    expect($target->tokens()->count())->toBe(1);

    $this->actingAs($admin)
        ->put(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'is_active' => false,
            'roles' => [],
        ])
        ->assertRedirect(route('users.index'));

    expect($target->fresh()->is_active)->toBeFalse();
    expect($target->tokens()->count())->toBe(0);

    $target->update(['is_active' => true]);
    $target->createToken('android-device-2');
    expect($target->tokens()->count())->toBe(1);

    $this->actingAs($admin)
        ->delete(route('users.destroy', $target))
        ->assertRedirect(route('users.index'));

    expect($target->tokens()->count())->toBe(0);
});

test('BE-04: StorePartRequest checks part.create on POST and part.update on PUT', function () {
    $partType = PartType::firstOrFail();
    $part = Part::where('part_number', 'CBKG07256C')->firstOrFail();

    $updateOnlyUser = createUserWithPermissions(['part.update']);
    $createOnlyUser = createUserWithPermissions(['part.create']);

    $this->actingAs($updateOnlyUser)
        ->post(route('parts.store'), [
            'part_number' => 'AUD-NEW-'.uniqid(),
            'part_name' => 'New Part',
            'part_type_id' => $partType->id,
        ])
        ->assertForbidden();

    $this->actingAs($updateOnlyUser)
        ->put(route('parts.update', $part), [
            'part_number' => $part->part_number,
            'part_name' => $part->part_name,
            'part_type_id' => $part->part_type_id,
            'model' => $part->model,
        ])
        ->assertRedirect();

    $this->actingAs($createOnlyUser)
        ->put(route('parts.update', $part), [
            'part_number' => $part->part_number,
            'part_name' => 'Should Not Update',
            'part_type_id' => $part->part_type_id,
        ])
        ->assertForbidden();

    $this->actingAs($createOnlyUser)
        ->post(route('parts.store'), [
            'part_number' => 'AUD-CRT-'.uniqid(),
            'part_name' => 'Created Part',
            'part_type_id' => $partType->id,
        ])
        ->assertRedirect();
});

test('BE-10: POST /api/machines/resolve with empty body returns 404 instead of first active machine', function () {
    $admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
    Sanctum::actingAs($admin);

    $this->postJson('/api/machines/resolve', [])
        ->assertNotFound()
        ->assertJsonPath('ok', false);

    $this->postJson('/api/machines/resolve', ['machine_code' => '   '])
        ->assertNotFound()
        ->assertJsonPath('ok', false);
});

test('BE-03: ReceiveMaterialService::reverseStock rejects reversing a receive whose stock is booked', function () {
    $supplier = Supplier::where('is_active', true)->firstOrFail();
    $material = Part::where('part_number', 'CBKG07256C')->firstOrFail();
    $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

    $arrival = IncomingArrival::create([
        'arrival_no' => 'ARR-AUD-'.uniqid(),
        'invoice_no' => 'INV-AUD-'.uniqid(),
        'supplier_id' => $supplier->id,
        'arrival_date' => now()->toDateString(),
        'is_local' => true,
    ]);

    $arrivalItem = IncomingArrivalItem::create([
        'arrival_id' => $arrival->id,
        'part_id' => $material->id,
        'qty_goods' => 100,
        'unit_goods' => 'KGM',
        'weight_nett' => 100,
    ]);

    $tag = 'TAG-BOOKED-'.uniqid();
    $receive = IncomingReceive::create([
        'arrival_item_id' => $arrivalItem->id,
        'part_id' => $material->id,
        'tag' => $tag,
        'qty' => 100,
        'weight' => 100,
        'net_weight' => 100,
        'qty_unit' => 'KGM',
        'qc_status' => 'pass',
        'ata_date' => now(),
    ]);

    $service = app(ReceiveMaterialService::class);
    $service->postStock($receive);

    $stock = PartStock::where('part_id', $material->id)->where('tag', $tag)->firstOrFail();

    $wo = WorkOrder::create([
        'wo_no' => 'WO-AUD-'.uniqid(),
        'part_id' => $fg->id,
        'qty' => 10,
        'status' => 'in_progress',
    ]);

    $woItem = WorkOrderItem::create([
        'work_order_id' => $wo->id,
        'parent_part_id' => $fg->id,
        'child_part_id' => $material->id,
        'uom_rm' => 'KGM',
        'qty_required' => 50,
        'qty_consumed' => 0,
    ]);

    WorkOrderMaterialBooking::create([
        'work_order_id' => $wo->id,
        'work_order_item_id' => $woItem->id,
        'part_id' => $material->id,
        'part_stock_id' => $stock->id,
        'tag' => $tag,
        'qty' => 50,
        'uom' => 'KGM',
        'status' => WorkOrderMaterialBooking::STATUS_BOOKED,
        'booked_at' => now(),
    ]);

    expect(fn () => $service->reverseStock($receive))
        ->toThrow(ValidationException::class);

    $freshStock = PartStock::withTrashed()->find($stock->id);
    expect($freshStock)->not->toBeNull();
    expect($freshStock->trashed())->toBeFalse();
    expect((float) $freshStock->qty)->toBe(100.0);
});

test('BE-07: ProductionResultService::reverse rejects reversing an FG result referenced by DeliveryScan', function () {
    $fg = Part::where('part_number', 'AAN30056405')->firstOrFail();
    $customer = Customer::where('is_active', true)->firstOrFail();

    $wo = WorkOrder::create([
        'wo_no' => 'WO-FG-AUD-'.uniqid(),
        'part_id' => $fg->id,
        'qty' => 50,
        'status' => 'in_progress',
    ]);

    $stock = PartStock::create([
        'part_id' => $fg->id,
        'tag' => 'FG-SCAN-'.uniqid(),
        'qty' => 50,
        'qty_unit' => 'PCS',
        'received_at' => now(),
    ]);

    $result = ProductionResult::create([
        'work_order_id' => $wo->id,
        'parent_part_id' => $fg->id,
        'result_date' => now()->toDateString(),
        'qty_good' => 50,
        'qty_reject' => 0,
        'uom' => 'PCS',
        'output_part_stock_id' => $stock->id,
    ]);

    $delivery = Delivery::create([
        'delivery_no' => 'DO-AUD-'.uniqid(),
        'customer_id' => $customer->id,
        'delivery_date' => now()->toDateString(),
        'status' => Delivery::STATUS_DRAFT,
    ]);

    DeliveryScan::create([
        'delivery_id' => $delivery->id,
        'scan_type' => DeliveryScan::TYPE_TAG,
        'code' => $stock->tag,
        'part_stock_id' => $stock->id,
        'qty' => 50,
        'scanned_at' => now(),
    ]);

    $service = app(ProductionResultService::class);

    expect(fn () => $service->reverse($wo, $result))
        ->toThrow(ValidationException::class);

    expect($result->fresh()->trashed())->toBeFalse();
    expect((float) $stock->fresh()->qty)->toBe(50.0);
});
