<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['super_admin', 'admin_staff', 'vendor_owner', 'vendor_staff'];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $permissions = [
            // admin
            'packages.manage',
            'vendors.manage',
            'settings.manage',
            'logs.view',
            // vendor
            'stores.manage',
            'products.manage',
            'inventory.manage',
            'whatsapp.manage',
            'orders.manage',
            'conversations.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superAdmin = Role::findByName('super_admin');
        $superAdmin->givePermissionTo(Permission::all());

        Role::findByName('vendor_owner')->givePermissionTo([
            'stores.manage', 'products.manage', 'inventory.manage',
            'whatsapp.manage', 'orders.manage', 'conversations.view',
        ]);

        Role::findByName('vendor_staff')->givePermissionTo([
            'products.manage', 'inventory.manage', 'orders.manage', 'conversations.view',
        ]);
    }
}
