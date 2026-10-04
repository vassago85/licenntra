<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('page_count')->nullable()->after('scan_status');
            $table->boolean('has_text_layer')->nullable()->after('page_count');
            $table->string('inspection_status', 32)->default('pending')->after('has_text_layer');
            $table->string('inspection_notes')->nullable()->after('inspection_status');
            $table->text('text_excerpt')->nullable()->after('inspection_notes');
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->dropColumn([
                'page_count',
                'has_text_layer',
                'inspection_status',
                'inspection_notes',
                'text_excerpt',
            ]);
        });
    }
};
