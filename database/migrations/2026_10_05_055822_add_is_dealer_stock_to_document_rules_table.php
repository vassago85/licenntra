<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable boolean filter on document_rules so a given document can
     * be scoped to "dealer stock only" (true), "non dealer stock only"
     * (false), or "either" (null) - mirrors the semantics of is_financed.
     */
    public function up(): void
    {
        Schema::table('document_rules', function (Blueprint $table): void {
            $table->boolean('is_dealer_stock')->nullable()->after('is_financed');
        });
    }

    public function down(): void
    {
        Schema::table('document_rules', function (Blueprint $table): void {
            $table->dropColumn('is_dealer_stock');
        });
    }
};
