<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ALV / RLV the licensing company checked before printing. Holds
     * the form type, every field value as printed, and a hash of the
     * application data the form was filled from so staff can see when
     * the application changed after the check. Stored encrypted because it
     * carries identity numbers.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('natis_form')->nullable()->after('fee_snapshot');
            $table->timestamp('natis_form_checked_at')->nullable()->after('natis_form');
            $table->foreignId('natis_form_checked_by_id')->nullable()->after('natis_form_checked_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('natis_form_checked_by_id');
            $table->dropColumn(['natis_form', 'natis_form_checked_at']);
        });
    }
};
