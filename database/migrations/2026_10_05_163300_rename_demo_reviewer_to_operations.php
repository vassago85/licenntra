<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reviewer role is presented as "Operations". Only the demo login still
 * carries the default "Reviewer" display name; real staff names are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('email', 'reviewer@licentra.test')
            ->where('name', 'Reviewer')
            ->update(['name' => 'Operations', 'updated_at' => now()]);
    }

    public function down(): void
    {
        //
    }
};
