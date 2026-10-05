<?php

use App\Actions\ConfirmDocumentHandover;
use App\Actions\PrepareSubmissionPack;
use App\Actions\RecordAuthorityReturn;
use App\Actions\ResolveAuthorityQuery;
use App\Actions\SaveDocumentHandover;
use App\Actions\SubmitToAuthority;
use App\Actions\TransitionApplication;
use App\Enums\ApplicationStage;
use App\Enums\DatafixStatus;
use App\Enums\DocumentStatus;
use App\Enums\HandoverDirection;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Exceptions\InvalidTransition;
use App\Livewire\Portal\DocumentHandoverForm;
use App\Livewire\Portal\OutstandingTasks;
use App\Livewire\Portal\ReviewWorkspace;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditEvent;
use App\Models\ClientAccount;
use App\Models\DocumentHandover;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\Payment;
use App\Models\User;
use App\Services\OperationsWorkloadService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('documents');

    $this->workload = app(OperationsWorkloadService::class);

    $this->dealership = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->otherDealership = ClientAccount::query()->create([
        'name' => 'Lowveld Vehicle Group',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->operations = User::factory()->create(['is_active' => true]);
    $this->operations->assignRole('reviewer');

    $this->finance = User::factory()->create(['is_active' => true]);
    $this->finance->assignRole('finance');

    $this->dealerUser = User::factory()->create(['is_active' => true, 'client_account_id' => $this->dealership->id]);
    $this->dealerUser->assignRole('customer_admin');

    $this->registrationType = DocumentType::query()->create([
        'code' => 'natis_original',
        'name' => 'Original NaTIS certificate',
        'is_identity_document' => false,
        'requires_original' => true,
    ]);

    $this->invoiceType = DocumentType::query()->create([
        'code' => 'dealer_invoice',
        'name' => 'Dealer invoice',
        'is_identity_document' => false,
    ]);
});

/**
 * A billed passenger application with two accepted documents, one of which
 * needs its physical original.
 */
function packReadyApplication(ClientAccount $account, bool $originalReceived = true): Application
{
    $application = Application::query()->create([
        'reference' => 'PACK-'.uniqid(),
        'client_account_id' => $account->id,
        'stage' => ApplicationStage::PaymentVerified,
        'request_type' => RequestType::LicenceRenewal,
        'vehicle_category' => VehicleCategory::Passenger,
        'owner_type' => 'business',
        'province' => 'gauteng',
        'datafix_status' => DatafixStatus::NotRequired,
        'fee_snapshot' => ['total_cents' => 10000, 'lines' => [['label' => 'Service fee', 'amount_cents' => 10000]]],
    ]);

    Payment::query()->create([
        'application_id' => $application->id,
        'amount_cents' => 10000,
        'method' => 'eft',
        'reference' => 'PAY-'.uniqid(),
        'verified_at' => now()->subHour(),
    ]);

    packDocument($application, DocumentType::query()->where('code', 'natis_original')->firstOrFail(), $originalReceived ? now()->subHour() : null);
    packDocument($application, DocumentType::query()->where('code', 'dealer_invoice')->firstOrFail());

    return $application->refresh();
}

function packDocument(Application $application, DocumentType $type, mixed $originalReceivedAt = null, DocumentStatus $status = DocumentStatus::Accepted): ApplicationDocument
{
    $document = ApplicationDocument::query()->create([
        'application_id' => $application->id,
        'document_type_id' => $type->id,
        'party_role' => 'vehicle',
        'required' => true,
        'status' => $status,
        'original_received_at' => $originalReceivedAt,
    ]);

    packVersion($document);

    return $document->refresh();
}

function packVersion(ApplicationDocument $document, string $mime = 'application/pdf'): DocumentVersion
{
    $version = DocumentVersion::query()->create([
        'application_document_id' => $document->id,
        'storage_path' => 'applications/'.$document->application_id.'/'.uniqid().'.pdf',
        'original_filename' => strtolower(str_replace(' ', '-', $document->documentType->name)).'.pdf',
        'mime' => $mime,
        'size' => 2048,
        'sha256' => hash('sha256', uniqid()),
        'scan_status' => 'clean',
    ]);

    $document->forceFill(['linked_version_id' => $version->id])->save();

    return $version;
}

it('freezes the current document versions into a submission pack manifest', function (): void {
    $application = packReadyApplication($this->dealership);

    $pack = app(PrepareSubmissionPack::class)->handle($application, $this->operations);

    $expectedVersions = $application->documents()->pluck('linked_version_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

    expect($pack->documentCount())->toBe(2)
        ->and(collect($pack->versionIds())->sort()->values()->all())->toBe($expectedVersions)
        ->and($pack->manifest['documents'][0])->toHaveKeys(['label', 'version_id', 'sha256', 'requires_original', 'original_received_at'])
        ->and($pack->prepared_by_id)->toBe($this->operations->id)
        ->and($application->currentSubmissionPack()?->id)->toBe($pack->id);

    expect(AuditEvent::query()->where('action', 'submission_pack.prepared')->count())->toBe(1);
});

it('reuses an unsubmitted pack on reprint and starts a new pack when a document version changes', function (): void {
    $application = packReadyApplication($this->dealership);
    $first = app(PrepareSubmissionPack::class)->handle($application, $this->operations);
    $again = app(PrepareSubmissionPack::class)->handle($application, $this->operations);

    expect($again->id)->toBe($first->id);

    packVersion($application->documents()->first());

    expect($application->refresh()->currentSubmissionPack())->toBeNull();

    $replacement = app(PrepareSubmissionPack::class)->handle($application, $this->operations);

    expect($replacement->id)->not->toBe($first->id)
        ->and($application->submissionPacks()->count())->toBe(2);
});

it('refuses a pack while a physical original is still outstanding', function (): void {
    $application = packReadyApplication($this->dealership, originalReceived: false);

    expect($this->workload->isReadyForAuthority($application))->toBeFalse()
        ->and($this->workload->authorityBlockers($application))->toContain('Original Original NaTIS certificate not received yet.')
        ->and($this->workload->readyForAuthorityApplications()->whereKey($application->id)->exists())->toBeFalse();

    expect(fn () => app(PrepareSubmissionPack::class)->handle($application, $this->operations))
        ->toThrow(ValidationException::class);
});

it('refuses a pack while an accepted required document has no file on record', function (): void {
    $application = packReadyApplication($this->dealership);
    $application->documents()->where('document_type_id', $this->invoiceType->id)->update(['linked_version_id' => null]);

    expect($this->workload->authorityBlockers($application))->toContain('Dealer invoice has no file on record to print.')
        ->and($this->workload->readyForAuthorityApplications()->whereKey($application->id)->exists())->toBeFalse();

    expect(fn () => app(PrepareSubmissionPack::class)->handle($application, $this->operations))
        ->toThrow(ValidationException::class);
});

it('only lets operations prepare packs', function (): void {
    $application = packReadyApplication($this->dealership);

    expect(fn () => app(PrepareSubmissionPack::class)->handle($application, $this->finance))
        ->toThrow(ValidationException::class);
});

it('prints the frozen pack for operations and audits the print', function (): void {
    $application = packReadyApplication($this->dealership);
    $pack = app(PrepareSubmissionPack::class)->handle($application, $this->operations);

    $this->actingAs($this->operations)
        ->get(route('review.packs.print', ['ids' => $application->id]))
        ->assertOk()
        ->assertSee($application->reference)
        ->assertSee('pack #'.$pack->id)
        ->assertSee('Original NaTIS certificate')
        ->assertSee('Dealer invoice');

    expect(AuditEvent::query()->where('action', 'submission_pack.printed')->count())->toBe(1);
});

it('keeps the pack print away from finance and dealership users', function (): void {
    $application = packReadyApplication($this->dealership);
    app(PrepareSubmissionPack::class)->handle($application, $this->operations);

    $this->actingAs($this->finance)
        ->get(route('review.packs.print', ['ids' => $application->id]))
        ->assertForbidden();

    $this->actingAs($this->dealerUser)
        ->get(route('review.packs.print', ['ids' => $application->id]))
        ->assertForbidden();
});

it('batch prepares the ticked packs from the submission packs tab and opens one print run', function (): void {
    $first = packReadyApplication($this->dealership);
    $second = packReadyApplication($this->otherDealership);

    Livewire::actingAs($this->operations)
        ->test(OutstandingTasks::class)
        ->set('tab', OperationsWorkloadService::TAB_SUBMISSION_PACKS)
        ->set('selectedApplicationIds', [$first->id, $second->id])
        ->call('preparePacks')
        ->assertRedirect(route('review.packs.print', ['ids' => $first->id.','.$second->id]));

    expect($first->submissionPacks()->count())->toBe(1)
        ->and($second->submissionPacks()->count())->toBe(1);
});

it('stamps the lodged pack when the application is submitted to the department', function (): void {
    $application = packReadyApplication($this->dealership);
    $pack = app(PrepareSubmissionPack::class)->handle($application, $this->operations);

    $submitted = app(SubmitToAuthority::class)->handle($application, $this->operations, 'DLTC-4471', now()->subMinutes(5));

    expect($submitted->stage)->toBe(ApplicationStage::SubmittedToAuthority)
        ->and($pack->refresh()->submitted_at)->not->toBeNull()
        ->and($pack->authority_reference)->toBe('DLTC-4471');
});

it('prepares and prints the pack from the review workspace', function (): void {
    $application = packReadyApplication($this->dealership);

    Livewire::actingAs($this->operations)
        ->test(ReviewWorkspace::class, ['application' => $application])
        ->assertSee('Prepare and print pack')
        ->call('preparePack')
        ->assertRedirect(route('review.packs.print', ['ids' => $application->id]));

    Livewire::actingAs($this->operations)
        ->test(ReviewWorkspace::class, ['application' => $application->refresh()])
        ->assertSee('Reprint pack')
        ->set('submitReference', 'DLTC-9001')
        ->call('submitToAuthority')
        ->assertHasNoErrors();

    expect($application->refresh()->stage)->toBe(ApplicationStage::SubmittedToAuthority);
});

it('keeps an unresolved department query out of the ready-to-submit list until it is resolved', function (): void {
    $application = packReadyApplication($this->dealership);
    app(SubmitToAuthority::class)->handle($application, $this->operations, 'DLTC-1', now()->subDay());
    $queried = app(TransitionApplication::class)->handle($application->refresh(), ApplicationStage::AuthorityQuery, $this->operations, 'Department wants a clearer invoice.');

    expect($this->workload->readyForAuthorityApplications()->whereKey($queried->id)->exists())->toBeFalse()
        ->and($this->workload->authorityQueryApplications()->whereKey($queried->id)->exists())->toBeTrue()
        ->and($this->workload->awaitingReturnApplications()->whereKey($queried->id)->exists())->toBeFalse()
        ->and($queried->latestAuthorityQueryNote())->toBe('Department wants a clearer invoice.');

    expect(fn () => app(TransitionApplication::class)->handle($queried, ApplicationStage::SubmittedToAuthority, $this->operations))
        ->toThrow(InvalidTransition::class, 'Record how the department query was resolved');

    $resolved = app(ResolveAuthorityQuery::class)->handle($queried, $this->operations, 'Dealer sent a new invoice scan.');

    expect($this->workload->readyForAuthorityApplications()->whereKey($resolved->id)->exists())->toBeTrue()
        ->and($this->workload->authorityQueryApplications()->whereKey($resolved->id)->exists())->toBeFalse();

    $resubmitted = app(SubmitToAuthority::class)->handle($resolved, $this->operations, 'DLTC-2', now());

    expect($resubmitted->stage)->toBe(ApplicationStage::SubmittedToAuthority)
        ->and($resubmitted->submissionPacks()->count())->toBe(2);
});

it('treats approved applications as still out at the department until receipt is recorded', function (): void {
    $application = packReadyApplication($this->dealership);
    app(SubmitToAuthority::class)->handle($application, $this->operations, 'DLTC-3', now()->subDays(2));
    $approved = app(TransitionApplication::class)->handle($application->refresh(), ApplicationStage::Approved, $this->operations);

    expect($this->workload->awaitingReturnApplications()->whereKey($approved->id)->exists())->toBeTrue()
        ->and($this->workload->returnedHandoverApplications()->whereKey($approved->id)->exists())->toBeFalse();

    expect(fn () => app(TransitionApplication::class)->handle($approved, ApplicationStage::ReadyForCollection, $this->operations))
        ->toThrow(InvalidTransition::class, 'Record the physical receipt');

    $returned = app(RecordAuthorityReturn::class)->handle($approved, $this->operations, now()->subHour(), 'Disc and RC2 back.');

    expect($returned->stage)->toBe(ApplicationStage::ReadyForCollection)
        ->and($returned->authority_returned_by_id)->toBe($this->operations->id)
        ->and($returned->authority_return_notes)->toBe('Disc and RC2 back.')
        ->and($this->workload->awaitingReturnApplications()->whereKey($returned->id)->exists())->toBeFalse()
        ->and($this->workload->returnedHandoverApplications()->whereKey($returned->id)->exists())->toBeTrue();
});

it('rejects a receipt dated in the future or before the submission', function (): void {
    $application = packReadyApplication($this->dealership);
    app(SubmitToAuthority::class)->handle($application, $this->operations, 'DLTC-4', now()->subDay());
    $approved = app(TransitionApplication::class)->handle($application->refresh(), ApplicationStage::Approved, $this->operations);

    expect(fn () => app(RecordAuthorityReturn::class)->handle($approved, $this->operations, now()->addDay()))
        ->toThrow(ValidationException::class);

    expect(fn () => app(RecordAuthorityReturn::class)->handle($approved, $this->operations, now()->subDays(3)))
        ->toThrow(ValidationException::class);
});

it('records physical receipt from the review workspace', function (): void {
    $application = packReadyApplication($this->dealership);
    app(SubmitToAuthority::class)->handle($application, $this->operations, 'DLTC-5', now()->subDay());
    $approved = app(TransitionApplication::class)->handle($application->refresh(), ApplicationStage::Approved, $this->operations);

    Livewire::actingAs($this->operations)
        ->test(ReviewWorkspace::class, ['application' => $approved])
        ->assertSee('Record physical receipt')
        ->assertDontSee('wire:click="advance(\'ready_for_collection\')"', false)
        ->set('returnNotes', 'Collected from DLTC counter.')
        ->call('recordReturn')
        ->assertHasNoErrors();

    expect($approved->refresh()->stage)->toBe(ApplicationStage::ReadyForCollection);
});

it('counts every outstanding task row, not distinct applications', function (): void {
    $application = packReadyApplication($this->dealership);
    $application->update(['stage' => ApplicationStage::DocumentReview]);
    $application->documents()->update(['status' => DocumentStatus::AwaitingReview]);

    $outstanding = collect($this->workload->counters())->firstWhere('key', 'outstanding');

    expect($outstanding['count'])->toBe(2)
        ->and($this->workload->tasks(OperationsWorkloadService::TAB_OUTSTANDING)->total())->toBe(2);
});

it('lets operations record a delivery hand-over for a dealership and completes the returned applications', function (): void {
    $application = packReadyApplication($this->dealership);
    $application->forceFill([
        'stage' => ApplicationStage::ReadyForCollection,
        'authority_returned_at' => now()->subHour(),
    ])->save();

    $this->actingAs($this->operations);

    $handover = app(SaveDocumentHandover::class)->handle($this->operations, [
        'client_account_id' => $this->dealership->id,
        'direction' => HandoverDirection::Delivery->value,
        'counterparty_name' => $this->operations->name,
        'counterparty_company' => 'Licentra',
        'dealer_person_name' => 'Johan Botha',
        'application_ids' => [$application->id],
    ]);

    expect($handover->client_account_id)->toBe($this->dealership->id)
        ->and($this->operations->can('confirm', $handover))->toBeTrue();

    app(ConfirmDocumentHandover::class)->handle($this->operations, $handover);

    expect($application->refresh()->stage)->toBe(ApplicationStage::Completed)
        ->and($application->completed_at)->not->toBeNull();
});

it('refuses applications from another dealership on an operations hand-over', function (): void {
    $foreign = packReadyApplication($this->otherDealership);

    $this->actingAs($this->operations);

    expect(fn () => app(SaveDocumentHandover::class)->handle($this->operations, [
        'client_account_id' => $this->dealership->id,
        'direction' => HandoverDirection::Delivery->value,
        'application_ids' => [$foreign->id],
    ]))->toThrow(ValidationException::class);
});

it('keeps finance read-only on hand-overs', function (): void {
    expect($this->finance->can('create', DocumentHandover::class))->toBeFalse()
        ->and($this->operations->can('create', DocumentHandover::class))->toBeTrue();
});

it('prefills the hand-over form for operations from the returned application', function (): void {
    $application = packReadyApplication($this->dealership);
    $application->forceFill(['stage' => ApplicationStage::ReadyForCollection, 'authority_returned_at' => now()])->save();

    Livewire::withQueryParams(['direction' => 'delivery', 'account' => $this->dealership->id, 'application' => $application->id])
        ->actingAs($this->operations)
        ->test(DocumentHandoverForm::class)
        ->assertSet('client_account_id', (string) $this->dealership->id)
        ->assertSet('application_ids', [$application->id])
        ->assertSet('counterparty_name', $this->operations->name)
        ->set('dealer_person_name', 'Johan Botha')
        ->call('save')
        ->assertHasNoErrors();

    expect(DocumentHandover::query()->where('client_account_id', $this->dealership->id)->count())->toBe(1);
});
