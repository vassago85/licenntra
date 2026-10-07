<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opening the print preview proves nothing reached paper, so operations
     * acknowledge the physical printout explicitly.
     */
    public function up(): void
    {
        Schema::table('submission_packs', function (Blueprint $table) {
            $table->timestamp('printed_at')->nullable()->after('manifest');
            $table->foreignId('printed_by_id')->nullable()->after('printed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('submission_packs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('printed_by_id');
            $table->dropColumn('printed_at');
        });
    }
};
