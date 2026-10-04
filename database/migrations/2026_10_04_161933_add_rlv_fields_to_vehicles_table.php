<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the fields the ALV + RLV forms expect but the Vehicle table did
 * not yet carry. Everything is nullable so existing applications remain
 * valid; the application form supplies sensible defaults where it can
 * (e.g. fuel_type defaults to diesel for commercial vehicles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            // Powertrain.
            $table->string('fuel_type', 20)->nullable()->after('vehicle_class');
            $table->string('transmission', 20)->nullable()->after('fuel_type');
            $table->unsignedInteger('net_power_kw')->nullable()->after('transmission');
            $table->unsignedInteger('engine_capacity_cc')->nullable()->after('net_power_kw');

            // Visual + physical.
            $table->string('main_colour', 20)->nullable()->after('engine_capacity_cc');
            $table->string('colour_other', 60)->nullable()->after('main_colour');
            $table->unsignedTinyInteger('no_of_wheels')->nullable()->after('colour_other');

            // Body / drive / use.
            $table->string('body_description', 20)->nullable()->after('no_of_wheels');
            $table->string('body_description_other', 60)->nullable()->after('body_description');
            $table->string('drive_type', 30)->nullable()->after('body_description_other');
            $table->string('vehicle_usage', 40)->nullable()->after('drive_type');
            $table->string('vehicle_usage_other', 60)->nullable()->after('vehicle_usage');
            $table->string('economic_sector', 30)->nullable()->after('vehicle_usage_other');
            $table->string('economic_sector_other', 60)->nullable()->after('economic_sector');

            // Odometer + steering.
            $table->unsignedInteger('odometer_reading')->nullable()->after('economic_sector_other');
            $table->string('odometer_type', 10)->nullable()->after('odometer_reading');
            $table->string('steering_position', 10)->nullable()->after('odometer_type');

            // Ownership + registration lifecycle.
            $table->string('nature_of_ownership', 20)->nullable()->after('steering_position');
            $table->string('reason_for_registration', 30)->nullable()->after('nature_of_ownership');
            $table->boolean('used_on_public_road')->nullable()->after('reason_for_registration');
            $table->date('date_liable')->nullable()->after('used_on_public_road');

            // Address where the vehicle is kept (optional, free text covers street + suburb + city + postcode).
            $table->text('address_where_kept')->nullable()->after('date_liable');

            // NaTIS identifiers.
            $table->string('natis_model_number', 40)->nullable()->after('address_where_kept');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn([
                'fuel_type',
                'transmission',
                'net_power_kw',
                'engine_capacity_cc',
                'main_colour',
                'colour_other',
                'no_of_wheels',
                'body_description',
                'body_description_other',
                'drive_type',
                'vehicle_usage',
                'vehicle_usage_other',
                'economic_sector',
                'economic_sector_other',
                'odometer_reading',
                'odometer_type',
                'steering_position',
                'nature_of_ownership',
                'reason_for_registration',
                'used_on_public_road',
                'date_liable',
                'address_where_kept',
                'natis_model_number',
            ]);
        });
    }
};
