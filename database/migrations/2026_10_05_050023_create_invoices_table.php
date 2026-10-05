<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices the licensing company uploads against an application once the
 * transaction has reached PaymentVerified or later. The record carries just
 * enough metadata to let both the licensing company and the originating
 * dealership see which invoices are outstanding and which have been marked
 * paid. The invoice amount itself is read from `applications.fee_snapshot`
 * so that one accepted-quote total remains the single source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('invoice_number', 40)->unique();

            $table->string('storage_path');
            $table->string('original_filename', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64)->nullable();

            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');

            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('paid_reference', 100)->nullable();

            $table->timestamps();

            $table->index(['application_id', 'paid_at']);
            $table->index('uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
