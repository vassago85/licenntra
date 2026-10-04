<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dealerships typically receive their licence documents immediately and are
 * invoiced on a monthly statement rather than paying up-front. This migration
 * teaches client accounts about their billing mode and gives payments a flag
 * plus settlement timestamp so finance can track per-dealership statement
 * balances separately from already-settled cash payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->string('billing_mode')->default('pay_per_transaction')->after('markup_basis_points');
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('billing_mode');
            $table->unsignedInteger('credit_limit_cents')->nullable()->after('payment_terms_days');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->boolean('on_account')->default(false)->after('override_reason');
            $table->timestamp('statement_settled_at')->nullable()->after('on_account');
            $table->foreignId('settled_by')->nullable()->after('statement_settled_at')->constrained('users')->nullOnDelete();
            $table->index(['on_account', 'statement_settled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['on_account', 'statement_settled_at']);
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn(['on_account', 'statement_settled_at']);
        });

        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->dropColumn(['billing_mode', 'payment_terms_days', 'credit_limit_cents']);
        });
    }
};
