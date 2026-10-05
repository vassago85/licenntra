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

        // Platform-side role. The `developer` is Charsley Digital staff
        // (not the licensing company). They set the per-completed-
        // transaction fee the licensing company owner is billed, and can
        // read the running counter alongside the owner. Deliberately
        // *not* granted to any demo user by RoleSeeder - a developer is
        // created explicitly on each deployment.
        Role::findOrCreate('developer');
    }
}
