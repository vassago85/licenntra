<?php

use App\Actions\SubmitToAuthority;
use App\Enums\ApplicationStage;
use App\Enums\DatafixStatus;
use App\Enums\DocumentStatus;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditEvent;
use App\Models\ClientAccount;
use App\Models\DocumentType;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use App\Services\FeatureFlags;
use App\Services\OperationsWorkloadService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    // This suite exercises the full quote-and-payment workflow; the new
    // default-off flags are covered by FeatureFlagsTest. Keep both on here
    // so the existing scenarios still mean what they used to.
    FeatureFlags::swapQuotesEnabled(true);
    FeatureFlags::swapPaymentTrackingRequired(true);

    $this->service = app(OperationsWorkloadService::class);

    $this->dealership = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->fleet = ClientAccount::query()->create([
        'name' => 'Kestrel Logistics',
        'type' => 'fleet_operator',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->reviewer = User::factory()->create(['is_active' => true]);
    $this->reviewer->assignRole('reviewer');

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('owner');
});

afterEach(function (): void {
    FeatureFlags::swapQuotesEnabled(null);
    FeatureFlags::swapPaymentTrackingRequired(null);
});

/**
 * Build a passenger application that is ready for the authority:
 * payment verified, every required document accepted, no datafix needed.
 */
function makeReadyPassengerApplication(ClientAccount $account): Application
{
    $application = Application::query()->create([
        'reference' => 'OPS-'.uniqid(),
        'client_account_id' => $account->id,
        'stage' => ApplicationStage::PaymentVerified,
        'request_type' => RequestType::NewRegistration,
        'vehicle_category' => VehicleCategory::Passenger,
        'owner_type' => 'business',
        'province' => 'gauteng',
        'datafix_status' => DatafixStatus::NotRequired,
        'fee_snapshot' => ['total_cents' => 10000],
    ]);

    Payment::query()->create([
        'application_id' => $application->id,
        'amount_cents' => 10000,
        'method' => 'eft',
        'reference' => 'PAY-'.uniqid(),
        'verified_by' => $application->client_account_id,
        'verified_at' => now()->subHour(),
    ]);

    return $application->refresh();
}

it('readiness returns true for a clean payment-verified passenger application', function () {
    $application = makeReadyPassengerApplication($this->dealership);

    expect($this->service->isReadyForAuthority($application))->toBeTrue();
});

it('readiness returns false when a required document is still rejected', function () {
    $application = makeReadyPassengerApplication($this->dealership);
    $type = DocumentType::query()->create(['code' => 'test_poa', 'name' => 'Proof of address', 'is_identity_document' => false]);
    ApplicationDocument::query()->create([
        'application_id' => $application->id,
        'document_type_id' => $type->id,
        'party_role' => 'owner',
        'required' => true,
        'status' => DocumentStatus::Rejected,
    ]);

    expect($this->service->isReadyForAuthority($application->refresh()))->toBeFalse();
});

it('readiness returns false when no payment has been verified yet', function () {
    $application = Application::query()->create([
        'reference' => 'OPS-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::PaymentVerified,
        'vehicle_category' => VehicleCategory::Passenger,
        'request_type' => RequestType::NewRegistration,
    ]);

    expect($this->service->isReadyForAuthority($application))->toBeFalse();
});

it('readiness returns false for a commercial vehicle whose datafix is still open', function () {
    $application = makeReadyPassengerApplication($this->dealership);
    $application->vehicle_category = VehicleCategory::Commercial;
    $application->datafix_status = DatafixStatus::InProgress;
    $application->save();

    expect($this->service->isReadyForAuthority($application->refresh()))->toBeFalse();
});

it('readiness returns true for a commercial vehicle whose datafix is completed', function () {
    $application = makeReadyPassengerApplication($this->dealership);
    $application->vehicle_category = VehicleCategory::Commercial;
    $application->stage = ApplicationStage::DatafixInProgress;
    $application->datafix_status = DatafixStatus::Completed;
    $application->save();

    expect($this->service->isReadyForAuthority($application->refresh()))->toBeTrue();
});

it('readiness returns false for stages beyond submission', function () {
    $application = makeReadyPassengerApplication($this->dealership);
    $application->stage = ApplicationStage::Approved;
    $application->save();

    expect($this->service->isReadyForAuthority($application->refresh()))->toBeFalse();
});

it('counters only count distinct applications even when a dealership has many waiting-on tasks', function () {
    $application = Application::query()->create([
        'reference' => 'OPS-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::QuoteSent,
    ]);

    $quote = Quote::query()->create([
        'application_id' => $application->id,
        'status' => 'sent',
        'expires_at' => now()->addDays(7),
    ]);

    QuoteLine::query()->create([
        'quote_id' => $quote->id,
        'description' => 'Admin fee',
        'client_price_cents' => 10000,
        'internal_cost_cents' => 0,
    ]);

    // A second application in Draft stays separate.
    Application::query()->create([
        'reference' => 'OPS-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::Draft,
    ]);

    $counters = collect($this->service->counters())->keyBy('key');

    // "Outstanding tasks" now carries anything the licensing company must act on.
    // Two dealership-side applications don't appear here directly, but the
    // per-tab total for TAB_WAITING_ON_DEALERSHIP still equals 2.
    $waiting = $this->service->tasks(OperationsWorkloadService::TAB_WAITING_ON_DEALERSHIP, [], perPage: 100);

    expect($waiting->total())->toBe(2);
});

it('excludes completed cancelled and archived applications from every outstanding count', function () {
    foreach ([ApplicationStage::Completed, ApplicationStage::Cancelled, ApplicationStage::Archived] as $terminal) {
        Application::query()->create([
            'reference' => 'OPS-'.uniqid(),
            'client_account_id' => $this->dealership->id,
            'stage' => $terminal,
        ]);
    }

    $counters = collect($this->service->counters())->keyBy('key');

    expect($counters['outstanding']['count'])->toBe(0)
        ->and($counters['submission_packs']['count'])->toBe(0)
        ->and($counters['awaiting_return']['count'])->toBe(0)
        ->and($counters['returned_handover']['count'])->toBe(0);
});

it('dealership workload default view hides accounts with no outstanding work', function () {
    Application::query()->create([
        'reference' => 'OPS-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::Draft,
    ]);

    $rows = $this->service->accountRows(['needs_action_only' => true]);

    expect($rows->pluck('id')->all())->toBe([$this->dealership->id])
        ->and($rows->pluck('id')->all())->not->toContain($this->fleet->id);
});

it('counters agree with the per-tab task counts', function () {
    $readyApp = makeReadyPassengerApplication($this->dealership);
    $readyApp->update(['assigned_reviewer_id' => $this->reviewer->id]);

    Application::query()->create([
        'reference' => 'OPS-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::Draft,
    ]);

    $counters = collect($this->service->counters())->keyBy('key');

    $packs = $this->service->tasks(OperationsWorkloadService::TAB_SUBMISSION_PACKS, [], perPage: 1000);

    expect($packs->total())->toBe($counters['submission_packs']['count']);
});

it('submit to authority writes reference and date, transitions stage, and audits', function () {
    $application = makeReadyPassengerApplication($this->dealership);
    $application->update(['stage' => ApplicationStage::PaymentVerified]);

    $before = AuditEvent::query()->count();

    app(SubmitToAuthority::class)->handle(
        $application->refresh(),
        $this->admin,
        'GP-2026-00042',
        now()->subMinutes(5),
    );

    $application->refresh();

    expect($application->authority_reference)->toBe('GP-2026-00042')
        ->and($application->authority_submitted_at)->not->toBeNull()
        ->and($application->stage)->toBe(ApplicationStage::SubmittedToAuthority)
        ->and(AuditEvent::query()->count())->toBeGreaterThan($before)
        ->and(AuditEvent::query()->where('action', 'application.authority_submitted')->exists())->toBeTrue();
});

it('submit to authority refuses when the application is not ready', function () {
    $application = Application::query()->create([
        'reference' => 'OPS-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::PaymentVerified,
        'vehicle_category' => VehicleCategory::Passenger,
        'request_type' => RequestType::NewRegistration,
    ]);

    expect(fn () => app(SubmitToAuthority::class)->handle(
        $application,
        $this->admin,
        'GP-2026-99999',
        now(),
    ))->toThrow(InvalidTransition::class);
});

it('submit to authority refuses a blank reference', function () {
    $application = makeReadyPassengerApplication($this->dealership);

    expect(fn () => app(SubmitToAuthority::class)->handle(
        $application,
        $this->admin,
        '   ',
        now(),
    ))->toThrow(ValidationException::class);
});

it('submit to authority refuses a future submission date', function () {
    $application = makeReadyPassengerApplication($this->dealership);

    expect(fn () => app(SubmitToAuthority::class)->handle(
        $application,
        $this->admin,
        'GP-2026-NEXT',
        now()->addDay(),
    ))->toThrow(ValidationException::class);
});

it('dealership card board returns one card per active application for the scoped dealership and excludes terminal stages', function () {
    $activeDraft = Application::query()->create([
        'reference' => 'OPS-D-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::Draft,
    ]);

    $activePayment = Application::query()->create([
        'reference' => 'OPS-PP-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::PaymentPending,
    ]);

    // These must not appear: terminal stages and other dealerships.
    Application::query()->create([
        'reference' => 'OPS-T-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::Completed,
    ]);
    Application::query()->create([
        'reference' => 'OPS-X-'.uniqid(),
        'client_account_id' => $this->fleet->id,
        'stage' => ApplicationStage::Draft,
    ]);

    $cards = $this->service->applicationCards($this->dealership);

    expect($cards)->toHaveCount(2)
        ->and($cards->pluck('application.reference')->sort()->values()->all())
        ->toBe(collect([$activeDraft->reference, $activePayment->reference])->sort()->values()->all());
});

it('card board search matches on vehicle registration or VIN', function () {
    $hit = Application::query()->create([
        'reference' => 'OPS-R-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::Draft,
    ]);
    $hit->vehicle()->create(['vehicle_register_number' => 'TLX999G', 'vin' => 'ABCDEFGH1234567X9']);

    $miss = Application::query()->create([
        'reference' => 'OPS-R-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::Draft,
    ]);
    $miss->vehicle()->create(['vehicle_register_number' => 'ZZZ000Z', 'vin' => 'ZZZZZZZZ0000000ZZ']);

    $byRego = $this->service->applicationCards($this->dealership, ['search' => 'TLX999']);
    $byVin = $this->service->applicationCards($this->dealership, ['search' => '1234567X']);
    $noMatch = $this->service->applicationCards($this->dealership, ['search' => 'nothing']);

    expect($byRego->pluck('application.reference')->all())->toBe([$hit->reference])
        ->and($byVin->pluck('application.reference')->all())->toBe([$hit->reference])
        ->and($noMatch)->toBeEmpty();
});

it('card board derives the right next_action per stage and surfaces blockers', function () {
    $review = Application::query()->create([
        'reference' => 'OPS-DR-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::DocumentReview,
    ]);
    $type = DocumentType::query()->create(['code' => 'poa_'.uniqid(), 'name' => 'POA', 'is_identity_document' => false]);
    ApplicationDocument::query()->create([
        'application_id' => $review->id,
        'document_type_id' => $type->id,
        'party_role' => 'owner',
        'required' => true,
        'status' => DocumentStatus::Uploaded,
    ]);

    $payVerify = Application::query()->create([
        'reference' => 'OPS-VP-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::PaymentPending,
    ]);
    Payment::query()->create([
        'application_id' => $payVerify->id,
        'amount_cents' => 10000,
        'method' => 'eft',
        'reference' => 'PAY-'.uniqid(),
        'verified_at' => null,
    ]);

    $payOwed = Application::query()->create([
        'reference' => 'OPS-OW-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::PaymentPending,
    ]);

    $cards = $this->service->applicationCards($this->dealership)->keyBy('application.reference');

    expect($cards[$review->reference]['next_action_key'])->toBe('review_docs')
        ->and($cards[$payVerify->reference]['next_action_key'])->toBe('verify_payment')
        ->and($cards[$payOwed->reference]['next_action_key'])->toBe('open_app')
        ->and($cards[$payOwed->reference]['payment_owed'])->toBeTrue();
});

it('card board page is reachable by internal staff for a given dealership and 404s for a missing dealership', function () {
    $reviewer = User::factory()->create(['is_active' => true]);
    $reviewer->assignRole('reviewer');

    $finance = User::factory()->create(['is_active' => true]);
    $finance->assignRole('finance');

    $client = User::factory()->create(['client_account_id' => $this->dealership->id, 'is_active' => true]);
    $client->assignRole('customer_admin');

    $url = route('dealerships.board', ['account_id' => $this->dealership->id]);

    $this->actingAs($reviewer)->get($url)->assertSuccessful();
    $this->actingAs($finance)->get($url)->assertSuccessful();
    $this->actingAs($this->admin)->get($url)->assertSuccessful();
    $this->actingAs($client)->get($url)->assertForbidden();

    $this->actingAs($this->admin)->get(route('dealerships.board', ['account_id' => 99999]))->assertNotFound();
});

it('card board page renders a dealership picker when visited with no account_id instead of 404', function () {
    // Previously the page 404d when visited without ?account_id= - which
    // broke the sidebar link that intentionally omits it. The page now
    // falls back to a picker.
    $this->actingAs($this->admin)
        ->get(route('dealerships.board'))
        ->assertSuccessful()
        ->assertSee('Pick a dealership');
});

it('dealerships whose only outstanding work is a payment they still owe remain visible in the default view', function () {
    Application::query()->create([
        'reference' => 'OPS-'.uniqid(),
        'client_account_id' => $this->fleet->id,
        'stage' => ApplicationStage::PaymentPending,
    ]);

    $rows = $this->service->accountRows(['needs_action_only' => true]);

    expect($rows->pluck('id')->all())->toContain($this->fleet->id)
        ->and($rows->firstWhere('id', $this->fleet->id)['payments_owed_by_dealership'])->toBe(1);
});

it('payment-owed count excludes payment rows already uploaded for verification', function () {
    $uploaded = Application::query()->create([
        'reference' => 'OPS-'.uniqid(),
        'client_account_id' => $this->fleet->id,
        'stage' => ApplicationStage::PaymentPending,
    ]);

    Payment::query()->create([
        'application_id' => $uploaded->id,
        'amount_cents' => 10000,
        'method' => 'eft',
        'reference' => 'PAY-'.uniqid(),
        'verified_by' => null,
        'verified_at' => null,
    ]);

    $rows = $this->service->accountRows(['needs_action_only' => true]);

    expect($rows->firstWhere('id', $this->fleet->id)['payments_owed_by_dealership'])->toBe(0)
        ->and($rows->firstWhere('id', $this->fleet->id)['payments_awaiting_verification'])->toBe(1);
});

it('having many required documents on one application does not inflate the dealership approval count', function () {
    $application = Application::query()->create([
        'reference' => 'OPS-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::DocumentReview,
    ]);

    $types = collect(['id', 'poa', 'natis'])->map(fn (string $code) => DocumentType::query()->create([
        'code' => $code.'_'.uniqid(),
        'name' => strtoupper($code),
        'is_identity_document' => false,
    ]));

    foreach ($types as $type) {
        ApplicationDocument::query()->create([
            'application_id' => $application->id,
            'document_type_id' => $type->id,
            'party_role' => 'owner',
            'required' => true,
            'status' => DocumentStatus::Uploaded,
        ]);
    }

    $rows = $this->service->accountRows(['needs_action_only' => true]);
    $dealershipRow = $rows->firstWhere('id', $this->dealership->id);

    expect($dealershipRow['awaiting_document_approval'])->toBe(1)
        ->and($dealershipRow['active_applications'])->toBe(1);
});

it('waiting_on_dealership sub-filter stage returns only matching tasks', function () {
    $draft = Application::query()->create([
        'reference' => 'OPS-D-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::Draft,
    ]);

    $pending = Application::query()->create([
        'reference' => 'OPS-P-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::PaymentPending,
    ]);

    $paymentTasks = $this->service->tasks(
        OperationsWorkloadService::TAB_WAITING_ON_DEALERSHIP,
        ['stage' => OperationsWorkloadService::WAITING_PAYMENT_PENDING],
        perPage: 100,
    );

    $referenceColumn = collect($paymentTasks->items())->pluck('application.reference');

    expect($referenceColumn)->toContain($pending->reference)
        ->and($referenceColumn)->not->toContain($draft->reference);
});

it('approvals sub-filter kind returns only documents when kind is document and only payments when kind is payment', function () {
    $docApp = Application::query()->create([
        'reference' => 'OPS-DOC-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::DocumentReview,
    ]);
    $type = DocumentType::query()->create(['code' => 'poa_'.uniqid(), 'name' => 'POA', 'is_identity_document' => false]);
    ApplicationDocument::query()->create([
        'application_id' => $docApp->id,
        'document_type_id' => $type->id,
        'party_role' => 'owner',
        'required' => true,
        'status' => DocumentStatus::Uploaded,
    ]);

    $payApp = Application::query()->create([
        'reference' => 'OPS-PAY-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::PaymentPending,
    ]);
    Payment::query()->create([
        'application_id' => $payApp->id,
        'amount_cents' => 10000,
        'method' => 'eft',
        'reference' => 'PAY-'.uniqid(),
        'verified_at' => null,
    ]);

    $docsOnly = $this->service->tasks(
        OperationsWorkloadService::TAB_APPROVALS,
        ['kind' => OperationsWorkloadService::KIND_DOCUMENT],
        perPage: 100,
    );
    $paysOnly = $this->service->tasks(
        OperationsWorkloadService::TAB_APPROVALS,
        ['kind' => OperationsWorkloadService::KIND_PAYMENT],
        perPage: 100,
    );

    expect(collect($docsOnly->items())->pluck('kind')->unique()->all())->toBe(['approval.document'])
        ->and(collect($paysOnly->items())->pluck('kind')->unique()->all())->toBe(['approval.payment']);
});

it('active scope on the all tab lists applications that have no outstanding task but are not terminal', function () {
    Application::query()->create([
        'reference' => 'OPS-APR-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::Approved,
    ]);

    $outstanding = $this->service->tasks(OperationsWorkloadService::TAB_ALL, ['scope' => OperationsWorkloadService::SCOPE_OUTSTANDING], perPage: 100);
    $active = $this->service->tasks(OperationsWorkloadService::TAB_ALL, ['scope' => OperationsWorkloadService::SCOPE_ACTIVE], perPage: 100);

    expect($outstanding->total())->toBe(0)
        ->and($active->total())->toBe(1);
});

it('every dealership workload count links to a tab that returns the same number of rows', function () {
    // Payment pending (owed).
    Application::query()->create([
        'reference' => 'OPS-P-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::PaymentPending,
    ]);
    // Draft (dealership owes submission).
    Application::query()->create([
        'reference' => 'OPS-DR-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::Draft,
    ]);
    // Approved (no outstanding work but still active).
    Application::query()->create([
        'reference' => 'OPS-AP-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::Approved,
    ]);

    $row = $this->service->accountRows(['needs_action_only' => false])->firstWhere('id', $this->dealership->id);

    $filters = ['account_id' => $this->dealership->id];

    $paymentsOwed = $this->service->tasks(
        OperationsWorkloadService::TAB_WAITING_ON_DEALERSHIP,
        $filters + ['stage' => OperationsWorkloadService::WAITING_PAYMENT_PENDING],
        perPage: 1000,
    );
    $draftsCorrections = $this->service->tasks(
        OperationsWorkloadService::TAB_WAITING_ON_DEALERSHIP,
        $filters + ['stage' => OperationsWorkloadService::WAITING_DRAFT_OR_CORRECTIONS],
        perPage: 1000,
    );
    $active = $this->service->tasks(
        OperationsWorkloadService::TAB_ALL,
        $filters + ['scope' => OperationsWorkloadService::SCOPE_ACTIVE],
        perPage: 1000,
    );

    expect($paymentsOwed->total())->toBe($row['payments_owed_by_dealership'])
        ->and($draftsCorrections->total())->toBe($row['waiting_on_dealership_docs'])
        ->and($active->total())->toBe($row['active_applications']);
});

it('clearing the reviewer filter restores all accounts that have outstanding work', function () {
    $otherReviewer = User::factory()->create(['is_active' => true]);
    $otherReviewer->assignRole('reviewer');

    Application::query()->create([
        'reference' => 'OPS-R-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::Draft,
        'assigned_reviewer_id' => $this->reviewer->id,
    ]);

    Application::query()->create([
        'reference' => 'OPS-R-'.uniqid(),
        'client_account_id' => $this->fleet->id,
        'stage' => ApplicationStage::PaymentPending,
        'assigned_reviewer_id' => $otherReviewer->id,
    ]);

    $filteredToOtherReviewer = $this->service->accountRows([
        'reviewer_id' => $otherReviewer->id,
        'needs_action_only' => true,
    ]);

    expect($filteredToOtherReviewer->pluck('id')->all())
        ->toBe([$this->fleet->id]);

    $cleared = $this->service->accountRows(['needs_action_only' => true]);

    expect($cleared->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->dealership->id, $this->fleet->id])->sort()->values()->all());
});

it('finance users can reach the payment queue and open the specific application the dashboard link points at', function () {
    $finance = User::factory()->create(['is_active' => true]);
    $finance->assignRole('finance');

    $application = Application::query()->create([
        'reference' => 'OPS-FIN-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::PaymentPending,
    ]);

    Payment::query()->create([
        'application_id' => $application->id,
        'amount_cents' => 10000,
        'method' => 'eft',
        'reference' => 'PAY-'.uniqid(),
        'verified_at' => null,
    ]);

    $this->actingAs($finance)
        ->get('/finance/payments?application='.$application->id)
        ->assertSuccessful();
});

it('config resources are forbidden to reviewers and finance users', function () {
    $reviewer = User::factory()->create(['is_active' => true]);
    $reviewer->assignRole('reviewer');

    $finance = User::factory()->create(['is_active' => true]);
    $finance->assignRole('finance');

    foreach (['/admin/fee-lines', '/admin/users', '/admin/document-rules'] as $url) {
        $this->actingAs($reviewer)->get($url)->assertForbidden();
        $this->actingAs($finance)->get($url)->assertForbidden();
    }
});

it('client users and client admins are forbidden to reach any admin panel URL', function () {
    $clientAdmin = User::factory()->create(['client_account_id' => $this->dealership->id, 'is_active' => true]);
    $clientAdmin->assignRole('customer_admin');

    $clientUser = User::factory()->create(['client_account_id' => $this->dealership->id, 'is_active' => true]);
    $clientUser->assignRole('customer_user');

    foreach (['/admin', route('tasks.outstanding'), '/admin/fee-lines'] as $url) {
        $this->actingAs($clientAdmin)->get($url)->assertForbidden();
        $this->actingAs($clientUser)->get($url)->assertForbidden();
    }
});

it('outstanding tasks workspace is reachable by every internal staff role but not by clients', function () {
    $dealer = User::factory()->create(['client_account_id' => $this->dealership->id, 'is_active' => true]);
    $dealer->assignRole('customer_admin');

    $clientUser = User::factory()->create(['client_account_id' => $this->dealership->id, 'is_active' => true]);
    $clientUser->assignRole('customer_user');

    $reviewer = User::factory()->create(['is_active' => true]);
    $reviewer->assignRole('reviewer');

    $finance = User::factory()->create(['is_active' => true]);
    $finance->assignRole('finance');

    // Clients - from the public portal perspective - must never reach the admin panel.
    $this->actingAs($dealer)->get(route('tasks.outstanding'))->assertForbidden();
    $this->actingAs($clientUser)->get(route('tasks.outstanding'))->assertForbidden();

    // Internal operations staff and admins should all land successfully.
    $this->actingAs($reviewer)->get(route('tasks.outstanding'))->assertSuccessful();
    $this->actingAs($finance)->get(route('tasks.outstanding'))->assertSuccessful();
    $this->actingAs($this->admin)->get(route('tasks.outstanding'))->assertSuccessful();
});
