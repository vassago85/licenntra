<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->string('status')->default('active');
            $table->boolean('quote_acceptance_allowed')->default(false);
            // ASSUMPTION: markup is basis points on the fee subtotal (150 = 1.50%).
            $table->unsignedInteger('markup_basis_points')->default(0);
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('client_account_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('business_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->text('registration_number')->nullable();
            $table->string('proxy_name')->nullable();
            $table->string('proxy_contact')->nullable();
            $table->text('proxy_id_number')->nullable();
            $table->text('address')->nullable();
            $table->string('usable_as')->default('owner');
            $table->unsignedSmallInteger('retention_period_months')->nullable();
            $table->timestamp('retention_expires_at')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('retention_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('period_months');
            $table->timestamp('confirmed_at');
            $table->string('wording_version');
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->string('request_type')->nullable();
            $table->string('service_type')->nullable();
            $table->string('vehicle_category')->nullable();
            $table->string('owner_type')->nullable();
            $table->foreignId('business_client_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_financed')->default(false);
            $table->foreignId('title_holder_business_client_id')->nullable()->constrained('business_clients')->nullOnDelete();
            $table->string('province')->nullable();
            $table->string('stage')->default('draft');
            $table->string('datafix_status')->default('not_required');
            $table->foreignId('assigned_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->json('fee_snapshot')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->timestamps();
            $table->index(['client_account_id', 'stage']);
        });

        Schema::create('stage_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('vin')->nullable();
            $table->string('vehicle_register_number')->nullable();
            $table->string('engine_number')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('body_type')->nullable();
            $table->unsignedInteger('tare_kg')->nullable();
            $table->unsignedInteger('gvm_kg')->nullable();
            $table->string('vehicle_class')->nullable();
            $table->timestamps();
            $table->index('vin');
            $table->index('vehicle_register_number');
        });

        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('party_type');
            $table->string('name')->nullable();
            $table->text('identifier')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('business_client_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_identity_document')->default(false);
            $table->unsignedSmallInteger('max_age_days')->nullable();
            $table->timestamps();
        });

        Schema::create('document_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->string('request_type')->nullable();
            $table->string('vehicle_category')->nullable();
            $table->string('owner_type')->nullable();
            $table->string('province')->nullable();
            $table->boolean('is_financed')->nullable();
            $table->string('party_role')->default('vehicle');
            $table->string('requirement')->default('required');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained();
            $table->string('party_role')->default('vehicle');
            $table->boolean('required')->default(true);
            $table->string('status')->default('missing');
            $table->string('source')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->text('reviewer_comment')->nullable();
            $table->foreignId('business_client_document_id')->nullable();
            $table->foreignId('linked_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('business_client_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
            $table->unique(['business_client_id', 'document_type_id']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('business_client_document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime');
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->string('scan_status')->default('pending');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('datafix_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('not_required');
            $table->unsignedInteger('tare_kg')->nullable();
            $table->string('body_type')->nullable();
            $table->unsignedInteger('gvm_kg')->nullable();
            $table->unsignedInteger('client_tare_kg')->nullable();
            $table->string('client_body_type')->nullable();
            $table->unsignedInteger('client_gvm_kg')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('authority_reference')->nullable();
            $table->text('query_note')->nullable();
            $table->timestamps();
        });

        Schema::create('fee_tables', function (Blueprint $table) {
            $table->id();
            $table->string('province');
            $table->string('name');
            $table->timestamps();
            $table->unique('province');
        });

        Schema::create('fee_table_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_table_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fee_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_table_version_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('label');
            $table->unsignedInteger('amount_cents');
            $table->boolean('client_visible')->default(true);
            $table->string('request_type')->nullable();
            $table->string('vehicle_category')->nullable();
            $table->string('service_type')->nullable();
            $table->string('vehicle_class')->nullable();
            $table->unsignedInteger('tare_min_kg')->nullable();
            $table->unsignedInteger('tare_max_kg')->nullable();
            $table->timestamps();
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->timestamp('expires_at')->nullable();
            $table->text('client_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('client_price_cents');
            $table->unsignedInteger('internal_cost_cents')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('method')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('proof_document_id')->nullable()->constrained('application_documents')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('override_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('visibility');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('export_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('step');
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'step']);
        });

        Schema::create('branding_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->default('Licentra');
            $table->string('logo_path')->nullable();
            $table->string('primary_colour')->default('#1F47B8');
            $table->string('support_email')->nullable();
            $table->string('support_phone')->nullable();
            $table->text('address')->nullable();
            $table->string('reference_prefix')->default('LIC');
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            // ASSUMPTION: VAT is stored as basis points. 1500 = 15.00%.
            $table->unsignedInteger('vat_basis_points')->default(1500);
            $table->unsignedSmallInteger('idle_timeout_minutes')->default(30);
            $table->unsignedSmallInteger('absolute_timeout_minutes')->default(480);
            $table->json('retention_period_options')->nullable();
            $table->unsignedSmallInteger('retention_max_months')->default(24);
            $table->unsignedSmallInteger('archive_after_days')->default(90);
            $table->json('sla_hours')->nullable();
            $table->boolean('enforce_client_two_factor')->default(false);
            $table->string('retention_wording_version')->default('1');
            $table->text('retention_wording')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('action');
            $table->text('summary');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->boolean('is_system')->default(false);
            $table->index(['subject_type', 'subject_id']);
        });

        $this->installAppendOnlyTrigger();
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events');
            DB::unprepared('DROP FUNCTION IF EXISTS licentra_reject_audit_mutation()');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_delete');
        }

        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('branding_settings');
        Schema::dropIfExists('export_steps');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('fee_lines');
        Schema::dropIfExists('fee_table_versions');
        Schema::dropIfExists('fee_tables');
        Schema::dropIfExists('datafix_records');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('business_client_documents');
        Schema::dropIfExists('application_documents');
        Schema::dropIfExists('document_rules');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('parties');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('stage_histories');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('retention_consents');
        Schema::dropIfExists('business_clients');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_account_id');
            $table->dropColumn('is_active');
        });
        Schema::dropIfExists('client_accounts');
    }

    private function installAppendOnlyTrigger(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION licentra_reject_audit_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'audit_events is append-only';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER audit_events_append_only
BEFORE UPDATE OR DELETE ON audit_events
FOR EACH ROW EXECUTE FUNCTION licentra_reject_audit_mutation();
SQL);
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER audit_events_no_update
BEFORE UPDATE ON audit_events
BEGIN
    SELECT RAISE(ABORT, 'audit_events is append-only');
END;
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER audit_events_no_delete
BEFORE DELETE ON audit_events
BEGIN
    SELECT RAISE(ABORT, 'audit_events is append-only');
END;
SQL);
        }
    }
};
