<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot the R72 RTMC national transaction fee alongside the other
     * fee components so saved estimates keep saying the same number even
     * after the pass-through fee is republished in a future gazette.
     */
    public function up(): void
    {
        Schema::table('licence_estimates', function (Blueprint $table): void {
            $table->unsignedInteger('rtmc_transaction_fee_cents')->default(0)->after('admin_charge_tax_treatment');
            $table->string('rtmc_transaction_fee_tax_treatment', 20)->default('exempt')->after('rtmc_transaction_fee_cents');
        });
    }

    public function down(): void
    {
        Schema::table('licence_estimates', function (Blueprint $table): void {
            $table->dropColumn(['rtmc_transaction_fee_cents', 'rtmc_transaction_fee_tax_treatment']);
        });
    }
};
