<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_lines', function (Blueprint $table): void {
            $table->string('tax_treatment', 32)->default('exempt')->after('amount_cents');
            $table->string('period', 32)->default('once_off')->after('tax_treatment');
            $table->string('licence_category', 64)->nullable()->after('vehicle_category');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('tare_max_kg');
        });

        Schema::table('fee_table_versions', function (Blueprint $table): void {
            $table->date('effective_from')->nullable()->after('status');
            $table->date('effective_until')->nullable()->after('effective_from');
            $table->text('notes')->nullable()->after('effective_until');
        });
    }

    public function down(): void
    {
        Schema::table('fee_lines', function (Blueprint $table): void {
            $table->dropColumn(['tax_treatment', 'period', 'licence_category', 'sort_order']);
        });

        Schema::table('fee_table_versions', function (Blueprint $table): void {
            $table->dropColumn(['effective_from', 'effective_until', 'notes']);
        });
    }
};
