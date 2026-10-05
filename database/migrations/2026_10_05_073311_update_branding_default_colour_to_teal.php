<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shift any branding row that still has the old default blue (#1F47B8) to
     * the new mockup teal (#146d61). Rows that an operator has customised
     * (any other colour) are left untouched.
     */
    public function up(): void
    {
        if (! Schema::hasTable('branding_settings')) {
            return;
        }

        DB::table('branding_settings')
            ->where('primary_colour', '#1F47B8')
            ->update(['primary_colour' => '#146d61']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('branding_settings')) {
            return;
        }

        DB::table('branding_settings')
            ->where('primary_colour', '#146d61')
            ->update(['primary_colour' => '#1F47B8']);
    }
};
