<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role refactor: 7 roles down to 5 + a platform role.
 *
 * Reasons:
 * - The names "customer_admin" (operator-side!) and "client_admin"
 *   (customer-side!) read the opposite of their actual meaning and
 *   confused every reader of the code.
 * - "super_admin" and "customer_admin" were 99% the same role, differing
 *   only in whether they saw the platform-billing tile.
 * - "auditor" was defensive design that never earned its keep. The audit
 *   log is already readable by owner; a one-shot read-only toggle will
 *   cover an external auditor if the business ever hires one.
 *
 * Final role model:
 *   - developer      - Charsley Digital. Platform fee + support.
 *   - owner          - Licensing company. Does everything on operator side.
 *                      Absorbs the old super_admin AND old customer_admin.
 *   - reviewer       - Docs + authority handoff. Unchanged.
 *   - finance        - Payments + invoices. Unchanged.
 *   - customer_admin - Dealer/fleet account admin. RENAMED FROM client_admin.
 *                      The name is reclaimed from its old (wrong) meaning.
 *   - customer_user  - Dealer/fleet staff. RENAMED FROM client_user.
 *
 * Dropped: super_admin, auditor, old-meaning customer_admin.
 *
 * This migration is single-direction. There is no down(); rolling back
 * would require a product-level call about whether to split owner again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $teamsEnabled = (bool) config('permission.teams');

        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        DB::transaction(function () use ($teamsEnabled): void {
            // 1. Delete every user that currently holds `auditor`. Owner
            //    agreed on a test-site cleanup, and the role has no
            //    production users on any live install.
            $auditorRoleId = DB::table('roles')->where('name', 'auditor')->value('id');
            if ($auditorRoleId !== null) {
                $auditorUserIds = DB::table('model_has_roles')
                    ->where('role_id', $auditorRoleId)
                    ->where('model_type', User::class)
                    ->pluck('model_id');

                if ($auditorUserIds->isNotEmpty()) {
                    DB::table('model_has_roles')->whereIn('model_id', $auditorUserIds)->delete();
                    DB::table('users')->whereIn('id', $auditorUserIds)->delete();
                }
            }

            // 2. Ensure `owner` role exists before we point users at it.
            $ownerRoleId = DB::table('roles')->where('name', 'owner')->value('id');
            if ($ownerRoleId === null) {
                $ownerRoleId = DB::table('roles')->insertGetId([
                    'name' => 'owner',
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ] + ($teamsEnabled ? ['team_id' => null] : []));
            }

            // 3. Reassign super_admin users to owner.
            $superAdminRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
            if ($superAdminRoleId !== null) {
                DB::table('model_has_roles')
                    ->where('role_id', $superAdminRoleId)
                    ->update(['role_id' => $ownerRoleId]);
            }

            // 4. Reassign OLD customer_admin users to owner. This has to
            //    happen BEFORE we rename client_admin to customer_admin,
            //    otherwise we would overwrite the new (dealer-side) role.
            $oldCustomerAdminRoleId = DB::table('roles')->where('name', 'customer_admin')->value('id');
            if ($oldCustomerAdminRoleId !== null) {
                DB::table('model_has_roles')
                    ->where('role_id', $oldCustomerAdminRoleId)
                    ->update(['role_id' => $ownerRoleId]);
            }

            // 5. Delete the now-orphan super_admin, auditor, old
            //    customer_admin role rows.
            DB::table('roles')
                ->whereIn('name', ['super_admin', 'auditor', 'customer_admin'])
                ->delete();

            // 6. Rename client_admin -> customer_admin (reclaimed name).
            //    Rename client_user  -> customer_user.
            //    Updating `name` on the role row keeps the model_has_roles
            //    pivot intact because that pivot references role_id, not
            //    role name.
            DB::table('roles')->where('name', 'client_admin')->update([
                'name' => 'customer_admin',
                'updated_at' => now(),
            ]);
            DB::table('roles')->where('name', 'client_user')->update([
                'name' => 'customer_user',
                'updated_at' => now(),
            ]);

            // 7. Deduplicate: if a user ended up with both super_admin AND
            //    customer_admin before this ran, step 3 and step 4 both
            //    pointed them at owner, leaving two identical rows.
            //    Collapse those.
            if (DB::getDriverName() === 'pgsql') {
                DB::statement(<<<'SQL'
                    DELETE FROM model_has_roles a
                    USING model_has_roles b
                    WHERE a.ctid < b.ctid
                      AND a.role_id = b.role_id
                      AND a.model_id = b.model_id
                      AND a.model_type = b.model_type
                SQL);
            } else {
                $duplicates = DB::table('model_has_roles')
                    ->select('role_id', 'model_id', 'model_type', DB::raw('COUNT(*) as c'))
                    ->groupBy('role_id', 'model_id', 'model_type')
                    ->having('c', '>', 1)
                    ->get();

                foreach ($duplicates as $dup) {
                    $rows = DB::table('model_has_roles')
                        ->where('role_id', $dup->role_id)
                        ->where('model_id', $dup->model_id)
                        ->where('model_type', $dup->model_type)
                        ->get();
                    // Keep the first row, delete the rest.
                    $rowsToDelete = $rows->skip(1)->pluck('role_id');
                    if ($rowsToDelete->isNotEmpty()) {
                        DB::table('model_has_roles')
                            ->where('role_id', $dup->role_id)
                            ->where('model_id', $dup->model_id)
                            ->where('model_type', $dup->model_type)
                            ->limit($rows->count() - 1)
                            ->delete();
                    }
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Not reversible. Splitting owner back into super_admin + customer_admin
        // would require a product decision about which users become which,
        // and there is no automatic way to recover the auditor users that
        // were deleted. If you need to roll back, restore from a backup.
    }
};
