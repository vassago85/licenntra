<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The dealership's own addresses, printed on the RLV when it registers
     * a vehicle into its stock.
     */
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->text('street_address')->nullable()->after('brn');
            $table->text('postal_address')->nullable()->after('street_address');
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn(['street_address', 'postal_address']);
        });
    }
};
