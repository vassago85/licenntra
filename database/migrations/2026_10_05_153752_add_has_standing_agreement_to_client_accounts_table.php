<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->boolean('has_standing_agreement')->default(false)->after('quote_acceptance_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->dropColumn('has_standing_agreement');
        });
    }
};
