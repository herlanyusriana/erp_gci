<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

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
        foreach (['part', 'supplier', 'trucking', 'machine', 'process', 'bom', 'production', 'production_plan', 'location', 'customer', 'trolley'] as $module) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $permissions[] = "{$module}.{$action}";
            }
        }
        foreach (['purchase_order', 'incoming', 'receive', 'work_order', 'delivery', 'customer_price'] as $module) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $permissions[] = "{$module}.{$action}";
            }
        }
        $permissions = array_merge($permissions, [
            'part_substitute.view', 'part_substitute.create', 'part_substitute.update', 'part_substitute.delete',
            'uom.view', 'uom.create', 'uom.update', 'uom.delete',
            'stock.view',
            'stock.issue',
            'delivery.oqc',
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
            'work_order.view', 'production.view', 'production_plan.view', 'location.view',
            'purchase_order.view', 'incoming.view', 'receive.view', 'stock.view',
            'customer.view', 'delivery.view', 'customer_price.view', 'trolley.view',
            'role.view', 'user.view',
        ];

        $rolePerms = [
            'super-admin' => array_keys($permissionIds),
            'management' => array_values(array_filter(
                array_keys($permissionIds),
                fn (string $name) => ! str_starts_with($name, 'role.') && ! str_starts_with($name, 'user.'),
            )),
            'it-admin' => array_merge($viewAll, ['config.update', 'role.create', 'role.update', 'role.delete', 'user.create', 'user.update', 'user.delete']),
            'ppic' => array_merge($viewAll, ['production.create', 'production.update', 'production.delete', 'production_plan.create', 'production_plan.update', 'production_plan.delete', 'work_order.create', 'work_order.update', 'bom.create', 'bom.update', 'delivery.create', 'delivery.update', 'delivery.delete', 'delivery.oqc', 'customer_price.create', 'customer_price.update', 'customer_price.delete', 'trolley.create', 'trolley.update', 'trolley.delete']),
            'purchasing' => array_merge($viewAll, ['supplier.create', 'supplier.update', 'supplier.delete', 'trucking.create', 'trucking.update', 'trucking.delete', 'purchase_order.create', 'purchase_order.update', 'purchase_order.delete', 'incoming.create', 'incoming.update']),
            'warehouse' => array_merge($viewAll, ['incoming.create', 'incoming.update', 'incoming.delete', 'receive.create', 'receive.update', 'receive.delete', 'stock.issue', 'location.create', 'location.update', 'location.delete', 'delivery.create', 'delivery.update', 'delivery.delete', 'delivery.oqc', 'customer.create', 'customer.update', 'trolley.create', 'trolley.update', 'trolley.delete']),
            'production' => array_merge($viewAll, ['work_order.create', 'work_order.update', 'work_order.delete', 'production.create', 'production.update', 'production.delete', 'stock.issue']),
            'qc' => array_merge($viewAll, ['incoming.update', 'receive.view', 'delivery.oqc']),
            'engineering' => array_merge($viewAll, ['part.create', 'part.update', 'part.delete', 'bom.create', 'bom.update', 'bom.delete', 'machine.create', 'machine.update', 'machine.delete', 'process.create', 'process.update', 'process.delete', 'part_substitute.create', 'part_substitute.update', 'part_substitute.delete', 'uom.create', 'uom.update', 'uom.delete']),
        ];

        foreach ($rolePerms as $roleName => $perms) {
            $role = Role::find($roleIds[$roleName]);
            $ids = Permission::whereIn('name', array_unique($perms))->pluck('id')->all();
            $role->permissions()->sync($ids);
        }

        // Admin deterministik untuk local/testing. User yang sudah ada tidak
        // pernah diubah passwordnya supaya re-seed tidak mereset admin produksi.
        $admin = User::where('email', 'admin@geumcheon.local')->first();

        if ($admin === null) {
            $admin = User::create([
                'email' => 'admin@geumcheon.local',
                'name' => 'Administrator',
                'password' => Hash::make($this->adminPassword()),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        }
        $admin->roles()->syncWithoutDetaching([$roleIds['super-admin']]);

        // User demo per role untuk pengujian login & audit permission.
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

        if ($this->demoUsersAllowed()) {
            $demoPassword = $this->demoPassword();

            foreach ($demoUsers as $email => $roleName) {
                $u = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $roleIds[$roleName] ? ucwords(str_replace('-', ' ', $roleName)) : $email,
                        'password' => Hash::make($demoPassword),
                        'is_active' => true,
                        'email_verified_at' => now(),
                    ],
                );
                $u->roles()->syncWithoutDetaching([$roleIds[$roleName]]);
            }
        } else {
            $this->command?->warn(
                'User demo dilewati: environment "'.app()->environment().'" bukan local/testing '
                .'dan SEED_ALLOW_DEMO_USERS tidak aktif.'
            );
        }
    }

    private function adminPassword(): string
    {
        return $this->passwordFromConfig('seed.admin_password', 'password', 'SEED_ADMIN_PASSWORD');
    }

    private function demoPassword(): string
    {
        return $this->passwordFromConfig('seed.demo_password', 'password', 'SEED_DEMO_PASSWORD');
    }

    private function passwordFromConfig(string $key, string $localFallback, string $envName): string
    {
        $configured = config($key);

        if (app()->environment('local', 'testing')) {
            return filled($configured) ? (string) $configured : $localFallback;
        }

        if (blank($configured)) {
            throw new RuntimeException(
                "{$envName} belum diisi. Set variabel environment tersebut sebelum menjalankan "
                .'seeder di environment "'.app()->environment().'".'
            );
        }

        return (string) $configured;
    }

    private function demoUsersAllowed(): bool
    {
        return app()->environment('local', 'testing') || (bool) config('seed.allow_demo_users');
    }
}
