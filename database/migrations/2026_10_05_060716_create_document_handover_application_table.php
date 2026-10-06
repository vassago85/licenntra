<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot joining document_handovers to applications. One handover may
     * cover many applications (whatever the licensing company collected
     * from or delivered to the client in one go) and one application may
     * appear on multiple handovers (first a collection of the paperwork,
     * later a delivery of the finished disc back to the client).
     */
    public function up(): void
    {
        Schema::create('document_handover_application', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_handover_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->text('item_description')->nullable();
            $table->timestamps();
            $table->unique(['document_handover_id', 'application_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_handover_application');
    }
};
