<?php

use App\Actions\SaveApplicationDraft;
use App\Enums\FuelType;
use App\Enums\MainColour;
use App\Enums\OwnerType;
use App\Enums\ReasonForRegistration;
use App\Enums\RequestType;
use App\Enums\SteeringPosition;
use App\Enums\Transmission;
use App\Enums\VehicleCategory;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->account = ClientAccount::query()->create([
        'name' => 'Dealer A',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->user = User::factory()->create([
        'client_account_id' => $this->account->id,
        'is_active' => true,
    ]);
    $this->user->assignRole('customer_admin');
});

it('persists and casts every new RLV field', function () {
    $application = Application::query()->create([
        'reference' => 'RLV-'.uniqid(),
        'client_account_id' => $this->account->id,
        'stage' => 'draft',
    ]);

    $vehicle = Vehicle::query()->create([
        'application_id' => $application->id,
        'vin' => 'VIN'.uniqid(),
        'make' => 'Isuzu',
        'model' => 'NPR 400 AMT',
        'fuel_type' => FuelType::Diesel->value,
        'transmission' => Transmission::SemiAutomatic->value,
        'main_colour' => MainColour::White->value,
        'net_power_kw' => 110,
        'engine_capacity_cc' => 5193,
        'no_of_wheels' => 6,
        'steering_position' => SteeringPosition::Right->value,
        'reason_for_registration' => ReasonForRegistration::FirstRegistration->value,
        'used_on_public_road' => true,
        'date_liable' => '2026-12-12',
        'address_where_kept' => 'N4 Gateway Industrial Park, Pretoria',
        'natis_model_number' => '4951000BZG5N',
    ]);

    $vehicle->refresh();

    expect($vehicle->fuel_type)->toBe(FuelType::Diesel)
        ->and($vehicle->transmission)->toBe(Transmission::SemiAutomatic)
        ->and($vehicle->main_colour)->toBe(MainColour::White)
        ->and($vehicle->net_power_kw)->toBe(110)
        ->and($vehicle->engine_capacity_cc)->toBe(5193)
        ->and($vehicle->no_of_wheels)->toBe(6)
        ->and($vehicle->steering_position)->toBe(SteeringPosition::Right)
        ->and($vehicle->reason_for_registration)->toBe(ReasonForRegistration::FirstRegistration)
        ->and($vehicle->used_on_public_road)->toBeTrue()
        ->and($vehicle->date_liable->toDateString())->toBe('2026-12-12')
        ->and($vehicle->natis_model_number)->toBe('4951000BZG5N');
});

it('defaults fuel_type to diesel for a brand-new commercial vehicle', function () {
    $saved = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'owner_type' => OwnerType::Business->value,
        'vin' => 'VIN'.uniqid(),
        'make' => 'Isuzu',
    ]);

    expect($saved->vehicle->fuel_type)->toBe(FuelType::Diesel);
});

it('defaults fuel_type to petrol for a brand-new passenger vehicle', function () {
    $saved = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
        'vin' => 'VIN'.uniqid(),
    ]);

    expect($saved->vehicle->fuel_type)->toBe(FuelType::Petrol);
});

it('defaults reason_for_registration to first_registration for a new registration', function () {
    $saved = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'vin' => 'VIN'.uniqid(),
    ]);

    expect($saved->vehicle->reason_for_registration)->toBe(ReasonForRegistration::FirstRegistration);
});

it('defaults reason_for_registration to ownership_change for a change of ownership', function () {
    $saved = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'vin' => 'VIN'.uniqid(),
    ]);

    expect($saved->vehicle->reason_for_registration)->toBe(ReasonForRegistration::OwnershipChange);
});

it('does not overwrite fuel_type on subsequent saves', function () {
    // First save with Commercial defaults diesel.
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'vin' => 'VIN'.uniqid(),
    ]);

    // Reviewer corrects to petrol directly on the vehicle (simulating the
    // manual override step we'll build in the pack-prep workspace).
    $application->vehicle->update(['fuel_type' => FuelType::Petrol->value]);

    // The dealer saves another draft edit (e.g. updates the make). The
    // override must survive.
    app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'make' => 'Isuzu',
    ], $application);

    expect($application->refresh()->vehicle->fuel_type)->toBe(FuelType::Petrol);
});

it('exposes natisVehicleNumber as the human-facing alias for vehicle_register_number', function () {
    $application = Application::query()->create([
        'reference' => 'ALV-'.uniqid(),
        'client_account_id' => $this->account->id,
        'stage' => 'draft',
    ]);

    $vehicle = Vehicle::query()->create([
        'application_id' => $application->id,
        'vehicle_register_number' => 'YHF428W',
    ]);

    expect($vehicle->natisVehicleNumber())->toBe('YHF428W');
});
