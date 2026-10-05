<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-step hours before an application is flagged as waiting too long.
     */
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->renameColumn('sla_hours', 'stage_warning_hours');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->renameColumn('stage_warning_hours', 'sla_hours');
        });
    }
};
