<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licence_estimates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();

            // Inputs — captured so a saved estimate always explains itself.
            $table->string('province', 32);
            $table->string('licence_category', 32);
            $table->unsignedInteger('tare_kg')->nullable();
            $table->date('applicable_date');

            // Fee-schedule snapshot (never silently replaced on recalc).
            $table->foreignId('fee_table_version_id')->nullable()->constrained('fee_table_versions')->nullOnDelete();
            $table->unsignedInteger('fee_table_version_number')->nullable();
            $table->date('fee_table_effective_from')->nullable();
            $table->date('fee_table_effective_until')->nullable();
            $table->string('fee_line_label')->nullable();
            $table->unsignedInteger('fee_line_tare_min_kg')->nullable();
            $table->unsignedInteger('fee_line_tare_max_kg')->nullable();

            // Money.
            $table->unsignedInteger('licence_fee_cents')->default(0);
            $table->string('licence_fee_tax_treatment', 20)->default('exempt');
            $table->unsignedInteger('admin_charge_cents')->default(0);
            $table->string('admin_charge_tax_treatment', 20)->default('standard');
            $table->unsignedInteger('vat_basis_points')->default(0);
            $table->unsignedInteger('vat_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);

            // Outcome: 'estimated' or 'confirmation_required'.
            $table->string('status', 32)->default('estimated');
            $table->string('confirmation_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index(['client_account_id', 'created_at']);
            $table->index(['application_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_estimates');
    }
};
