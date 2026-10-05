<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The per-completed-transaction fee that Charsley Digital charges the
     * licensing company owner. Set by the developer role from the
     * Platform billing page; read by the owner on the same page to see
     * their running month-to-date bill.
     */
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->unsignedInteger('platform_fee_per_transaction_cents')
                ->default(0)
                ->after('admin_charge_tax_treatment');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('platform_fee_per_transaction_cents');
        });
    }
};
