<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pin which gazette licence-fee column an application prices under.
 *
 * `vehicle_category` only distinguishes passenger from commercial (used
 * to pick datafix flow, document checklist, etc). The gazette prices on
 * a different axis - LicenceFeeCategory - which carries values like
 * "Rigid vehicle", "Trailer", "Motorcycle", "Caravan". Without this
 * column, CalculateFees falls back to showing every licence band whose
 * tare window happens to overlap the vehicle's tare, which balloons the
 * fee estimate to 15+ lines instead of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->string('licence_category', 32)->nullable()->after('vehicle_category');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('licence_category');
        });
    }
};
