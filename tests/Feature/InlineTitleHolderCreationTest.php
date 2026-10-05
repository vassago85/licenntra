<?php

use App\Actions\SaveApplicationDraft;
use App\Enums\OwnerType;
use App\Enums\Province;
use App\Enums\RequestType;
use App\Enums\ServiceType;
use App\Enums\VehicleCategory;
use App\Models\BusinessClient;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers the "+ New title holder" inline flow on the application form.
 * A dealer should be able to add a title holder (finance house, bank, etc.)
 * from inside the application form without jumping to Business clients
 * first, exactly like the "+ New business client" owner shortcut.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->dealer = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->user = User::factory()->create([
        'client_account_id' => $this->dealer->id,
        'is_active' => true,
    ]);
    $this->user->assignRole('client_user');
});

it('creates a new title holder business client when the inline form is filled in', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'service_type' => ServiceType::RegisterAndLicense->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'owner_type' => OwnerType::Individual->value,
        'province' => Province::Gauteng->value,
        'is_financed' => true,
        'owner_name' => 'Paul Charsley',
        'owner_identifier' => '8507026265088',
        'owner_address' => '525 Gert Potgieter Street',
        'title_holder_business_client_id' => null,
        'new_title_holder_business_name' => 'Alpha Bank Vehicle Finance',
        'new_title_holder_registration_number' => '1962/000738/06',
        'new_title_holder_proxy_name' => 'Nkululeko Zulu',
        'new_title_holder_proxy_contact' => 'nkululeko@alphabank.test',
        'new_title_holder_proxy_id_number' => '7501014000083',
        'new_title_holder_address' => '1 Bank Lane, Johannesburg',
    ]);

    $titleHolder = BusinessClient::query()
        ->where('business_name', 'Alpha Bank Vehicle Finance')
        ->firstOrFail();

    expect($titleHolder->client_account_id)->toBe($this->dealer->id)
        ->and($titleHolder->usable_as)->toBe('title_holder')
        ->and($titleHolder->status)->toBe('active')
        ->and($titleHolder->registration_number)->toBe('1962/000738/06')
        ->and($titleHolder->proxy_name)->toBe('Nkululeko Zulu')
        ->and($titleHolder->proxy_contact)->toBe('nkululeko@alphabank.test')
        ->and($titleHolder->proxy_id_number)->toBe('7501014000083');

    expect($application->title_holder_business_client_id)->toBe($titleHolder->id);
});

it('does not create a new title holder record when the inline form is left blank', function (): void {
    $existing = BusinessClient::query()->create([
        'client_account_id' => $this->dealer->id,
        'business_name' => 'Existing Finance House',
        'usable_as' => 'title_holder',
        'status' => 'active',
    ]);

    app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'service_type' => ServiceType::RegisterAndLicense->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'owner_type' => OwnerType::Individual->value,
        'province' => Province::Gauteng->value,
        'is_financed' => true,
        'owner_name' => 'Paul Charsley',
        'title_holder_business_client_id' => $existing->id,
        'new_title_holder_business_name' => '',
    ]);

    expect(BusinessClient::query()->where('usable_as', 'title_holder')->count())->toBe(1);
});

it('creates the new title holder under the acting dealer\'s own account, not another', function (): void {
    $otherDealer = ClientAccount::query()->create([
        'name' => 'Other Dealer',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    app(SaveApplicationDraft::class)->handle($this->user, [
        'owner_type' => OwnerType::Individual->value,
        'is_financed' => true,
        'owner_name' => 'Paul Charsley',
        'new_title_holder_business_name' => 'Scope Test Bank',
    ]);

    $created = BusinessClient::withoutGlobalScopes()
        ->where('business_name', 'Scope Test Bank')
        ->firstOrFail();

    expect($created->client_account_id)->toBe($this->dealer->id)
        ->and($created->client_account_id)->not->toBe($otherDealer->id);
});
