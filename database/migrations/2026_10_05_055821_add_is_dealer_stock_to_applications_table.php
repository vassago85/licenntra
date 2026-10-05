<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks whether the vehicle is currently registered to this
     * dealership (dealer stock). On a change of ownership that resells
     * dealer-stock, the dealer-stock registration document must also be
     * physically collected before the pack can be forwarded to the
     * licensing authority.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->boolean('is_dealer_stock')->default(false)->after('is_financed');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('is_dealer_stock');
        });
    }
};
