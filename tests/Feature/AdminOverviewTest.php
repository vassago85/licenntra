<?php

use App\Models\AuditEvent;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

function overviewUser(string $role, ?int $clientAccountId = null): User
{
    $user = User::factory()->create(['is_active' => true, 'client_account_id' => $clientAccountId]);
    $user->assignRole($role);

    return $user;
}

it('shows operations and money panels to finance and the owner', function (string $role): void {
    $this->actingAs(overviewUser($role))
        ->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('Outstanding tasks')
        ->assertSee('Dealerships needing action')
        ->assertSee('Verified this month')
        ->assertSee('Top customers');
})->with(['owner', 'finance']);

it('shows a reviewer the operations panels without the money', function (): void {
    $this->actingAs(overviewUser('reviewer'))
        ->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('Outstanding tasks')
        ->assertSee('Recent activity')
        ->assertDontSee('Verified this month')
        ->assertDontSee('Top customers');
});

it('lists recent audit activity', function (): void {
    $owner = overviewUser('owner');
    $account = ClientAccount::query()->create(['name' => 'Highveld', 'type' => 'dealer']);

    AuditEvent::query()->create([
        'actor_user_id' => $owner->id,
        'subject_type' => ClientAccount::class,
        'subject_id' => $account->id,
        'action' => 'client_account.updated',
        'summary' => 'Markup changed to 2.5%.',
        'occurred_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('Markup changed to 2.5%.');
});

it('sends the platform developer to platform billing', function (): void {
    $this->actingAs(overviewUser('developer'))
        ->get(route('admin.overview'))
        ->assertRedirect(route('platform.billing'));
});

it('forbids dealer logins', function (): void {
    $account = ClientAccount::query()->create(['name' => 'Highveld', 'type' => 'dealer']);

    $this->actingAs(overviewUser('customer_admin', $account->id))
        ->get(route('admin.overview'))
        ->assertForbidden();
});
