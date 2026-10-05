<?php

namespace Tests\Feature;

use App\Livewire\Account\Settings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_guest_cannot_reach_account_page(): void
    {
        $this->get('/account')->assertRedirect(route('login'));
    }

    public function test_signed_in_user_sees_their_account_page(): void
    {
        $user = User::factory()->create([
            'name' => 'Dawn Example',
            'email' => 'dawn@example.test',
            'is_active' => true,
        ]);
        $user->assignRole('customer_user');

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('Account settings')
            ->assertSee('dawn@example.test');
    }

    public function test_user_can_update_their_name_and_email(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.test',
            'is_active' => true,
        ]);
        $user->assignRole('reviewer');

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('name', 'New Name')
            ->set('email', 'new@example.test')
            ->call('updateProfile')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.test', $user->email);
    }

    public function test_email_must_be_unique_across_users(): void
    {
        User::factory()->create(['email' => 'taken@example.test', 'is_active' => true]);
        $me = User::factory()->create(['email' => 'me@example.test', 'is_active' => true]);
        $me->assignRole('customer_admin');

        Livewire::actingAs($me)
            ->test(Settings::class)
            ->set('name', $me->name)
            ->set('email', 'taken@example.test')
            ->call('updateProfile')
            ->assertHasErrors(['email']);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-horse-battery'),
            'is_active' => true,
        ]);
        $user->assignRole('customer_admin');

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('current_password', 'wrong-password')
            ->set('password', 'brand-new-password')
            ->set('password_confirmation', 'brand-new-password')
            ->call('updatePassword')
            ->assertHasErrors(['current_password']);

        $this->assertTrue(Hash::check('correct-horse-battery', $user->fresh()->password));
    }

    public function test_user_can_change_their_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-horse-battery'),
            'is_active' => true,
        ]);
        $user->assignRole('customer_admin');

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('current_password', 'correct-horse-battery')
            ->set('password', 'brand-new-pass-9')
            ->set('password_confirmation', 'brand-new-pass-9')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-pass-9', $user->fresh()->password));
    }

    public function test_password_confirmation_must_match(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password-ok'),
            'is_active' => true,
        ]);
        $user->assignRole('customer_user');

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('current_password', 'old-password-ok')
            ->set('password', 'brand-new-password')
            ->set('password_confirmation', 'different-value')
            ->call('updatePassword')
            ->assertHasErrors(['password']);

        $this->assertTrue(Hash::check('old-password-ok', $user->fresh()->password));
    }
}
