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
        foreach (['part', 'supplier', 'trucking', 'machine', 'process', 'bom', 'production'] as $module) {
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

        // Super Admin gets everything
        $superAdmin = Role::find($roleIds['super-admin']);
        $superAdmin->permissions()->sync(array_values($permissionIds));

        // View-all for the main functional roles (least privilege foundation)
        $viewOnly = [
            'part.view', 'supplier.view', 'trucking.view', 'machine.view', 'process.view',
            'part_substitute.view', 'uom.view', 'config.view', 'bom.view',
            'work_order.view', 'production.view',
        ];
        $management = Role::find($roleIds['management']);
        $management->permissions()->sync(
            Permission::whereIn('name', $viewOnly)->pluck('id')->all()
        );

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
    }
}