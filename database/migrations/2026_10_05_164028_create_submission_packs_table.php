<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A submission pack freezes exactly which document versions were printed
     * for the licensing department, so a later re-upload cannot change what
     * the audit trail says was lodged.
     */
    public function up(): void
    {
        Schema::create('submission_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prepared_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('manifest');
            $table->timestamp('submitted_at')->nullable();
            $table->string('authority_reference')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_packs');
    }
};
