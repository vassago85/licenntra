<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The licensing company nominates one reviewer per dealership as the
     * "primary" owner of that account's work. New submissions auto-land
     * on that reviewer's queue, but any other reviewer can still pick
     * the application up when the primary is on leave - that coverage
     * event gets its own audit annotation.
     */
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->foreignId('primary_reviewer_user_id')
                ->nullable()
                ->after('stock_controller_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('primary_reviewer_user_id');
        });
    }
};
