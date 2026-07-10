<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissionNames = [
            'users.view',
            'users.manage',
            'campaigns.view',
            'campaigns.manage',
            'cashback.view',
            'cashback.manage',
            'withdrawals.view',
            'withdrawals.manage',
            'settings.manage',
        ];

        $permissions = collect();
        foreach ($permissionNames as $name) {
            $permissions->push(Permission::firstOrCreate(['name' => $name]));
        }

        $admin = Role::firstOrCreate(['name' => 'Admin']);
        $merchant = Role::firstOrCreate(['name' => 'Merchant']);
        $affiliate = Role::firstOrCreate(['name' => 'Affiliate']);
        $member = Role::firstOrCreate(['name' => 'Member']);

        $admin->syncPermissions($permissions);
    }
}
