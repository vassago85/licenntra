<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewerOperationsLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewer_role_is_shown_as_operations_in_the_portal(): void
    {
        $this->seed(RoleSeeder::class);
        $reviewer = User::factory()->create(['name' => 'Nadia Naidoo', 'is_active' => true]);
        $reviewer->assignRole('reviewer');

        $this->actingAs($reviewer)
            ->get(route('review.queue'))
            ->assertOk()
            ->assertSee('Operations')
            ->assertDontSee('>Reviewer<', false);
    }

    public function test_forbidden_page_names_the_role_by_its_label(): void
    {
        $this->seed(RoleSeeder::class);
        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->assignRole('reviewer');

        $this->actingAs($reviewer)
            ->get(route('applications.index'))
            ->assertForbidden()
            ->assertSee('Your role (Operations)');
    }

    public function test_demo_reviewer_login_is_renamed_to_operations(): void
    {
        $demo = User::factory()->create(['email' => 'reviewer@licentra.test', 'name' => 'Reviewer']);
        $renamedStaff = User::factory()->create(['email' => 'reviewer@licentra.test.other', 'name' => 'Reviewer']);
        $customised = User::factory()->create(['email' => 'nadia@licentra.test', 'name' => 'Nadia Naidoo']);

        $migration = require database_path('migrations/2026_10_05_163300_rename_demo_reviewer_to_operations.php');
        $migration->up();

        $this->assertSame('Operations', $demo->refresh()->name);
        $this->assertSame('Reviewer', $renamedStaff->refresh()->name);
        $this->assertSame('Nadia Naidoo', $customised->refresh()->name);
    }
}
