<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dealerships commonly use the same banks and finance houses as title
 * holders - Southern Cross Bank, Nedbank, Standard Bank, Meridian, etc.
 * Making each dealership re-type (and re-mistype) the same handful of
 * finance houses is pointless.
 *
 * is_shared opts a BusinessClient into cross-dealership visibility. The
 * record still carries its originating client_account_id for provenance,
 * so audit trails show which dealership added it. Any dealer can view or
 * edit a shared record; every edit is captured by the audit log.
 *
 * Owner records (usable_as = 'owner') are the dealer's own customers and
 * typically contain customer PII - the model and SaveBusinessClient action
 * refuse to share those, regardless of what the UI sends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_clients', function (Blueprint $table): void {
            $table->boolean('is_shared')->default(false)->after('usable_as');
            $table->index(['is_shared', 'usable_as']);
        });

        // Pre-share every existing title-holder record. Owner records
        // (customer data) stay private.
        DB::table('business_clients')
            ->whereIn('usable_as', ['title_holder', 'both'])
            ->update(['is_shared' => true]);
    }

    public function down(): void
    {
        Schema::table('business_clients', function (Blueprint $table): void {
            $table->dropIndex(['is_shared', 'usable_as']);
            $table->dropColumn('is_shared');
        });
    }
};
