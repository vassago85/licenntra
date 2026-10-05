<?php

use App\Actions\SaveApplicationDraft;
use App\Enums\OwnerType;
use App\Enums\RequestType;
use App\Livewire\Portal\ApplicationForm;
use App\Models\Application;
use App\Models\BusinessClient;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Covers the "title holder is only for registration" UX rule:
 * eNaTIS already stores the current title holder for every registered
 * vehicle, so a disc renewal (or duplicate/deregistration/...) must not
 * re-prompt the dealer for one. The RequestType enum knows which types
 * need a title holder; this test proves both the Livewire form and the
 * SaveApplicationDraft action honour that.
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
    $this->user->assignRole('customer_user');
});

/**
 * Build a persisted draft Application for the given request type so that
 * Livewire tests can mount the ApplicationForm with an existing row.
 * Mounting with a fresh (null) application creates one on first `set()`
 * and triggers redirectRoute(), which gets in the way of asserting
 * against the current render.
 */
function makeDraftFor(RequestType $type)
{
    return app(SaveApplicationDraft::class)->handle(test()->user, [
        'request_type' => $type->value,
        'owner_type' => OwnerType::Individual->value,
    ]);
}

it('shows the title holder prompt for a new registration', function (): void {
    $this->actingAs($this->user);
    $draft = makeDraftFor(RequestType::NewRegistration);

    Livewire::test(ApplicationForm::class, ['application' => $draft])
        ->assertSee('Financed, with a title holder');
});

it('shows the title holder prompt for a change of ownership', function (): void {
    $this->actingAs($this->user);
    $draft = makeDraftFor(RequestType::ChangeOfOwnership);

    Livewire::test(ApplicationForm::class, ['application' => $draft])
        ->assertSee('Financed, with a title holder');
});

it('hides the title holder prompt for a licence renewal', function (): void {
    $this->actingAs($this->user);
    $draft = makeDraftFor(RequestType::LicenceRenewal);

    Livewire::test(ApplicationForm::class, ['application' => $draft])
        ->assertDontSee('Financed, with a title holder')
        ->assertSee('eNaTIS already has it');
});

it('hides the title holder prompt for a duplicate disc', function (): void {
    $this->actingAs($this->user);
    $draft = makeDraftFor(RequestType::DuplicateDisc);

    Livewire::test(ApplicationForm::class, ['application' => $draft])
        ->assertDontSee('Financed, with a title holder');
});

it('clears a stale title holder selection when the dealer switches from registration to renewal', function (): void {
    $this->actingAs($this->user);

    $bank = BusinessClient::query()->create([
        'client_account_id' => $this->dealer->id,
        'business_name' => 'Nedbank Vehicle Finance',
        'usable_as' => 'title_holder',
        'is_shared' => true,
        'status' => 'active',
    ]);

    $draft = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'owner_type' => OwnerType::Individual->value,
        'is_financed' => true,
        'title_holder_business_client_id' => $bank->id,
    ]);

    Livewire::test(ApplicationForm::class, ['application' => $draft])
        ->assertSet('is_financed', true)
        ->assertSet('title_holder_business_client_id', (string) $bank->id)
        ->set('request_type', RequestType::LicenceRenewal->value)
        ->assertSet('is_financed', false)
        ->assertSet('title_holder_business_client_id', '');
});

it('strips is_financed and title holder from the saved application when the request type is a renewal', function (): void {
    $this->actingAs($this->user);

    $bank = BusinessClient::query()->create([
        'client_account_id' => $this->dealer->id,
        'business_name' => 'Nedbank Vehicle Finance',
        'usable_as' => 'title_holder',
        'is_shared' => true,
        'status' => 'active',
    ]);

    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
        'is_financed' => true,
        'title_holder_business_client_id' => $bank->id,
    ]);

    expect($application->is_financed)->toBeFalse('renewals must not carry a financed flag through to the row')
        ->and($application->title_holder_business_client_id)->toBeNull('renewals must not carry a title holder reference');
});

it('refuses to create a new inline title holder when the request type is a renewal', function (): void {
    $this->actingAs($this->user);

    $before = BusinessClient::query()->count();

    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
        'is_financed' => true,
        'new_title_holder_business_name' => 'Phantom Bank That Should Not Exist',
    ]);

    expect(BusinessClient::query()->count())->toBe($before, 'inline-new title holder creation must be a no-op on a renewal')
        ->and($application->title_holder_business_client_id)->toBeNull();
    expect(BusinessClient::query()->where('business_name', 'Phantom Bank That Should Not Exist')->exists())->toBeFalse();
});

it('still accepts a title holder for a new registration', function (): void {
    $this->actingAs($this->user);

    $application = app(SaveApplicationDraft::class)->handle($this->user, [
        'request_type' => RequestType::NewRegistration->value,
        'owner_type' => OwnerType::Individual->value,
        'is_financed' => true,
        'new_title_holder_business_name' => 'Starbank Vehicle Finance',
    ]);

    expect($application->is_financed)->toBeTrue()
        ->and($application->title_holder_business_client_id)->not->toBeNull();
    expect(Application::find($application->id)->titleHolder->business_name)->toBe('Starbank Vehicle Finance');
});
