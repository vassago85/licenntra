<?php

namespace App\Console\Commands;

use App\Models\BrandingSetting;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DocumentRuleSeeder;
use Database\Seeders\FeeTableSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('licentra:install {--name= : Name of the first customer admin} {--email= : Email of the first customer admin} {--password= : Password of the first customer admin}')]
#[Description('Create the first customer admin, branding, document rules, and fee tables')]
class InstallLicentra extends Command
{
    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => DocumentRuleSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => FeeTableSeeder::class, '--force' => true]);

        BrandingSetting::current();
        SystemSetting::current();

        $email = $this->option('email') ?: $this->ask('Customer admin email');
        $name = $this->option('name') ?: $this->ask('Customer admin name', 'Customer admin');
        $password = $this->option('password') ?: $this->secret('Customer admin password');

        if (blank($email) || blank($password)) {
            $this->error('An email and password are required.');

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => $password,
            'is_active' => true,
            'email_verified_at' => now(),
            'client_account_id' => null,
        ]);
        $user->syncRoles(['customer_admin']);

        $this->info('Licentra is installed. Sign in at /login or /admin.');

        return self::SUCCESS;
    }
}
