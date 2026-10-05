<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batched proof-of-collection / proof-of-delivery records for when a
     * licensing-authority representative drops documents off at the
     * dealership (delivery) or collects a pack of lodged documents from
     * the dealership (collection). One row per in-person visit; many
     * applications per row via the pivot table.
     */
    public function up(): void
    {
        Schema::create('document_handovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 16);
            $table->string('status', 16)->default('pending');
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_identifier')->nullable();
            $table->string('counterparty_company')->nullable();
            $table->string('dealer_person_name')->nullable();
            $table->text('items_summary')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('signed_file_path')->nullable();
            $table->string('signed_file_original_name')->nullable();
            $table->string('signed_file_mime', 64)->nullable();
            $table->unsignedBigInteger('signed_file_size')->nullable();
            $table->string('signed_file_sha256', 64)->nullable();
            $table->timestamp('signed_file_uploaded_at')->nullable();
            $table->timestamps();
            $table->index(['client_account_id', 'direction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_handovers');
    }
};
