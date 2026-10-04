<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->string('vehicle_register_number')->nullable();
            $table->string('vin')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            // VehicleCategory enum: 'passenger' | 'commercial'.
            $table->string('vehicle_category');
            $table->date('licence_expires_on')->nullable();
            // 'scan' when the Tesseract candidate was kept, 'typed' when the
            // reviewer changed it or OCR returned nothing. Null while pending.
            $table->string('licence_expiry_source')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->index(['client_account_id', 'licence_expires_on']);
            $table->index(['client_account_id', 'retired_at']);
            $table->index('vin');
        });

        Schema::create('fleet_vehicle_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fleet_vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            // 'pending' | 'clean' | 'unreadable' | 'failed'.
            $table->string('ocr_status')->default('pending');
            $table->string('ocr_notes')->nullable();
            $table->date('ocr_expiry_candidate')->nullable();
            $table->string('ocr_register_candidate')->nullable();
            $table->string('ocr_vin_candidate')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('ocr_status');
        });

        Schema::create('fleet_vehicle_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fleet_vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // 'created' | 'confirmed' | 'corrected' | 'retired' | 'ocr_completed'.
            $table->string('action');
            $table->string('summary');
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['fleet_vehicle_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_vehicle_events');
        Schema::dropIfExists('fleet_vehicle_documents');
        Schema::dropIfExists('fleet_vehicles');
    }
};
