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

        $permissions = [
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

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        $admin = Role::create(['name' => 'Admin']);
        $merchant = Role::create(['name' => 'Merchant']);
        $affiliate = Role::create(['name' => 'Affiliate']);
        $member = Role::create(['name' => 'Member']);

        $admin->givePermissionTo($permissions);
    }
}
