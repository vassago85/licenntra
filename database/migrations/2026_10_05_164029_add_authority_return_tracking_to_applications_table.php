<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Approved" only means the department signed off; the paperwork is
     * physically back once staff record receipt. Queries are likewise only
     * ready to resubmit once someone records how they were resolved.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('authority_query_resolved_at')->nullable()->after('authority_submitted_at');
            $table->text('authority_query_resolution')->nullable()->after('authority_query_resolved_at');
            $table->timestamp('authority_returned_at')->nullable()->after('authority_query_resolution');
            $table->foreignId('authority_returned_by_id')->nullable()->after('authority_returned_at')->constrained('users')->nullOnDelete();
            $table->text('authority_return_notes')->nullable()->after('authority_returned_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('authority_returned_by_id');
            $table->dropColumn(['authority_query_resolved_at', 'authority_query_resolution', 'authority_returned_at', 'authority_return_notes']);
        });
    }
};
