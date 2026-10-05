<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoOwnerAccountMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_demo_super_admin_becomes_the_advertised_owner_login(): void
    {
        $owner = User::factory()->create(['email' => 'super.admin@licentra.test', 'name' => 'Super admin']);

        $this->runMigration();

        $owner->refresh();
        $this->assertSame('owner@licentra.test', $owner->email);
        $this->assertSame('Owner', $owner->name);
    }

    public function test_existing_owner_login_is_left_alone(): void
    {
        $owner = User::factory()->create(['email' => 'owner@licentra.test']);
        $old = User::factory()->create(['email' => 'super.admin@licentra.test']);

        $this->runMigration();

        $this->assertSame('owner@licentra.test', $owner->refresh()->email);
        $this->assertSame('super.admin@licentra.test', $old->refresh()->email);
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_10_05_162235_rename_demo_super_admin_to_owner.php');
        $migration->up();
    }
}
