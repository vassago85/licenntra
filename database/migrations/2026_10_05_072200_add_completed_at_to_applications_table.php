<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record the exact moment an application first entered the Completed
     * stage. This is the metering timestamp used by the platform billing
     * counter (Charsley Digital charges the licensing company owner a
     * per-completed-transaction fee).
     *
     * Backfill from audit_events so existing Completed / Archived rows
     * don't vanish from the counter the first month after deploy.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->timestamp('completed_at')->nullable()->after('stage');
        });

        foreach (DB::table('applications')
            ->whereIn('stage', ['completed', 'archived'])
            ->whereNull('completed_at')
            ->pluck('id') as $applicationId) {
            $firstCompletion = DB::table('audit_events')
                ->where('subject_type', 'App\\Models\\Application')
                ->where('subject_id', $applicationId)
                ->where('action', 'application.stage_changed')
                ->get(['occurred_at', 'after'])
                ->first(function (object $event): bool {
                    $after = json_decode((string) $event->after, true, flags: JSON_THROW_ON_ERROR);

                    return is_array($after) && ($after['stage'] ?? null) === 'completed';
                });

            if ($firstCompletion !== null) {
                DB::table('applications')
                    ->where('id', $applicationId)
                    ->update(['completed_at' => $firstCompletion->occurred_at]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('completed_at');
        });
    }
};
