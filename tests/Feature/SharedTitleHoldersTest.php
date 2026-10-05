<?php

use App\Actions\SaveApplicationDraft;
use App\Actions\SaveBusinessClient;
use App\Enums\OwnerType;
use App\Models\BusinessClient;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Covers the "shared title holder" feature:
 * Banks and finance houses are typically used by every dealership on the
 * platform, so a BusinessClient with usable_as in (title_holder, both)
 * and is_shared=true is visible/editable across dealerships.
 *
 * Owner records always stay dealership-private, even if the UI tries to
 * flip the flag.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->dealerA = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->dealerB = ClientAccount::query()->create([
        'name' => 'Lowveld Vehicle Group',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->userA = User::factory()->create([
        'client_account_id' => $this->dealerA->id,
        'is_active' => true,
    ]);
    $this->userA->assignRole('client_user');

    $this->userB = User::factory()->create([
        'client_account_id' => $this->dealerB->id,
        'is_active' => true,
    ]);
    $this->userB->assignRole('client_user');
});

it('lets dealership B see a shared title holder that dealership A created', function (): void {
    auth()->login($this->userA);
    app(SaveBusinessClient::class)->handle($this->userA, [
        'business_name' => 'Southern Cross Bank Vehicle Finance',
        'usable_as' => 'title_holder',
        'is_shared' => true,
        'status' => 'active',
    ]);
    auth()->logout();

    // Dealership B should see the record in their own query.
    auth()->login($this->userB);
    $visible = BusinessClient::query()->where('business_name', 'Southern Cross Bank Vehicle Finance')->first();

    expect($visible)->not->toBeNull('dealership B must see the shared bank record');
    expect($visible->isShared())->toBeTrue();
    expect($visible->client_account_id)->toBe($this->dealerA->id, 'originator stays as dealership A for provenance');
});

it('hides a non-shared title holder from other dealerships', function (): void {
    auth()->login($this->userA);
    app(SaveBusinessClient::class)->handle($this->userA, [
        'business_name' => 'A Private Finance Deal',
        'usable_as' => 'title_holder',
        'is_shared' => false,
        'status' => 'active',
    ]);
    auth()->logout();

    auth()->login($this->userB);
    $visible = BusinessClient::query()->where('business_name', 'A Private Finance Deal')->first();

    expect($visible)->toBeNull('dealership B must NOT see a private title holder created by A');
});

it('refuses to mark an owner record as shared even if the UI sends is_shared=true', function (): void {
    auth()->login($this->userA);
    $client = app(SaveBusinessClient::class)->handle($this->userA, [
        'business_name' => 'Ridgeline Haulage',
        'usable_as' => 'owner',
        'is_shared' => true,
        'status' => 'active',
    ]);
    auth()->logout();

    // Owner record: is_shared is forcibly false regardless of what UI sent.
    expect($client->is_shared)->toBeFalse('owner records are POPIA-sensitive customer data and must stay private');
    expect($client->isShared())->toBeFalse();

    // Confirm dealership B cannot see the owner record.
    auth()->login($this->userB);
    expect(BusinessClient::query()->where('business_name', 'Ridgeline Haulage')->exists())->toBeFalse();
});

it('lets dealership B edit a shared title holder that dealership A created, preserving the originator', function (): void {
    auth()->login($this->userA);
    $original = app(SaveBusinessClient::class)->handle($this->userA, [
        'business_name' => 'Nedbank Vehicle Finance',
        'usable_as' => 'title_holder',
        'is_shared' => true,
        'status' => 'active',
    ]);
    auth()->logout();

    auth()->login($this->userB);
    $visibleToB = BusinessClient::query()->find($original->id);
    $updated = app(SaveBusinessClient::class)->handle($this->userB, [
        'business_name' => 'Nedbank Vehicle & Asset Finance',
        'proxy_contact' => 'vaf@nedbank.co.za',
        'usable_as' => 'title_holder',
        'is_shared' => true,
        'status' => 'active',
    ], $visibleToB);

    expect($updated->business_name)->toBe('Nedbank Vehicle & Asset Finance')
        ->and($updated->proxy_contact)->toBe('vaf@nedbank.co.za')
        ->and($updated->client_account_id)->toBe($this->dealerA->id, 'provenance (originating dealership) must survive cross-dealership edits');
});

it('still refuses to edit a non-shared record on another dealership', function (): void {
    auth()->login($this->userA);
    $privateRecord = app(SaveBusinessClient::class)->handle($this->userA, [
        'business_name' => 'Dealer A Private Client',
        'usable_as' => 'owner',
        'status' => 'active',
    ]);
    auth()->logout();

    auth()->login($this->userB);

    expect(fn () => app(SaveBusinessClient::class)->handle($this->userB, [
        'business_name' => 'Hacked',
        'usable_as' => 'owner',
        'status' => 'active',
    ], $privateRecord))->toThrow(ValidationException::class);
});

it('shares a new title holder created inline from the application form by default', function (): void {
    app(SaveApplicationDraft::class)->handle($this->userA, [
        'owner_type' => OwnerType::Individual->value,
        'is_financed' => true,
        'owner_name' => 'Jane Dealer',
        'new_title_holder_business_name' => 'Starbank Vehicle Finance',
    ]);

    auth()->logout();
    auth()->login($this->userB);

    $visible = BusinessClient::query()->where('business_name', 'Starbank Vehicle Finance')->first();
    expect($visible)->not->toBeNull('inline-created title holders are shared by default');
    expect($visible->isShared())->toBeTrue();
});
