<?php

use App\Actions\SaveApplicationDraft;
use App\Enums\OwnerType;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Livewire\Portal\ApplicationForm;
use App\Models\ClientAccount;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DocumentRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * When a dealer resells a vehicle out of their own stock, the dealer-stock
 * registration document (the RC1 in the dealership's own name) must also
 * be forwarded to the licensing authority. The dealer ticks "Vehicle was
 * dealer stock" on the application form and the checklist adds a
 * requires_original "Dealer stock registration doc" slot.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(DocumentRuleSeeder::class);

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
    $this->user->assignRole('customer_user');
    $this->actingAs($this->user);
});

/**
 * @param  list<string>  $codes
 */
function dealerStockDocsOn($application): array
{
    return $application->refresh()
        ->documents()
        ->where('required', true)
        ->with('documentType')
        ->get()
        ->pluck('documentType.code')
        ->sort()
        ->values()
        ->all();
}

it('seeds the dealer stock reg doc with requires_original = true', function (): void {
    $type = DocumentType::query()->where('code', 'dealer_stock_reg_doc')->firstOrFail();
    expect($type->requires_original)->toBeTrue()
        ->and($type->name)->toBe('Dealer stock registration doc');
});

it('does NOT add the dealer stock reg doc when is_dealer_stock is false', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
        'is_dealer_stock' => false,
    ]);

    expect(dealerStockDocsOn($application))->not->toContain('dealer_stock_reg_doc');
});

it('adds the dealer stock reg doc as a required requires_original slot when is_dealer_stock is true', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
        'is_dealer_stock' => true,
    ]);

    $codes = dealerStockDocsOn($application);
    expect($codes)->toContain('dealer_stock_reg_doc')
        ->and($codes)->toContain('original_natis');

    $dealerStock = $application->documents()
        ->whereHas('documentType', fn ($q) => $q->where('code', 'dealer_stock_reg_doc'))
        ->firstOrFail();

    expect($dealerStock->requiresOriginal())->toBeTrue()
        ->and($dealerStock->isOriginalReceived())->toBeFalse()
        ->and($dealerStock->isOriginalOutstanding())->toBeTrue();
});

it('silently strips is_dealer_stock when the request type is anything other than change of ownership', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::LicenceRenewal->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
        'is_dealer_stock' => true,
    ]);

    expect($application->is_dealer_stock)->toBeFalse()
        ->and(dealerStockDocsOn($application))->not->toContain('dealer_stock_reg_doc');
});

it('removes the dealer stock reg doc when the dealer unticks the flag on an existing draft', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
        'is_dealer_stock' => true,
    ]);

    expect(dealerStockDocsOn($application))->toContain('dealer_stock_reg_doc');

    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
        'is_dealer_stock' => false,
    ], $application);

    expect($application->refresh()->is_dealer_stock)->toBeFalse()
        ->and(dealerStockDocsOn($application))->not->toContain('dealer_stock_reg_doc');
});

it('shows the dealer stock checkbox only when request_type is change of ownership', function (): void {
    $renewalDraft = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    Livewire::test(ApplicationForm::class, ['application' => $renewalDraft])
        ->assertDontSee('Vehicle was dealer stock');

    $cooDraft = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    Livewire::test(ApplicationForm::class, ['application' => $cooDraft])
        ->assertSee('Vehicle was dealer stock');
});

it('clears is_dealer_stock locally when the dealer switches away from change of ownership', function (): void {
    $cooDraft = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'owner_type' => OwnerType::Individual->value,
        'is_dealer_stock' => true,
    ]);

    Livewire::test(ApplicationForm::class, ['application' => $cooDraft])
        ->assertSet('is_dealer_stock', true)
        ->set('request_type', RequestType::LicenceRenewal->value)
        ->assertSet('is_dealer_stock', false);
});
