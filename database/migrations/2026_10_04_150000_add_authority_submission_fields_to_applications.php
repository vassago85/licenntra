<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->string('authority_reference')->nullable()->after('submitted_at');
            $table->timestamp('authority_submitted_at')->nullable()->after('authority_reference');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn(['authority_reference', 'authority_submitted_at']);
        });
    }
};
