<?php

namespace Tests\Feature;

use App\Livewire\Portal\TeamIndex;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    protected ClientAccount $dealer;

    protected ClientAccount $other;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->dealer = ClientAccount::query()->create([
            'name' => 'Highveld Commercial Centurion',
            'type' => 'dealer',
            'quote_acceptance_allowed' => false,
        ]);

        $this->other = ClientAccount::query()->create([
            'name' => 'Kestrel Logistics',
            'type' => 'fleet_operator',
            'quote_acceptance_allowed' => true,
        ]);

        $this->admin = User::factory()->create([
            'name' => 'Dealer Admin',
            'email' => 'admin@dealer.test',
            'client_account_id' => $this->dealer->id,
            'is_active' => true,
        ]);
        $this->admin->assignRole('client_admin');
    }

    public function test_guest_cannot_reach_team_page(): void
    {
        $this->get('/team')->assertRedirect(route('login'));
    }

    public function test_client_user_cannot_reach_team_page(): void
    {
        $user = User::factory()->create([
            'client_account_id' => $this->dealer->id,
            'is_active' => true,
        ]);
        $user->assignRole('client_user');

        $this->actingAs($user)->get('/team')->assertForbidden();
    }

    public function test_licensing_staff_cannot_reach_team_page(): void
    {
        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->assignRole('reviewer');

        $this->actingAs($reviewer)->get('/team')->assertForbidden();
    }

    public function test_client_admin_sees_their_team_only(): void
    {
        $teammate = User::factory()->create([
            'name' => 'Mia Mine',
            'client_account_id' => $this->dealer->id,
            'is_active' => true,
        ]);
        $teammate->assignRole('client_user');

        $outsider = User::factory()->create([
            'name' => 'Oren Other',
            'client_account_id' => $this->other->id,
            'is_active' => true,
        ]);
        $outsider->assignRole('client_user');

        $this->actingAs($this->admin)
            ->get('/team')
            ->assertOk()
            ->assertSee('Mia Mine')
            ->assertSee('Dealer Admin')
            ->assertDontSee('Oren Other');
    }

    public function test_client_admin_can_create_a_team_user(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TeamIndex::class)
            ->call('openCreate')
            ->set('name', 'Nia New')
            ->set('email', 'nia@dealer.test')
            ->set('role', 'client_user')
            ->set('password', 'change-me-9')
            ->set('password_confirmation', 'change-me-9')
            ->call('createMember')
            ->assertHasNoErrors();

        $member = User::query()->where('email', 'nia@dealer.test')->first();
        $this->assertNotNull($member);
        $this->assertSame($this->dealer->id, $member->client_account_id);
        $this->assertTrue($member->hasRole('client_user'));
        $this->assertTrue(Hash::check('change-me-9', $member->password));
    }

    public function test_client_admin_can_create_another_admin(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TeamIndex::class)
            ->call('openCreate')
            ->set('name', 'Second Admin')
            ->set('email', 'second@dealer.test')
            ->set('role', 'client_admin')
            ->set('password', 'super-safe-9')
            ->set('password_confirmation', 'super-safe-9')
            ->call('createMember')
            ->assertHasNoErrors();

        $member = User::query()->where('email', 'second@dealer.test')->firstOrFail();
        $this->assertTrue($member->hasRole('client_admin'));
    }

    public function test_create_member_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@dealer.test', 'is_active' => true]);

        Livewire::actingAs($this->admin)
            ->test(TeamIndex::class)
            ->call('openCreate')
            ->set('name', 'Dup')
            ->set('email', 'dup@dealer.test')
            ->set('role', 'client_user')
            ->set('password', 'change-me-9')
            ->set('password_confirmation', 'change-me-9')
            ->call('createMember')
            ->assertHasErrors(['email']);
    }

    public function test_create_member_requires_strong_password(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TeamIndex::class)
            ->call('openCreate')
            ->set('name', 'Weak')
            ->set('email', 'weak@dealer.test')
            ->set('role', 'client_user')
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('createMember')
            ->assertHasErrors(['password']);
    }

    public function test_client_admin_can_reset_a_members_password(): void
    {
        $teammate = User::factory()->create([
            'name' => 'Reset Me',
            'password' => Hash::make('original-password'),
            'client_account_id' => $this->dealer->id,
            'is_active' => true,
        ]);
        $teammate->assignRole('client_user');

        Livewire::actingAs($this->admin)
            ->test(TeamIndex::class)
            ->call('startPasswordReset', $teammate->id)
            ->set('resetPassword', 'freshly-reset-9')
            ->set('resetPasswordConfirmation', 'freshly-reset-9')
            ->call('completePasswordReset')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('freshly-reset-9', $teammate->fresh()->password));
    }

    public function test_client_admin_can_deactivate_and_reactivate_a_teammate(): void
    {
        $teammate = User::factory()->create([
            'client_account_id' => $this->dealer->id,
            'is_active' => true,
        ]);
        $teammate->assignRole('client_user');

        Livewire::actingAs($this->admin)
            ->test(TeamIndex::class)
            ->call('deactivate', $teammate->id);
        $this->assertFalse($teammate->fresh()->is_active);

        Livewire::actingAs($this->admin)
            ->test(TeamIndex::class)
            ->call('activate', $teammate->id);
        $this->assertTrue($teammate->fresh()->is_active);
    }

    public function test_client_admin_cannot_deactivate_themself(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TeamIndex::class)
            ->call('deactivate', $this->admin->id);

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_client_admin_cannot_strand_the_last_admin(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TeamIndex::class)
            ->call('deactivate', $this->admin->id);

        $this->assertTrue($this->admin->fresh()->is_active);

        $second = User::factory()->create([
            'client_account_id' => $this->dealer->id,
            'is_active' => true,
        ]);
        $second->assignRole('client_admin');

        Livewire::actingAs($this->admin)
            ->test(TeamIndex::class)
            ->call('deactivate', $second->id)
            ->assertHasNoErrors();

        $this->assertFalse($second->fresh()->is_active);
    }

    public function test_client_admin_cannot_touch_outside_account_users(): void
    {
        $outsider = User::factory()->create([
            'client_account_id' => $this->other->id,
            'is_active' => true,
        ]);
        $outsider->assignRole('client_user');

        Livewire::actingAs($this->admin)
            ->test(TeamIndex::class)
            ->call('deactivate', $outsider->id)
            ->assertStatus(404);
    }
}
