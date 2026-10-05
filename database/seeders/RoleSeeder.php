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

        // Operator side - staff of the licensing company. The `owner` does
        // everything (configuration + ops + money). `reviewer` and `finance`
        // are narrow operational roles.
        Role::findOrCreate('owner')->syncPermissions([$identity, $unmask]);
        Role::findOrCreate('reviewer')->syncPermissions([$identity, $unmask]);
        Role::findOrCreate('finance');

        // Customer side - staff of a dealer or fleet account. `customer_admin`
        // runs their own account (invites staff, accepts quotes, settles
        // billing). `customer_user` submits applications. The ClientAccount
        // type (dealer/fleet/body-builder/OEM) drives UI differences, not
        // the role.
        Role::findOrCreate('customer_admin');
        Role::findOrCreate('customer_user');

        // Platform side - Charsley Digital. Sets the per-transaction fee
        // the licensing company owner is billed, and can read the counter
        // alongside the owner. Not a licensing-company role. Deliberately
        // not granted to any demo user by RoleSeeder - a developer is
        // created explicitly on each deployment.
        Role::findOrCreate('developer');
    }
}
