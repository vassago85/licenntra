<?php

use App\Livewire\Portal\BusinessClientForm;
use App\Models\AuditEvent;
use App\Models\BusinessClient;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->dealer = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->otherDealer = ClientAccount::query()->create([
        'name' => 'Kestrel Fleet',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->admin = User::factory()->create([
        'client_account_id' => $this->dealer->id,
        'is_active' => true,
    ]);
    $this->admin->assignRole('client_admin');

    $this->normalUser = User::factory()->create([
        'client_account_id' => $this->dealer->id,
        'is_active' => true,
    ]);
    $this->normalUser->assignRole('client_user');
});

it('lets a client_admin add a business client from the portal form', function (): void {
    Livewire::actingAs($this->admin)
        ->test(BusinessClientForm::class)
        ->set('business_name', 'Alpha Logistics')
        ->set('registration_number', '2020/123456/07')
        ->set('proxy_name', 'Thandi Mokoena')
        ->set('proxy_contact', 'thandi@alpha.test')
        ->set('proxy_id_number', '8001015800084')
        ->set('address', '12 Freight Street, Centurion')
        ->set('usable_as', 'owner')
        ->set('status', 'active')
        ->call('save')
        ->assertRedirect();

    $created = BusinessClient::query()->where('business_name', 'Alpha Logistics')->firstOrFail();

    expect($created->client_account_id)->toBe($this->dealer->id)
        ->and($created->proxy_name)->toBe('Thandi Mokoena')
        ->and($created->registration_number)->toBe('2020/123456/07')
        ->and($created->proxy_id_number)->toBe('8001015800084')
        ->and($created->usable_as)->toBe('owner');
});

it('blocks a plain client_user from adding a business client (new strict policy)', function (): void {
    // Under the strict policy only client_admins (and licensing staff)
    // may create business clients. A dealer sales user can still pick
    // existing records on an application form but cannot spawn new
    // owner / finance-house rows that leak across the dealership.
    $this->actingAs($this->normalUser)
        ->get(route('business-clients.create'))
        ->assertForbidden();
});

it('rejects an empty business name', function (): void {
    Livewire::actingAs($this->admin)
        ->test(BusinessClientForm::class)
        ->set('business_name', '')
        ->call('save')
        ->assertHasErrors(['business_name']);

    expect(BusinessClient::query()->count())->toBe(0);
});

it('always writes the new client under the actor\'s own account - never another dealer\'s', function (): void {
    Livewire::actingAs($this->admin)
        ->test(BusinessClientForm::class)
        ->set('business_name', 'Scope Test')
        ->set('usable_as', 'owner')
        ->set('status', 'active')
        ->call('save')
        ->assertRedirect();

    $created = BusinessClient::withoutGlobalScopes()
        ->where('business_name', 'Scope Test')
        ->firstOrFail();

    expect($created->client_account_id)->toBe($this->dealer->id)
        ->and($created->client_account_id)->not->toBe($this->otherDealer->id);
});

it('blocks a client_user from editing an existing business client (new strict policy)', function (): void {
    $client = BusinessClient::query()->create([
        'client_account_id' => $this->dealer->id,
        'business_name' => 'Old Name',
        'usable_as' => 'owner',
        'status' => 'active',
    ]);

    $this->actingAs($this->normalUser)
        ->get(route('business-clients.edit', $client))
        ->assertForbidden();
});

it('lets a client_admin edit an existing business client on their own account', function (): void {
    $client = BusinessClient::query()->create([
        'client_account_id' => $this->dealer->id,
        'business_name' => 'Old Name',
        'usable_as' => 'owner',
        'status' => 'active',
    ]);

    Livewire::actingAs($this->admin)
        ->test(BusinessClientForm::class, ['businessClient' => $client])
        ->assertSet('business_name', 'Old Name')
        ->set('business_name', 'Renamed Logistics')
        ->set('address', '99 Updated Road')
        ->call('save')
        ->assertRedirect();

    expect($client->refresh()->business_name)->toBe('Renamed Logistics')
        ->and($client->address)->toBe('99 Updated Road');
});

it('forbids editing a business client that belongs to another dealership', function (): void {
    // Use withoutGlobalScopes-free creation by creating as a guest (no auth).
    $foreign = BusinessClient::query()->create([
        'client_account_id' => $this->otherDealer->id,
        'business_name' => 'Not Yours',
        'usable_as' => 'owner',
        'status' => 'active',
    ]);

    Livewire::actingAs($this->admin);

    // The global scope hides the foreign record from the actor's query, so
    // route-model binding would 404 before policy fires - but belt-and-
    // braces: assert the policy denies it too.
    expect(auth()->user()->can('update', $foreign))->toBeFalse();
});

it('records an audit event on both create and update', function (): void {
    Livewire::actingAs($this->admin)
        ->test(BusinessClientForm::class)
        ->set('business_name', 'Audit Co')
        ->set('usable_as', 'owner')
        ->set('status', 'active')
        ->call('save')
        ->assertRedirect();

    $created = BusinessClient::query()->where('business_name', 'Audit Co')->firstOrFail();

    expect(AuditEvent::query()->where('action', 'business_client.created')->where('subject_id', $created->id)->exists())
        ->toBeTrue('create must leave an audit trail');

    Livewire::actingAs($this->admin)
        ->test(BusinessClientForm::class, ['businessClient' => $created])
        ->set('business_name', 'Audit Co (renamed)')
        ->call('save')
        ->assertRedirect();

    expect(AuditEvent::query()->where('action', 'business_client.updated')->where('subject_id', $created->id)->exists())
        ->toBeTrue('update must leave an audit trail');
});

it('hides the Add client button from a normal client_user and shows it to a client_admin', function (): void {
    // client_user: no Add CTA, policy denies create.
    $this->actingAs($this->normalUser)
        ->get(route('business-clients.index'))
        ->assertOk()
        ->assertDontSee('+ Add client');

    // client_admin: Add CTA is visible.
    $this->actingAs($this->admin)
        ->get(route('business-clients.index'))
        ->assertOk()
        ->assertSee('+ Add client');
});

it('refuses the create route to read-only staff (finance, auditor)', function (): void {
    $finance = User::factory()->create([
        'client_account_id' => null,
        'is_active' => true,
    ]);
    $finance->assignRole('finance');

    $this->actingAs($finance)
        ->get(route('business-clients.create'))
        ->assertForbidden();

    $auditor = User::factory()->create([
        'client_account_id' => null,
        'is_active' => true,
    ]);
    $auditor->assignRole('auditor');

    $this->actingAs($auditor)
        ->get(route('business-clients.create'))
        ->assertForbidden();
});
