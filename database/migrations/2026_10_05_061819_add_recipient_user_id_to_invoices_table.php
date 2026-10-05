<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the licensing company (operator) uploads an invoice, they
     * pick which user at the dealership the invoice is addressed to -
     * so the right finance clerk receives it rather than everyone on
     * the account. Nullable because invoices created before this change
     * (and ad-hoc uploads where no specific recipient is relevant) are
     * valid without one.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('recipient_user_id')->nullable()->after('uploaded_by_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recipient_user_id');
        });
    }
};
