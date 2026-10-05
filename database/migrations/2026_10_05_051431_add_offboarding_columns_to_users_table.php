<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('offboarded_at')->nullable()->after('is_active');
            $table->string('offboard_reason', 32)->nullable()->after('offboarded_at');
            $table->text('offboard_note')->nullable()->after('offboard_reason');
            $table->foreignId('offboarded_by_id')
                ->nullable()
                ->after('offboard_note')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('anonymised_at')->nullable()->after('offboarded_by_id');

            $table->index('offboarded_at');
            $table->index('anonymised_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['offboarded_at']);
            $table->dropIndex(['anonymised_at']);
            $table->dropConstrainedForeignId('offboarded_by_id');
            $table->dropColumn([
                'offboarded_at',
                'offboard_reason',
                'offboard_note',
                'anonymised_at',
            ]);
        });
    }
};
