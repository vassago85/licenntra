<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captures what the ALV + RLV declaration blocks need from the licensing
 * company's dealership customer: the dealership's own business reg
 * number, their appointed proxy, and (optionally) a separate
 * representative. Document uploads are a follow-up slice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->string('brn', 60)->nullable()->after('contact_phone');

            $table->string('proxy_name', 120)->nullable()->after('brn');
            $table->string('proxy_initials', 10)->nullable()->after('proxy_name');
            $table->string('proxy_id_type', 20)->nullable()->after('proxy_initials');
            $table->text('proxy_id_number')->nullable()->after('proxy_id_type');
            $table->string('proxy_id_country', 60)->nullable()->after('proxy_id_number');

            $table->string('representative_name', 120)->nullable()->after('proxy_id_country');
            $table->string('representative_initials', 10)->nullable()->after('representative_name');
            $table->string('representative_id_type', 20)->nullable()->after('representative_initials');
            $table->text('representative_id_number')->nullable()->after('representative_id_type');
            $table->string('representative_id_country', 60)->nullable()->after('representative_id_number');
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'brn',
                'proxy_name',
                'proxy_initials',
                'proxy_id_type',
                'proxy_id_number',
                'proxy_id_country',
                'representative_name',
                'representative_initials',
                'representative_id_type',
                'representative_id_number',
                'representative_id_country',
            ]);
        });
    }
};
