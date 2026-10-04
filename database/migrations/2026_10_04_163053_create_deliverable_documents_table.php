<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverable_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('label', 160)->nullable();

            $table->string('storage_path');
            $table->string('original_filename', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64)->nullable();

            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');

            $table->text('handover_notes')->nullable();

            $table->timestamps();

            $table->index(['application_id', 'kind']);
            $table->index('uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverable_documents');
    }
};
