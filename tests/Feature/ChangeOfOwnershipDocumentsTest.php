<?php

use App\Actions\SaveApplicationDraft;
use App\Enums\OwnerType;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Livewire\Portal\ApplicationForm;
use App\Models\ApplicationDocument;
use App\Models\BusinessClient;
use App\Models\ClientAccount;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DocumentRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Used-vehicle sales come through as change_of_ownership and have their
 * own document checklist:
 *  - CoF for both passenger and commercial vehicles
 *  - ID + proof of address for an individual seller/buyer
 *  - BRN + proxy ID + CoF for a business
 *  - Title holder BRN + proxy ID when financed
 *  - Original NaTIS (RC1) always - dealer uploads a scan to progress
 *    the pack, then confirms once the physical original is collected.
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
function assertHasRequiredDocs($application, array $codes): void
{
    $actual = $application->refresh()
        ->documents()
        ->where('required', true)
        ->with('documentType')
        ->get()
        ->pluck('documentType.code')
        ->sort()
        ->values()
        ->all();

    sort($codes);
    expect($actual)->toBe($codes);
}

it('requires CoF, id copy, poa and original NaTIS for a change of ownership on a passenger vehicle by an individual', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    assertHasRequiredDocs($application, ['cof', 'id_copy', 'poa', 'original_natis']);
});

it('requires CoF, id copy, poa and original NaTIS for a change of ownership on a commercial vehicle by an individual', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    assertHasRequiredDocs($application, ['cof', 'id_copy', 'poa', 'original_natis']);
});

it('requires CoF, BRN, proxy id and original NaTIS for a change of ownership by a business', function (): void {
    $business = BusinessClient::query()->create([
        'client_account_id' => $this->dealer->id,
        'business_name' => 'Highveld Mining',
        'usable_as' => 'owner',
        'status' => 'active',
    ]);

    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Commercial->value,
        'owner_type' => OwnerType::Business->value,
        'business_client_id' => $business->id,
    ]);

    assertHasRequiredDocs($application, ['cof', 'brn_certificate', 'proxy_id', 'original_natis']);
});

it('adds title holder BRN and proxy id when the change of ownership is financed', function (): void {
    $bank = BusinessClient::query()->create([
        'client_account_id' => $this->dealer->id,
        'business_name' => 'Nedbank Vehicle Finance',
        'usable_as' => 'title_holder',
        'is_shared' => true,
        'status' => 'active',
    ]);

    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
        'is_financed' => true,
        'title_holder_business_client_id' => $bank->id,
    ]);

    assertHasRequiredDocs($application, ['cof', 'id_copy', 'poa', 'original_natis', 'title_holder_brn', 'title_holder_proxy_id']);
});

it('marks the original NaTIS slot as requires_original but not received by default', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    $natis = $application->documents()
        ->whereHas('documentType', fn ($q) => $q->where('code', 'original_natis'))
        ->firstOrFail();

    expect($natis->requiresOriginal())->toBeTrue()
        ->and($natis->isOriginalReceived())->toBeFalse()
        ->and($natis->isOriginalOutstanding())->toBeTrue();
});

it('does NOT require an original NaTIS on a licence renewal', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::LicenceRenewal->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    $natis = $application->documents()
        ->whereHas('documentType', fn ($q) => $q->where('code', 'original_natis'))
        ->first();

    // Either no slot exists, or if ResolveRequiredDocuments created one it
    // must at least not be required.
    expect($natis === null || $natis->required === false)->toBeTrue();
});

it('lets the dealer confirm the original was received via the Livewire form', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    $natis = $application->documents()
        ->whereHas('documentType', fn ($q) => $q->where('code', 'original_natis'))
        ->firstOrFail();

    Livewire::test(ApplicationForm::class, ['application' => $application])
        ->call('confirmOriginalReceived', $natis->id);

    $natis->refresh();
    expect($natis->isOriginalReceived())->toBeTrue()
        ->and($natis->isOriginalOutstanding())->toBeFalse()
        ->and($natis->original_received_at)->not->toBeNull();
});

it('lets the dealer undo an accidental confirmation', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    $natis = $application->documents()
        ->whereHas('documentType', fn ($q) => $q->where('code', 'original_natis'))
        ->firstOrFail();

    $natis->forceFill(['original_received_at' => now()])->save();

    Livewire::test(ApplicationForm::class, ['application' => $application])
        ->call('undoOriginalReceived', $natis->id);

    expect($natis->refresh()->original_received_at)->toBeNull();
});

it('shows the "awaiting original" warning on the dealer form when original is outstanding', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    Livewire::test(ApplicationForm::class, ['application' => $application])
        ->assertSee('Original must be submitted in person')
        ->assertSee('Confirm original received');
});

it('shows the "original on hand" confirmation on the dealer form once confirmed', function (): void {
    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::ChangeOfOwnership->value,
        'vehicle_category' => VehicleCategory::Passenger->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    ApplicationDocument::query()
        ->whereHas('documentType', fn ($q) => $q->where('code', 'original_natis'))
        ->update(['original_received_at' => now()]);

    Livewire::test(ApplicationForm::class, ['application' => $application->refresh()])
        ->assertSee('Original on hand')
        ->assertDontSee('Original must be submitted in person');
});

it('seeds the original_natis doctype with requires_original = true', function (): void {
    $natis = DocumentType::query()->where('code', 'original_natis')->firstOrFail();
    expect($natis->requires_original)->toBeTrue()
        ->and($natis->name)->toBe('Original NaTIS (RC1)');
});
