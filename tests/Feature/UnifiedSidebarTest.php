<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the "one sidebar for every user" promise: both the Livewire portal
 * (`/review`) and the Filament admin panel (`/admin/users`) must render the
 * same portal sidebar component. The sidebar includes a stable marker — the
 * "Powered by Licentra" footer — which we assert on both surfaces.
 */
class UnifiedSidebarTest extends TestCase
{
    use RefreshDatabase;

    private const SIDEBAR_MARKER = 'Powered by Licentra';

    private const ADMIN_SECTION = 'Administration';

    private const COMPLIANCE_SECTION = 'Compliance';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_portal_review_queue_renders_the_shared_sidebar(): void
    {
        $reviewer = $this->staff('reviewer');

        $response = $this->actingAs($reviewer)->get(route('review.queue'));

        $response->assertOk();
        $response->assertSee(self::SIDEBAR_MARKER);
    }

    public function test_filament_admin_page_renders_the_same_shared_sidebar(): void
    {
        $admin = $this->staff('super_admin');

        $response = $this->actingAs($admin)->get('/admin/users');

        $response->assertOk();
        $response->assertSee(self::SIDEBAR_MARKER);
    }

    public function test_admin_section_appears_for_super_admin_on_both_surfaces(): void
    {
        $admin = $this->staff('super_admin');

        $this->actingAs($admin)
            ->get(route('review.queue'))
            ->assertOk()
            ->assertSee(self::ADMIN_SECTION)
            ->assertSee('Audit log');

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee(self::ADMIN_SECTION)
            ->assertSee('Audit log');
    }

    public function test_reviewer_does_not_see_admin_section(): void
    {
        $reviewer = $this->staff('reviewer');

        $this->actingAs($reviewer)
            ->get(route('review.queue'))
            ->assertOk()
            ->assertDontSee(self::ADMIN_SECTION);
    }

    public function test_auditor_sees_compliance_but_not_administration(): void
    {
        $auditor = $this->staff('auditor');

        $response = $this->actingAs($auditor)->get(route('review.queue'));

        $response->assertOk();
        $response->assertSee(self::COMPLIANCE_SECTION);
        $response->assertDontSee(self::ADMIN_SECTION);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
