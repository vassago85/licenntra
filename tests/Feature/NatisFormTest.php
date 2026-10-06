<?php

use App\Enums\ApplicationStage;
use App\Enums\DatafixStatus;
use App\Enums\LicenceFeeCategory;
use App\Enums\NatisFormType;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Livewire\Portal\DealershipParticulars;
use App\Livewire\Portal\NatisFormEditor;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\BusinessClient;
use App\Models\ClientAccount;
use App\Models\Party;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\NatisFormBuilder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->builder = app(NatisFormBuilder::class);

    $this->dealership = ClientAccount::query()->create([
        'name' => 'Highveld Commercial (Pty) Ltd',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
        'contact_email' => 'stock@highveld.test',
        'contact_phone' => '012 345 6789',
        'brn' => '2015/123456/07',
        'street_address' => '45 Lynnwood Road, Lynnwood, Pretoria, 0081',
        'proxy_name' => 'Botha',
        'proxy_initials' => 'J',
        'proxy_id_type' => 'rsa_id',
        'proxy_id_number' => '7802155012083',
    ]);

    $this->operations = User::factory()->create(['is_active' => true]);
    $this->operations->assignRole('reviewer');

    $this->dealerAdmin = User::factory()->create(['is_active' => true, 'client_account_id' => $this->dealership->id]);
    $this->dealerAdmin->assignRole('customer_admin');
});

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $vehicle
 */
function natisApplication(ClientAccount $account, array $attributes = [], array $vehicle = []): Application
{
    $application = Application::query()->create(array_merge([
        'reference' => 'NATIS-'.uniqid(),
        'client_account_id' => $account->id,
        'stage' => ApplicationStage::PaymentVerified,
        'request_type' => RequestType::NewRegistration,
        'vehicle_category' => VehicleCategory::Commercial,
        'licence_category' => LicenceFeeCategory::GoodsVehicle,
        'owner_type' => 'individual',
        'province' => 'gauteng',
        'datafix_status' => DatafixStatus::NotRequired,
    ], $attributes));

    Vehicle::query()->create(array_merge([
        'application_id' => $application->id,
        'vin' => 'AHTFR22G406012345',
        'vehicle_register_number' => 'XYZ123B',
        'engine_number' => '1GD1234567',
        'make' => 'Toyota',
        'model' => 'Hilux 2.8 GD-6 Raider',
        'tare_kg' => 2100,
        'gvm_kg' => 3210,
    ], $vehicle));

    return $application->refresh();
}

function individualOwner(Application $application, string $name = 'Thandi Grace Mokoena', string $identifier = '8501010800087', string $address = '12 Main Road, Waterkloof, Pretoria, 0181'): Party
{
    return Party::query()->create([
        'application_id' => $application->id,
        'role' => 'owner',
        'party_type' => 'individual',
        'name' => $name,
        'identifier' => $identifier,
        'address' => $address,
    ]);
}

function financeHouse(ClientAccount $account): BusinessClient
{
    return BusinessClient::query()->create([
        'client_account_id' => $account->id,
        'business_name' => 'Wesbank Ltd',
        'registration_number' => '1929/001225/06',
        'proxy_name' => 'Sipho Dlamini',
        'proxy_contact' => '011 632 6000',
        'proxy_id_number' => '8003035123089',
        'address' => '1 First Place, Bank City, Johannesburg 2001',
        'usable_as' => 'title_holder',
        'is_shared' => true,
    ]);
}

it('fills a financed new-registration RLV with the bank in Part A and the buyer in Part B', function (): void {
    $bank = financeHouse($this->dealership);
    $application = natisApplication($this->dealership, [
        'is_financed' => true,
        'title_holder_business_client_id' => $bank->id,
    ]);
    individualOwner($application);

    $values = $this->builder->derive($application->refresh());

    expect($this->builder->formTypeFor($application))->toBe(NatisFormType::Rlv)
        ->and($values['transaction'])->toBe(['type' => 'registration', 'owner_is_title_holder' => false])
        ->and($values['title_holder']['surname'])->toBe('Wesbank Ltd')
        ->and($values['title_holder']['id_type'])->toBe('business_reg')
        ->and($values['title_holder']['id_number'])->toBe('1929/001225/06')
        ->and($values['title_holder']['nature'])->toBe('private_company')
        ->and($values['title_holder']['street_city'])->toBe('Johannesburg')
        ->and($values['title_holder']['street_code'])->toBe('2001')
        ->and($values['title_holder']['phone_day'])->toBe('011 632 6000')
        ->and($values['title_holder_proxy'])->toMatchArray(['surname' => 'Dlamini', 'initials' => 'S', 'id_type' => 'rsa_id', 'id_number' => '8003035123089'])
        ->and($values['title_holder_declaration']['declarant'])->toBe('motor_dealer')
        ->and($values['owner'])->toMatchArray([
            'surname' => 'Mokoena',
            'first_names' => 'Thandi Grace',
            'initials' => 'TG',
            'id_type' => 'rsa_id',
            'date_of_birth' => '1985-01-01',
            'nature' => 'female',
            'street_address' => '12 Main Road',
            'street_suburb' => 'Waterkloof',
            'street_city' => 'Pretoria',
            'street_code' => '0181',
            'notices_to' => 'street',
        ])
        ->and($values['owner_declaration']['declarant'])->toBe('owner');
});

it('fills the RLV vehicle part from the vehicle with NaTIS defaults', function (): void {
    $application = natisApplication($this->dealership);
    individualOwner($application);

    $vehicle = $this->builder->derive($application->refresh())['vehicle'];

    expect($vehicle)->toMatchArray([
        'licence_not_allocated' => true,
        'register_number' => 'XYZ123B',
        'vin' => 'AHTFR22G406012345',
        'make' => 'Toyota',
        'series_name' => 'Hilux 2.8 GD-6 Raider',
        'category' => 'K',
        'driven' => 'self_propelled',
        'fuel' => 'diesel',
        'tare_kg' => '2100',
        'gvm_kg' => '3210',
        'steering' => 'right',
        'public_road' => 'yes',
        'nature_of_ownership' => 'private',
        'reason' => 'first_registration',
        'sector' => 'private',
    ]);
});

it('fills a cash dealer-stock RLV from the dealership particulars and leaves Part B blank', function (): void {
    $application = natisApplication($this->dealership, [
        'request_type' => RequestType::DealerStock,
        'is_dealer_stock' => true,
        'owner_type' => 'business',
    ], ['gvm_kg' => 8500]);

    $values = $this->builder->derive($application);

    expect($values['transaction']['owner_is_title_holder'])->toBeTrue()
        ->and($values['title_holder'])->toMatchArray([
            'surname' => 'Highveld Commercial (Pty) Ltd',
            'id_type' => 'business_reg',
            'id_number' => '2015/123456/07',
            'email' => 'stock@highveld.test',
            'street_address' => '45 Lynnwood Road',
            'street_suburb' => 'Lynnwood',
            'street_city' => 'Pretoria',
            'street_code' => '0081',
        ])
        ->and($values['title_holder_proxy'])->toMatchArray(['surname' => 'Botha', 'initials' => 'J', 'id_type' => 'rsa_id', 'id_number' => '7802155012083'])
        ->and($values['vehicle']['category'])->toBe('L')
        ->and($values['vehicle']['nature_of_ownership'])->toBe('md_stock');

    $printed = $this->builder->printableValues(NatisFormType::Rlv, array_replace_recursive($values, ['owner' => ['surname' => 'Should not print']]));

    expect($printed['owner']['surname'])->toBe('')
        ->and($printed['title_holder']['surname'])->toBe('Highveld Commercial (Pty) Ltd');
});

it('fills an ALV for a licence renewal', function (): void {
    $application = natisApplication($this->dealership, ['request_type' => RequestType::LicenceRenewal]);
    individualOwner($application);

    $values = $this->builder->derive($application->refresh());

    expect($this->builder->formTypeFor($application))->toBe(NatisFormType::Alv)
        ->and(array_keys($values))->toBe(['owner', 'owner_proxy', 'owner_representative', 'vehicle', 'declaration'])
        ->and($values['owner']['surname'])->toBe('Mokoena')
        ->and($values['owner'])->not->toHaveKey('date_of_birth')
        ->and($values['vehicle'])->toMatchArray(['vin' => 'AHTFR22G406012345', 'steering' => 'right', 'licence_number' => ''])
        ->and($values['declaration']['declarant'])->toBe('owner')
        ->and($this->builder->missingEssentials(NatisFormType::Alv, $values))->toBe(['vehicle.licence_number' => 'Vehicle: licence number']);
});

it('has no form for request types the department does not take on an ALV or RLV', function (): void {
    $application = natisApplication($this->dealership, ['request_type' => RequestType::Deregistration]);

    expect($this->builder->formTypeFor($application))->toBeNull()
        ->and($this->builder->printable($application))->toBeNull();

    $this->actingAs($this->operations)
        ->get(route('review.natis-form', $application))
        ->assertNotFound();
});

it('lets operations correct the form, saves it as checked and prints the saved values', function (): void {
    $application = natisApplication($this->dealership);
    individualOwner($application);

    $this->actingAs($this->operations);

    Livewire::test(NatisFormEditor::class, ['application' => $application])
        ->assertSee('Check the RLV(5)')
        ->assertSee('Not checked yet')
        ->set('values.vehicle.colour', 'other')
        ->set('values.vehicle.colour_other', 'Silver')
        ->set('values.vehicle.engine_number', '1GD7654321')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('RLV checked and saved');

    $application->refresh();

    expect($application->natis_form_checked_by_id)->toBe($this->operations->id)
        ->and($application->natis_form_checked_at)->not->toBeNull()
        ->and($this->builder->isChecked($application))->toBeTrue()
        ->and($this->builder->isOutOfDate($application))->toBeFalse()
        ->and($this->builder->values($application)['vehicle']['colour_other'])->toBe('Silver')
        ->and($this->builder->values($application)['vehicle']['engine_number'])->toBe('1GD7654321')
        ->and(AuditEvent::query()->where('action', 'natis_form.checked')->count())->toBe(1);

    $audit = AuditEvent::query()->where('action', 'natis_form.checked')->firstOrFail();
    $storedForm = DB::table('applications')->where('id', $application->id)->value('natis_form');

    expect($audit->after['changed_fields'])->toBe(['vehicle.engine_number', 'vehicle.colour', 'vehicle.colour_other'])
        ->and(json_encode($audit->after))->not->toContain('8501010800087')
        ->and($storedForm)->not->toContain('8501010800087')
        ->and($storedForm)->not->toContain('Silver');

    $this->get(route('review.natis-form.print', $application))
        ->assertOk()
        ->assertSee('RLV(5)')
        ->assertSee('Silver')
        ->assertSee('1GD7654321')
        ->assertDontSee('Operations have not checked this');

    expect(AuditEvent::query()->where('action', 'natis_form.printed')->count())->toBe(1);
});

it('flags a checked form once the application data changes, and refills on request', function (): void {
    $application = natisApplication($this->dealership);
    individualOwner($application);
    $this->actingAs($this->operations);

    Livewire::test(NatisFormEditor::class, ['application' => $application])
        ->set('values.vehicle.make', 'Toyota SA')
        ->call('save');

    $application->vehicle->update(['vin' => 'NEWVIN00000000001']);
    $application = Application::query()->findOrFail($application->id);

    expect($this->builder->isOutOfDate($application))->toBeTrue()
        ->and($this->builder->values($application)['vehicle']['vin'])->toBe('AHTFR22G406012345');

    Livewire::test(NatisFormEditor::class, ['application' => $application])
        ->assertSee('the application has changed since')
        ->call('refill')
        ->assertSet('values.vehicle.vin', 'NEWVIN00000000001')
        ->assertSet('values.vehicle.make', 'Toyota');
});

it('rejects values that are not on the form', function (): void {
    $application = natisApplication($this->dealership);
    $this->actingAs($this->operations);

    Livewire::test(NatisFormEditor::class, ['application' => $application])
        ->set('values.vehicle.category', 'Z')
        ->set('values.title_holder.date_of_birth', '01/02/1990')
        ->call('save')
        ->assertHasErrors(['values.vehicle.category', 'values.title_holder.date_of_birth']);

    expect($application->refresh()->natis_form)->toBeNull();
});

it('keeps the form check away from dealership users', function (): void {
    $application = natisApplication($this->dealership);

    $this->actingAs($this->dealerAdmin)
        ->get(route('review.natis-form', $application))
        ->assertForbidden();

    $this->actingAs($this->dealerAdmin)
        ->get(route('review.natis-form.print', $application))
        ->assertForbidden();
});

it('prints the form inside the submission pack behind the cover sheet', function (): void {
    $application = natisApplication($this->dealership);
    individualOwner($application);
    $application->submissionPacks()->create([
        'prepared_by_id' => $this->operations->id,
        'manifest' => ['documents' => []],
    ]);

    $this->actingAs($this->operations)
        ->get(route('review.packs.print', ['ids' => $application->id]))
        ->assertOk()
        ->assertSee('RLV(5): not checked by operations yet')
        ->assertSee('Application for registration and licensing of motor vehicle')
        ->assertSee('Mokoena')
        ->assertSee('AHTFR22G406012345');
});

it('lets the dealership admin capture its BRN, address, proxy and representative', function (): void {
    $account = ClientAccount::query()->create([
        'name' => 'Lowveld Motors CC',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);
    $admin = User::factory()->create(['is_active' => true, 'client_account_id' => $account->id]);
    $admin->assignRole('customer_admin');

    $this->actingAs($admin);

    Livewire::test(DealershipParticulars::class)
        ->assertSee('not complete yet')
        ->call('save')
        ->assertHasErrors(['brn', 'street_address', 'proxy_name', 'proxy_initials', 'proxy_id_type', 'proxy_id_number'])
        ->set('brn', '2010/555555/23')
        ->set('street_address', '3 Ferreira Street, Nelspruit, 1200')
        ->set('proxy_name', 'Nkosi')
        ->set('proxy_initials', 'MB')
        ->set('proxy_id_type', 'foreign_id')
        ->set('proxy_id_number', 'ZW1234567')
        ->call('save')
        ->assertHasErrors(['proxy_id_country'])
        ->set('proxy_id_country', 'Zimbabwe')
        ->set('representative_name', 'Smit')
        ->call('save')
        ->assertHasErrors(['representative_initials', 'representative_id_type', 'representative_id_number'])
        ->set('representative_name', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Dealership details saved');

    $account->refresh();

    expect($account->hasDealershipParticulars())->toBeTrue()
        ->and($account->proxy_id_country)->toBe('Zimbabwe')
        ->and($account->street_address)->toBe('3 Ferreira Street, Nelspruit, 1200')
        ->and($account->representative_name)->toBeNull();
});

it('keeps dealership details to the dealership admin', function (): void {
    $user = User::factory()->create(['is_active' => true, 'client_account_id' => $this->dealership->id]);
    $user->assignRole('customer_user');

    $this->actingAs($user)->get(route('dealership.particulars'))->assertForbidden();
    $this->actingAs($this->operations)->get(route('dealership.particulars'))->assertForbidden();
    $this->actingAs($this->dealerAdmin)->get(route('dealership.particulars'))->assertOk()->assertSee('Dealership details');
});
