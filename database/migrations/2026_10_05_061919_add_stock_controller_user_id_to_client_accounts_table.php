<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nominates a single user on the dealership as the "stock controller"
     * - the person who receives every invoice by default. Finance can
     * still override per-invoice if a specific transaction needs to go
     * to a different person, but 95% of uploads land in the stock
     * controller's inbox.
     */
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->foreignId('stock_controller_user_id')->nullable()->after('contact_phone')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stock_controller_user_id');
        });
    }
};
