<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a single client account play more than one role. The primary
 * `type` column still drives the account's main business (and the
 * paperwork defaults that key off it), but `additional_types` adds
 * extra ClientAccountType values that unlock their matching portal
 * sections. Example: a dealership that also runs a rental fleet has
 * `type = 'dealer'` and `additional_types = ['fleet_operator']`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->json('additional_types')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->dropColumn('additional_types');
        });
    }
};
