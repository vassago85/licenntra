<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->string('mailgun_domain')->nullable()->after('enforce_client_two_factor');
            $table->text('mailgun_secret')->nullable()->after('mailgun_domain');
            $table->string('mailgun_endpoint')->nullable()->after('mailgun_secret');
            $table->string('mail_from_address')->nullable()->after('mailgun_endpoint');
            $table->string('mail_from_name')->nullable()->after('mail_from_address');
            $table->boolean('notifications_enabled')->default(true)->after('mail_from_name');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'mailgun_domain',
                'mailgun_secret',
                'mailgun_endpoint',
                'mail_from_address',
                'mail_from_name',
                'notifications_enabled',
            ]);
        });
    }
};
