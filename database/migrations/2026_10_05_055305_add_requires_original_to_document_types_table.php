<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mark certain document types (currently the original NaTIS / RC1
     * for a change of ownership) as "a copy may be uploaded, but the
     * original must be physically collected from the seller before the
     * pack can be shipped to the licensing authority".
     */
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->boolean('requires_original')->default(false)->after('max_age_days');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->dropColumn('requires_original');
        });
    }
};
