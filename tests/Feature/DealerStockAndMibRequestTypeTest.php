<?php

use App\Actions\SaveApplicationDraft;
use App\Enums\OwnerType;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Livewire\Portal\ApplicationForm;
use App\Models\BusinessClient;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\DocumentRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * "Dealer stock" and "MIB" are two lightweight request types a dealership
 * uses day-to-day. Dealer stock moves a vehicle into inventory - either in
 * the dealership's own name, or directly into a fleet customer's name on
 * fleet-claim arrangements where the fleet must be the registered owner
 * from day one. "MIB" is a micro-dot / admin-only transaction against an
 * already-registered vehicle. Both skip the title holder, both skip the
 * individual-owner path, and both default to an empty document checklist.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(DocumentRuleSeeder::class);

    $this->dealer = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'brn' => '1996/001234/07',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->user = User::factory()->create([
        'client_account_id' => $this->dealer->id,
        'is_active' => true,
    ]);
    $this->user->assignRole('customer_user');
    $this->actingAs($this->user);
});

it('exposes Dealer stock and MIB as request type options with the Data fix label', function (): void {
    $labels = collect(RequestType::cases())->mapWithKeys(fn (RequestType $t) => [$t->value => $t->label()]);

    expect($labels)->toHaveKey('dealer_stock')
        ->and($labels)->toHaveKey('mib')
        ->and($labels['dealer_stock'])->toBe('Dealer stock')
        ->and($labels['mib'])->toBe('MIB')
        ->and($labels['data_change'])->toBe('Data fix');
});

it('says Dealer stock never requires a title holder', function (): void {
    expect(RequestType::DealerStock->requiresTitleHolder())->toBeFalse()
        ->and(RequestType::DealerStock->isDealerStock())->toBeTrue();
});

it('says MIB never requires a title holder either', function (): void {
    expect(RequestType::Mib->requiresTitleHolder())->toBeFalse()
        ->and(RequestType::Mib->isDealerStock())->toBeFalse();
});

it('forces owner_type to business and is_dealer_stock to true on a Dealer stock draft', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::DealerStock->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        // Intentionally submitting individual + financed + a stray title
        // holder id - all three MUST be scrubbed because they don't apply.
        'owner_type' => OwnerType::Individual->value,
        'is_financed' => true,
    ]);

    expect($application->request_type)->toBe(RequestType::DealerStock)
        ->and($application->owner_type)->toBe(OwnerType::Business)
        ->and($application->is_dealer_stock)->toBeTrue()
        ->and($application->is_financed)->toBeFalse()
        ->and($application->title_holder_business_client_id)->toBeNull();
});

it('writes the dealership as the owner party when stocking into the dealership itself', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::DealerStock->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'business_client_id' => null,
    ]);

    $owner = $application->refresh()->parties()->where('role', 'owner')->first();

    expect($application->business_client_id)->toBeNull()
        ->and($owner)->not->toBeNull()
        ->and($owner->party_type)->toBe('business')
        ->and($owner->name)->toBe('Highveld Commercial Centurion')
        ->and($owner->identifier)->toBe('1996/001234/07')
        ->and($owner->business_client_id)->toBeNull();
});

it('writes the chosen fleet BusinessClient as the owner party when stocking into a fleet', function (): void {
    $fleet = BusinessClient::query()->create([
        'client_account_id' => $this->dealer->id,
        'business_name' => 'Trans-Africa Logistics',
        'registration_number' => '2019/112233/07',
        'address' => '12 Industrial Rd, Alberton',
        'usable_as' => 'owner',
        'status' => 'active',
    ]);

    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::DealerStock->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'business_client_id' => $fleet->id,
    ]);

    $owner = $application->refresh()->parties()->where('role', 'owner')->first();

    expect($application->business_client_id)->toBe($fleet->id)
        ->and($owner->name)->toBe('Trans-Africa Logistics')
        ->and($owner->identifier)->toBe('2019/112233/07')
        ->and($owner->address)->toBe('12 Industrial Rd, Alberton')
        ->and($owner->business_client_id)->toBe($fleet->id);
});

it('builds an empty document checklist for a Dealer stock draft - no questions, no required docs', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::DealerStock->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
    ]);

    $required = $application->refresh()
        ->documents()
        ->where('required', true)
        ->count();

    expect($required)->toBe(0);
});

it('builds an empty document checklist for an MIB draft', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::Mib->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    $required = $application->refresh()
        ->documents()
        ->where('required', true)
        ->count();

    expect($required)->toBe(0);
});

it('clears is_dealer_stock on the model when the dealer switches away from Dealer stock to a renewal', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::DealerStock->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
    ]);

    expect($application->is_dealer_stock)->toBeTrue();

    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::LicenceRenewal->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
        'is_dealer_stock' => true, // leftover UI tick - must be ignored
    ], $application);

    expect($application->refresh()->is_dealer_stock)->toBeFalse();
});

/**
 * UI guards: on a Dealer stock draft the Owner section must collapse to
 * the "Stock into" picker and the title-holder / "Financed with a title
 * holder" / "Vehicle was dealer stock" controls must disappear. If
 * someone re-introduces any of them, this test will catch it.
 */
it('renders the Stock into picker and hides title holder + is_financed on a Dealer stock draft', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::DealerStock->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
    ]);

    Livewire::test(ApplicationForm::class, ['application' => $application])
        ->assertSee('Stock into')
        ->assertSee('This dealership')
        ->assertSee('+ New fleet')
        ->assertDontSee('Financed, with a title holder')
        ->assertDontSee('Vehicle was dealer stock');
});

it('switches owner_type to business the moment the dealer picks Dealer stock from the Request dropdown', function (): void {
    Livewire::test(ApplicationForm::class)
        ->set('owner_type', OwnerType::Individual->value)
        ->set('request_type', RequestType::DealerStock->value)
        ->assertSet('owner_type', OwnerType::Business->value);
});
