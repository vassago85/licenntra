<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records the moment the dealer confirmed the physical original of a
     * document (e.g. an original NaTIS / RC1 for a change of ownership)
     * is in hand and ready to be forwarded to the licensing authority.
     * Null means "copy may have been uploaded but no original on file
     * yet".
     */
    public function up(): void
    {
        Schema::table('application_documents', function (Blueprint $table): void {
            $table->timestamp('original_received_at')->nullable()->after('dangerous_goods_stamped');
        });
    }

    public function down(): void
    {
        Schema::table('application_documents', function (Blueprint $table): void {
            $table->dropColumn('original_received_at');
        });
    }
};
