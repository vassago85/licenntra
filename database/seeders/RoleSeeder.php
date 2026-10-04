<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $identity = Permission::findOrCreate('documents.identity.download');
        $unmask = Permission::findOrCreate('identifiers.unmask');

        foreach (['super_admin', 'customer_admin', 'reviewer'] as $name) {
            Role::findOrCreate($name)->syncPermissions([$identity, $unmask]);
        }

        foreach (['finance', 'auditor', 'client_admin', 'client_user'] as $name) {
            Role::findOrCreate($name);
        }
    }
}
