<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the actual customer user who clicked "Submit" on an application.
 *
 * Previously the dashboard inferred this from the latest editor, which
 * made handoffs ambiguous. For older records this column is null and the
 * UI shows "Unknown".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->foreignId('submitted_by_id')
                ->nullable()
                ->after('authority_submitted_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('submitted_by_id');
        });
    }
};
