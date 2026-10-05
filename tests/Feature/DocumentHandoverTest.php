<?php

use App\Actions\ConfirmDocumentHandover;
use App\Actions\SaveApplicationDraft;
use App\Actions\SaveDocumentHandover;
use App\Enums\HandoverDirection;
use App\Enums\HandoverStatus;
use App\Enums\OwnerType;
use App\Enums\RequestType;
use App\Livewire\Portal\DocumentHandoverForm;
use App\Livewire\Portal\DocumentHandoverIndex;
use App\Models\ClientAccount;
use App\Models\DocumentHandover;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Batched POD / POC flow: a licensing-authority representative shows up
 * at the dealership and drops off a stack of discs + NaTIS certificates
 * (delivery) or collects a stack of lodged paperwork (collection). The
 * dealer records one hand-over per visit, prints a POD/POC for the
 * physical paper trail, and confirms the hand-over digitally once both
 * sides have signed.
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

/**
 * Build a quick draft application the dealer can bundle onto a hand-over.
 */
function dealerDraftOn(ClientAccount $account, User $user)
{
    auth()->login($user);
    $application = app(SaveApplicationDraft::class)->handle($user, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);
    auth()->logout();

    return $application;
}

it('creates a pending collection hand-over covering several applications on the same dealership', function (): void {
    auth()->login($this->userA);
    $app1 = app(SaveApplicationDraft::class)->handle($this->userA, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);
    $app2 = app(SaveApplicationDraft::class)->handle($this->userA, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    $handover = app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Collection->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'counterparty_identifier' => 'EMP-99812',
        'counterparty_company' => 'Gauteng Licensing Services',
        'dealer_person_name' => $this->userA->name,
        'items_summary' => "2 x stamped RC1\n2 x proof of payment",
        'application_ids' => [$app1->id, $app2->id],
        'line_items' => [
            $app1->id => 'Original NaTIS + stamped RC1',
            $app2->id => 'Stamped RC1 only',
        ],
    ]);

    expect($handover->client_account_id)->toBe($this->dealerA->id)
        ->and($handover->direction)->toBe(HandoverDirection::Collection)
        ->and($handover->status)->toBe(HandoverStatus::Pending)
        ->and($handover->applications)->toHaveCount(2)
        ->and($handover->applications->first()->pivot->item_description)->toBe('Original NaTIS + stamped RC1');
});

it('refuses to attach another dealership\'s applications to a hand-over', function (): void {
    $myApp = dealerDraftOn($this->dealerA, $this->userA);
    $otherApp = dealerDraftOn($this->dealerB, $this->userB);

    auth()->login($this->userA);

    expect(fn () => app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'dealer_person_name' => $this->userA->name,
        'application_ids' => [$myApp->id, $otherApp->id],
    ]))->toThrow(ValidationException::class);
});

it('confirms a pending hand-over and locks it from further edits', function (): void {
    auth()->login($this->userA);
    $app = app(SaveApplicationDraft::class)->handle($this->userA, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    $handover = app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'dealer_person_name' => $this->userA->name,
        'application_ids' => [$app->id],
    ]);

    $confirmed = app(ConfirmDocumentHandover::class)->handle($this->userA, $handover);

    expect($confirmed->status)->toBe(HandoverStatus::Completed)
        ->and($confirmed->confirmed_at)->not->toBeNull()
        ->and($confirmed->confirmed_by_id)->toBe($this->userA->id);

    // Second save on a completed hand-over is rejected.
    expect(fn () => app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'dealer_person_name' => $this->userA->name,
        'application_ids' => [$app->id],
    ], $confirmed))->toThrow(ValidationException::class);
});

it('refuses to confirm a hand-over that has no applications attached', function (): void {
    auth()->login($this->userA);

    $handover = app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'dealer_person_name' => $this->userA->name,
        'application_ids' => [],
    ]);

    expect(fn () => app(ConfirmDocumentHandover::class)->handle($this->userA, $handover))
        ->toThrow(ValidationException::class);
});

it('refuses to confirm a hand-over without counterparty and dealership names', function (): void {
    auth()->login($this->userA);
    $app = app(SaveApplicationDraft::class)->handle($this->userA, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);

    $handover = app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => '',
        'dealer_person_name' => $this->userA->name,
        'application_ids' => [$app->id],
    ]);

    expect(fn () => app(ConfirmDocumentHandover::class)->handle($this->userA, $handover))
        ->toThrow(ValidationException::class);
});

it('scopes hand-overs so dealership B cannot see dealership A\'s records', function (): void {
    auth()->login($this->userA);
    $app = app(SaveApplicationDraft::class)->handle($this->userA, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);
    app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'dealer_person_name' => $this->userA->name,
        'application_ids' => [$app->id],
    ]);
    auth()->logout();

    auth()->login($this->userB);
    expect(DocumentHandover::query()->count())->toBe(0);
    expect(DocumentHandover::query()->withoutGlobalScope('dealer_account')->count())->toBe(1);
});

it('refuses the policy for a user on another dealership', function (): void {
    auth()->login($this->userA);
    $app = app(SaveApplicationDraft::class)->handle($this->userA, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);
    $handover = app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'dealer_person_name' => $this->userA->name,
        'application_ids' => [$app->id],
    ]);
    auth()->logout();

    expect($this->userB->can('view', $handover))->toBeFalse();
    expect($this->userA->can('view', $handover))->toBeTrue();
});

it('renders the printable POD view with representative, dealership, and item details', function (): void {
    auth()->login($this->userA);
    $app = app(SaveApplicationDraft::class)->handle($this->userA, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);
    $handover = app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'counterparty_company' => 'Gauteng Licensing Services',
        'dealer_person_name' => 'Paul Charsley',
        'items_summary' => '1 x licence disc, 1 x NaTIS',
        'application_ids' => [$app->id],
        'line_items' => [$app->id => 'Licence disc + stamped NaTIS'],
    ]);

    $this->actingAs($this->userA)
        ->get(route('handovers.print', $handover))
        ->assertOk()
        ->assertSee('Proof of delivery (POD)')
        ->assertSee('Gauteng Licensing Services')
        ->assertSee('Thandi Mahlangu')
        ->assertSee('Paul Charsley')
        ->assertSee('Licence disc + stamped NaTIS')
        ->assertSee($app->reference);
});

it('refuses the printable view for a user on another dealership', function (): void {
    auth()->login($this->userA);
    $app = app(SaveApplicationDraft::class)->handle($this->userA, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);
    $handover = app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'dealer_person_name' => $this->userA->name,
        'application_ids' => [$app->id],
    ]);
    auth()->logout();

    // The dealer_account global scope filters the handover out of model
    // binding before the policy even runs, so dealer B sees a 404 rather
    // than a 403 - which is the stronger posture because it doesn't leak
    // that the record exists.
    $this->actingAs($this->userB)
        ->get(route('handovers.print', $handover))
        ->assertNotFound();
});

it('the Livewire index lists the dealer\'s own hand-overs with status badges', function (): void {
    auth()->login($this->userA);
    $app = app(SaveApplicationDraft::class)->handle($this->userA, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);
    app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'dealer_person_name' => $this->userA->name,
        'items_summary' => 'List preview text that must show up',
        'application_ids' => [$app->id],
    ]);

    Livewire::test(DocumentHandoverIndex::class)
        ->assertSee('Delivery')
        ->assertSee('Pending')
        ->assertSee('List preview text that must show up');
});

it('the Livewire form confirms a hand-over end-to-end', function (): void {
    auth()->login($this->userA);
    $app = app(SaveApplicationDraft::class)->handle($this->userA, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);
    $handover = app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'dealer_person_name' => $this->userA->name,
        'application_ids' => [$app->id],
    ]);

    Livewire::test(DocumentHandoverForm::class, ['handover' => $handover])
        ->call('confirm')
        ->assertHasNoErrors();

    expect($handover->refresh()->isCompleted())->toBeTrue();
});

/**
 * The paper POD/POC is explicitly a nice-to-have. A hand-over confirmed
 * digitally - with nothing uploaded afterwards - is a complete record of
 * truth. This test locks that contract in so nobody re-introduces a
 * "must attach signed scan" requirement later.
 */
it('a digitally-confirmed hand-over is complete without any signed paper scan', function (): void {
    auth()->login($this->userA);
    $app = app(SaveApplicationDraft::class)->handle($this->userA, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);
    $handover = app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Collection->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'dealer_person_name' => $this->userA->name,
        'application_ids' => [$app->id],
    ]);

    Livewire::test(DocumentHandoverForm::class, ['handover' => $handover])
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSee('Hand-over confirmed digitally')
        ->assertSee('optional');

    $fresh = $handover->refresh();

    expect($fresh->isCompleted())->toBeTrue()
        ->and($fresh->confirmed_at)->not->toBeNull()
        ->and($fresh->signed_file_path)->toBeNull()
        ->and($fresh->signed_file_uploaded_at)->toBeNull();
});

/**
 * UI copy guard: the form must present printing and the signed scan as
 * optional - not as a required next step. If somebody rewords it back to
 * "you must print and sign", this test will fail.
 */
it('presents paper POD/POC and signed scan as optional on the form', function (): void {
    auth()->login($this->userA);
    $app = app(SaveApplicationDraft::class)->handle($this->userA, [
        'request_type' => RequestType::LicenceRenewal->value,
        'owner_type' => OwnerType::Individual->value,
    ]);
    $handover = app(SaveDocumentHandover::class)->handle($this->userA, [
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => 'Thandi Mahlangu',
        'dealer_person_name' => $this->userA->name,
        'application_ids' => [$app->id],
    ]);

    Livewire::test(DocumentHandoverForm::class, ['handover' => $handover])
        ->assertSee('Print paper POD/POC (optional)')
        ->assertSee('Confirm digitally')
        ->assertSee('Not needed for the record');
});
