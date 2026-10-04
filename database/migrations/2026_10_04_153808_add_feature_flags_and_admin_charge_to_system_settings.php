<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deployment-wide feature switches:
 *
 * - quotes_enabled: when false, hide quote navigation, counters, filters
 *   and actions and let the workflow proceed from DocumentReview straight
 *   to PaymentPending (or PaymentVerified for on-account clients) without
 *   requiring a quote stage. Historical quote records are preserved.
 *
 * - payment_tracking_required: when false, the dashboard no longer treats
 *   payment verification as a reviewer task. Payment capture still works
 *   for clients who use it, but packs can be prepared and submitted
 *   without any payment check.
 *
 * - admin_charge_cents + admin_charge_tax_treatment: configurable estimated
 *   admin fee used by the dealer licence cost estimator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->boolean('quotes_enabled')->default(false)->after('notifications_enabled');
            $table->boolean('payment_tracking_required')->default(false)->after('quotes_enabled');
            $table->unsignedInteger('admin_charge_cents')->default(0)->after('payment_tracking_required');
            $table->string('admin_charge_tax_treatment', 20)->default('standard')->after('admin_charge_cents');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'quotes_enabled',
                'payment_tracking_required',
                'admin_charge_cents',
                'admin_charge_tax_treatment',
            ]);
        });
    }
};
