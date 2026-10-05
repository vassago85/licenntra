<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the "one sidebar for every user" promise: every role that can see
 * the app must get the shared portal sidebar on every surface they can
 * reach, with the right sections visible and the wrong ones hidden.
 */
class UnifiedSidebarTest extends TestCase
{
    use RefreshDatabase;

    private const SIDEBAR_MARKER = 'Powered by Licentra';

    private const ADMIN_SECTION = 'Administration';

    private const COMPLIANCE_SECTION = 'Compliance';

    private const PLATFORM_SECTION = 'Platform';

    protected ClientAccount $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->dealer = ClientAccount::query()->create([
            'name' => 'Highveld Commercial Centurion',
            'type' => 'dealer',
            'quote_acceptance_allowed' => false,
        ]);
    }

    /* ---------------------------------------------------------------
     * Chrome parity: the sidebar renders on both the Livewire portal
     * and the Filament panel for every role that can see each surface.
     * ------------------------------------------------------------- */

    public function test_super_admin_sees_the_shared_sidebar_on_both_surfaces(): void
    {
        $user = $this->staff('super_admin');

        $this->actingAs($user)->get(route('review.queue'))->assertOk()->assertSee(self::SIDEBAR_MARKER);
        $this->actingAs($user)->get('/admin/users')->assertOk()->assertSee(self::SIDEBAR_MARKER);
        $this->actingAs($user)->get('/admin/platform-billing')->assertOk()->assertSee(self::SIDEBAR_MARKER);
    }

    public function test_customer_admin_sees_the_shared_sidebar_on_both_surfaces(): void
    {
        $user = $this->staff('customer_admin');

        $this->actingAs($user)->get(route('review.queue'))->assertOk()->assertSee(self::SIDEBAR_MARKER);
        $this->actingAs($user)->get('/admin/users')->assertOk()->assertSee(self::SIDEBAR_MARKER);
    }

    public function test_reviewer_sees_the_shared_sidebar_on_both_surfaces(): void
    {
        $user = $this->staff('reviewer');

        $this->actingAs($user)->get(route('review.queue'))->assertOk()->assertSee(self::SIDEBAR_MARKER);
        $this->actingAs($user)->get('/admin/outstanding-tasks')->assertOk()->assertSee(self::SIDEBAR_MARKER);
    }

    public function test_finance_sees_the_shared_sidebar_on_both_surfaces(): void
    {
        $user = $this->staff('finance');

        $this->actingAs($user)->get(route('finance.payments'))->assertOk()->assertSee(self::SIDEBAR_MARKER);
        $this->actingAs($user)->get('/admin/outstanding-tasks')->assertOk()->assertSee(self::SIDEBAR_MARKER);
    }

    public function test_auditor_sees_the_shared_sidebar_on_both_surfaces(): void
    {
        $user = $this->staff('auditor');

        $this->actingAs($user)->get(route('review.queue'))->assertOk()->assertSee(self::SIDEBAR_MARKER);
        $this->actingAs($user)->get('/admin/audit-events')->assertOk()->assertSee(self::SIDEBAR_MARKER);
    }

    public function test_developer_sees_the_shared_sidebar_on_platform_billing(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('developer');

        $this->actingAs($user)
            ->get('/admin/platform-billing')
            ->assertOk()
            ->assertSee(self::SIDEBAR_MARKER)
            ->assertSee(self::PLATFORM_SECTION)
            ->assertSee('Platform developer');
    }

    public function test_client_admin_sees_the_shared_sidebar_on_the_client_portal(): void
    {
        $client = $this->client('client_admin');

        $this->actingAs($client)
            ->get(route('applications.index'))
            ->assertOk()
            ->assertSee(self::SIDEBAR_MARKER)
            ->assertSee('Client portal');
    }

    public function test_client_user_sees_the_shared_sidebar_on_the_client_portal(): void
    {
        $client = $this->client('client_user');

        $this->actingAs($client)
            ->get(route('applications.index'))
            ->assertOk()
            ->assertSee(self::SIDEBAR_MARKER)
            ->assertSee('Client portal');
    }

    /* ---------------------------------------------------------------
     * Section visibility: each role only sees the sidebar groups it
     * is actually permitted to use.
     * ------------------------------------------------------------- */

    public function test_super_admin_sees_every_sidebar_section(): void
    {
        $this->actingAs($this->staff('super_admin'))
            ->get(route('review.queue'))
            ->assertOk()
            ->assertSee(self::ADMIN_SECTION)
            ->assertSee(self::COMPLIANCE_SECTION)
            ->assertSee(self::PLATFORM_SECTION)
            ->assertSee('Users')
            ->assertSee('Audit log')
            ->assertSee('Platform billing');
    }

    public function test_customer_admin_sees_administration_and_compliance_but_not_platform(): void
    {
        $response = $this->actingAs($this->staff('customer_admin'))
            ->get(route('review.queue'))
            ->assertOk();

        $response->assertSee(self::ADMIN_SECTION);
        $response->assertSee(self::COMPLIANCE_SECTION);
        $response->assertDontSee(self::PLATFORM_SECTION);
    }

    public function test_reviewer_sees_operations_only(): void
    {
        $response = $this->actingAs($this->staff('reviewer'))
            ->get(route('review.queue'))
            ->assertOk();

        $response->assertSee('Review queue');
        $response->assertDontSee(self::ADMIN_SECTION);
        $response->assertDontSee(self::COMPLIANCE_SECTION);
        $response->assertDontSee(self::PLATFORM_SECTION);
    }

    public function test_finance_sees_operations_only(): void
    {
        $response = $this->actingAs($this->staff('finance'))
            ->get(route('finance.payments'))
            ->assertOk();

        $response->assertSee('Review queue');
        $response->assertDontSee(self::ADMIN_SECTION);
        $response->assertDontSee(self::COMPLIANCE_SECTION);
    }

    public function test_auditor_sees_compliance_but_not_administration_or_platform(): void
    {
        $response = $this->actingAs($this->staff('auditor'))
            ->get(route('review.queue'))
            ->assertOk();

        $response->assertSee(self::COMPLIANCE_SECTION);
        $response->assertSee('Audit log');
        $response->assertDontSee(self::ADMIN_SECTION);
        $response->assertDontSee(self::PLATFORM_SECTION);
    }

    public function test_developer_sees_platform_section_only(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('developer');

        $response = $this->actingAs($user)->get('/admin/platform-billing')->assertOk();

        $response->assertSee(self::PLATFORM_SECTION);
        $response->assertSee('Platform billing');
        $response->assertDontSee(self::ADMIN_SECTION);
        $response->assertDontSee(self::COMPLIANCE_SECTION);
    }

    public function test_client_sidebar_hides_every_staff_section(): void
    {
        $response = $this->actingAs($this->client('client_admin'))
            ->get(route('applications.index'))
            ->assertOk();

        $response->assertDontSee(self::ADMIN_SECTION);
        $response->assertDontSee(self::COMPLIANCE_SECTION);
        $response->assertDontSee(self::PLATFORM_SECTION);
        $response->assertDontSee('Audit log');
        $response->assertDontSee('Platform billing');
        $response->assertDontSee('Users');
    }

    public function test_client_user_does_not_see_the_team_link(): void
    {
        $response = $this->actingAs($this->client('client_user'))
            ->get(route('applications.index'))
            ->assertOk();

        // Team is only visible to client_admin, not client_user.
        $response->assertDontSee('>Team<', escape: false);
    }

    /* ---------------------------------------------------------------
     * Access denials: roles that must not reach a surface are blocked
     * (either 403 or redirect), and no shell is leaked to them.
     * ------------------------------------------------------------- */

    public function test_client_admin_is_blocked_from_the_admin_panel(): void
    {
        $this->actingAs($this->client('client_admin'))
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_client_user_is_blocked_from_the_admin_panel(): void
    {
        $this->actingAs($this->client('client_user'))
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_reviewer_is_blocked_from_configuration_resources(): void
    {
        $this->actingAs($this->staff('reviewer'))
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_finance_is_blocked_from_configuration_resources(): void
    {
        $this->actingAs($this->staff('finance'))
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_auditor_is_blocked_from_configuration_resources(): void
    {
        $this->actingAs($this->staff('auditor'))
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_non_platform_users_are_blocked_from_platform_billing(): void
    {
        $this->actingAs($this->staff('customer_admin'))
            ->get('/admin/platform-billing')
            ->assertForbidden();

        $this->actingAs($this->staff('reviewer'))
            ->get('/admin/platform-billing')
            ->assertForbidden();
    }

    public function test_guest_hits_the_login_page_without_the_portal_shell(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        // The portal sidebar must NOT render on the login page — otherwise
        // we leak brand/role chrome to unauthenticated visitors and the
        // login form sits next to a dead sidebar.
        $response->assertDontSee(self::SIDEBAR_MARKER);
    }

    public function test_guest_is_redirected_to_login_from_portal_routes(): void
    {
        $this->get(route('review.queue'))->assertRedirect(route('login'));
        $this->get(route('applications.index'))->assertRedirect(route('login'));
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function client(string $role): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'client_account_id' => $this->dealer->id,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
