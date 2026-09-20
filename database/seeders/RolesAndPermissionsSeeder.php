<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'super-admin' => 'Super Admin',
            'it-admin' => 'IT Admin',
            'management' => 'Management',
            'ppic' => 'PPIC',
            'purchasing' => 'Purchasing',
            'warehouse' => 'Warehouse',
            'production' => 'Production',
            'qc' => 'QC',
            'engineering' => 'Engineering',
        ];

        $roleIds = [];
        foreach ($roles as $name => $label) {
            $roleIds[$name] = Role::firstOrCreate(['name' => $name], ['label' => $label])->id;
        }

        // Permissions: module.action (spec 0.2)
        $permissions = [];
        foreach (['part', 'supplier', 'trucking', 'machine', 'process', 'bom', 'production', 'production_plan'] as $module) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $permissions[] = "{$module}.{$action}";
            }
        }
        foreach (['purchase_order', 'incoming', 'receive', 'work_order'] as $module) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $permissions[] = "{$module}.{$action}";
            }
        }
        $permissions = array_merge($permissions, [
            'part_substitute.view', 'part_substitute.create', 'part_substitute.update', 'part_substitute.delete',
            'uom.view', 'uom.create', 'uom.update', 'uom.delete',
            'stock.view',
            'stock.issue',
            'config.view', 'config.update',
            'role.view', 'role.create', 'role.update', 'role.delete',
            'user.view', 'user.create', 'user.update', 'user.delete',
        ]);

        $permissionIds = [];
        foreach ($permissions as $perm) {
            [$module, $action] = explode('.', $perm, 2);
            $permissionIds[$perm] = Permission::firstOrCreate(
                ['name' => $perm],
                ['module' => $module, 'label' => ucwords(str_replace('_', ' ', $perm))],
            )->id;
        }

        $viewAll = [
            'part.view', 'supplier.view', 'trucking.view', 'machine.view', 'process.view',
            'part_substitute.view', 'uom.view', 'config.view', 'bom.view',
            'work_order.view', 'production.view', 'production_plan.view',
            'purchase_order.view', 'incoming.view', 'receive.view', 'stock.view',
            'role.view', 'user.view',
        ];

        $rolePerms = [
            'super-admin' => array_keys($permissionIds),
            'management' => array_values(array_filter(
                array_keys($permissionIds),
                fn (string $name) => ! str_starts_with($name, 'role.') && ! str_starts_with($name, 'user.'),
            )),
            'it-admin' => array_merge($viewAll, ['config.update', 'role.create', 'role.update', 'role.delete', 'user.create', 'user.update', 'user.delete']),
            'ppic' => array_merge($viewAll, ['production.create', 'production.update', 'production.delete', 'production_plan.create', 'production_plan.update', 'production_plan.delete', 'work_order.create', 'work_order.update', 'bom.create', 'bom.update']),
            'purchasing' => array_merge($viewAll, ['supplier.create', 'supplier.update', 'supplier.delete', 'trucking.create', 'trucking.update', 'trucking.delete', 'purchase_order.create', 'purchase_order.update', 'purchase_order.delete', 'incoming.create', 'incoming.update']),
            'warehouse' => array_merge($viewAll, ['incoming.create', 'incoming.update', 'incoming.delete', 'receive.create', 'receive.update', 'receive.delete', 'stock.issue']),
            'production' => array_merge($viewAll, ['work_order.create', 'work_order.update', 'work_order.delete', 'production.create', 'production.update', 'production.delete', 'stock.issue']),
            'qc' => array_merge($viewAll, ['incoming.update', 'receive.view']),
            'engineering' => array_merge($viewAll, ['part.create', 'part.update', 'part.delete', 'bom.create', 'bom.update', 'bom.delete', 'machine.create', 'machine.update', 'machine.delete', 'process.create', 'process.update', 'process.delete', 'part_substitute.create', 'part_substitute.update', 'part_substitute.delete', 'uom.create', 'uom.update', 'uom.delete']),
        ];

        foreach ($rolePerms as $roleName => $perms) {
            $role = Role::find($roleIds[$roleName]);
            $ids = Permission::whereIn('name', array_unique($perms))->pluck('id')->all();
            $role->permissions()->sync($ids);
        }

        // Create the seed admin user (userId 5 is the real operator's id in other apps;
        // here we create a deterministic local admin).
        $admin = User::updateOrCreate(
            ['email' => 'admin@geumcheon.local'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('password'),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $admin->roles()->syncWithoutDetaching([$roleIds['super-admin']]);

        // User demo per role (password = password) untuk pengujian login & audit permission.
        $demoUsers = [
            'engineering@geumcheon.local' => 'engineering',
            'it-admin@geumcheon.local' => 'it-admin',
            'management@geumcheon.local' => 'management',
            'ppic@geumcheon.local' => 'ppic',
            'purchasing@geumcheon.local' => 'purchasing',
            'warehouse@geumcheon.local' => 'warehouse',
            'production@geumcheon.local' => 'production',
            'qc@geumcheon.local' => 'qc',
        ];
        foreach ($demoUsers as $email => $roleName) {
            $u = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $roleIds[$roleName] ? ucwords(str_replace('-', ' ', $roleName)) : $email,
                    'password' => Hash::make('password'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
            $u->roles()->syncWithoutDetaching([$roleIds[$roleName]]);
        }
    }
}
