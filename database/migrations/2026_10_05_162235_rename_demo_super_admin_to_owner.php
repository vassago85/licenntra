<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Demo databases seeded before the role consolidation have the owner under
 * the old super.admin@licentra.test address, while the login page advertises
 * owner@licentra.test. Installs without the demo seed have neither address,
 * so this is a no-op for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->where('email', 'owner@licentra.test')->exists()) {
            return;
        }

        DB::table('users')
            ->where('email', 'super.admin@licentra.test')
            ->update(['email' => 'owner@licentra.test', 'name' => 'Owner', 'updated_at' => now()]);
    }

    public function down(): void
    {
        //
    }
};
