<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dangerous goods request means the certificate of fitness has to be
 * stamped Dangerous goods before a reviewer can accept it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->boolean('dangerous_goods')->default(false)->after('is_financed');
        });

        Schema::table('application_documents', function (Blueprint $table): void {
            $table->boolean('dangerous_goods_stamped')->nullable()->after('reviewer_comment');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('dangerous_goods');
        });

        Schema::table('application_documents', function (Blueprint $table): void {
            $table->dropColumn('dangerous_goods_stamped');
        });
    }
};
