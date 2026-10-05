<?php

use App\Actions\TransitionApplication;
use App\Enums\ApplicationStage;
use App\Enums\DocumentStatus;
use App\Exceptions\InvalidTransition;
use App\Livewire\Portal\ReviewWorkspace;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\BusinessClient;
use App\Models\ClientAccount;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->dealer = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'quote_acceptance_allowed' => false,
    ]);
    $this->other = ClientAccount::query()->create([
        'name' => 'Kestrel Logistics',
        'type' => 'fleet_operator',
        'quote_acceptance_allowed' => true,
    ]);

    $this->application = Application::query()->create([
        'reference' => 'SUS-RBAC-00001',
        'client_account_id' => $this->dealer->id,
        'stage' => ApplicationStage::DocumentReview,
    ]);
    $this->draft = Application::query()->create([
        'reference' => 'SUS-RBAC-00002',
        'client_account_id' => $this->dealer->id,
        'stage' => ApplicationStage::Draft,
    ]);
    $this->quoted = Application::query()->create([
        'reference' => 'SUS-RBAC-00003',
        'client_account_id' => $this->dealer->id,
        'stage' => ApplicationStage::QuoteSent,
    ]);
    $this->payable = Application::query()->create([
        'reference' => 'SUS-RBAC-00004',
        'client_account_id' => $this->dealer->id,
        'stage' => ApplicationStage::PaymentPending,
        'fee_snapshot' => ['total_cents' => 10000],
    ]);
    $this->foreign = Application::query()->create([
        'reference' => 'SUS-RBAC-00005',
        'client_account_id' => $this->other->id,
        'stage' => ApplicationStage::DocumentReview,
    ]);
    $this->business = BusinessClient::query()->create([
        'client_account_id' => $this->dealer->id,
        'business_name' => 'Ridgeline Haulage',
    ]);
    $this->foreignBusiness = BusinessClient::query()->create([
        'client_account_id' => $this->other->id,
        'business_name' => 'Kestrel Fleet',
    ]);

    $this->application->refresh();
    $this->draft->refresh();
    $this->quoted->refresh();
    $this->payable->refresh();
    $this->foreign->refresh();
});

it('gives identity and unmask permissions only to licensing reviewers and admins', function () {
    foreach (['super_admin', 'customer_admin', 'reviewer'] as $role) {
        $user = rbacUser($role);

        expect($user->can('documents.identity.download'))->toBeTrue()
            ->and($user->can('identifiers.unmask'))->toBeTrue();
    }

    foreach (['finance', 'auditor', 'client_admin', 'client_user'] as $role) {
        $user = rbacUser($role, $this->dealer->id);

        expect($user->can('documents.identity.download'))->toBeFalse()
            ->and($user->can('identifiers.unmask'))->toBeFalse();
    }
});

it('sends guests to sign in and keeps registration closed', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('applications.index'))->assertRedirect(route('login'));
    $this->get(route('review.queue'))->assertRedirect(route('login'));
    $this->get(route('finance.payments'))->assertRedirect(route('login'));
    $this->get('/admin')->assertRedirect();
    $this->get('/register')->assertNotFound();
});

it('limits a client admin to their own account', function () {
    $this->actingAs(rbacUser('client_admin', $this->dealer->id));

    $this->get(route('dashboard'))->assertRedirect(route('applications.index'));
    $this->get(route('applications.index'))->assertOk();
    $this->get(route('applications.create'))->assertOk();
    $this->get(route('applications.show', $this->application))->assertOk();
    $this->get(route('applications.edit', $this->draft))->assertOk();
    $this->get(route('applications.edit', $this->application))->assertForbidden();
    $this->get(route('review.queue'))->assertForbidden();
    $this->get(route('review.show', $this->application))->assertForbidden();
    $this->get(route('applications.quote', $this->application))->assertForbidden();
    $this->get(route('finance.payments'))->assertForbidden();
    $this->get('/admin')->assertForbidden();
    $this->get(route('business-clients.index'))->assertOk();
    $this->get(route('business-clients.show', $this->business))->assertOk();
    $this->get(route('applications.show', $this->foreign))->assertNotFound();
    $this->get(route('business-clients.show', $this->foreignBusiness))->assertNotFound();
    expect(auth()->user()->can('acceptQuote', $this->quoted))->toBeTrue();
});

it('stops a client user from accepting quotes unless the account allows it', function () {
    $denied = rbacUser('client_user', $this->dealer->id);
    $allowed = rbacUser('client_user', $this->other->id);

    expect($denied->can('acceptQuote', $this->quoted))->toBeFalse()
        ->and($allowed->can('acceptQuote', $this->foreign->forceFill(['stage' => ApplicationStage::QuoteSent])))->toBeTrue();

    expect(stageDenial($denied, $this->quoted, ApplicationStage::QuoteAccepted))
        ->toBe('This account cannot accept quotes.');

    $this->actingAs($denied);
    $this->get(route('applications.index'))->assertOk();
    $this->get(route('review.queue'))->assertForbidden();
    $this->get(route('finance.payments'))->assertForbidden();
    $this->get('/admin')->assertForbidden();
    $this->get(route('applications.show', $this->foreign))->assertNotFound();
});

it('lets a reviewer work the queue and blocks admin, finance, and other clients files', function () {
    $reviewer = rbacUser('reviewer');
    $this->actingAs($reviewer);

    $this->get(route('dashboard'))->assertRedirect(route('review.queue'));
    $this->get(route('applications.index'))->assertForbidden();
    $this->get(route('applications.create'))->assertForbidden();
    $this->get(route('applications.show', $this->application))->assertOk();
    $this->get(route('applications.show', $this->foreign))->assertOk();
    $this->get(route('review.queue'))->assertOk();
    $this->get(route('review.show', $this->application))->assertOk();
    $this->get(route('applications.quote', $this->application))->assertOk();
    $this->get(route('finance.payments'))->assertForbidden();
    $this->get('/admin')->assertOk();
    $this->get('/admin/outstanding-tasks')->assertOk();
    $this->get('/admin/fee-lines')->assertForbidden();
    $this->get('/admin/users')->assertForbidden();
    $this->get(route('business-clients.index'))->assertOk();

    expect(stageDenial($reviewer, $this->application, ApplicationStage::ChangesRequested))
        ->not->toBe('You cannot make this stage change.')
        ->and(stageDenial($reviewer, $this->payable, ApplicationStage::PaymentVerified))
        ->toBe('You cannot make this stage change.');
});

it('lets finance verify payment and keeps them out of review actions and admin', function () {
    $finance = rbacUser('finance');
    $this->actingAs($finance);

    $this->get(route('dashboard'))->assertRedirect(route('finance.payments'));
    $this->get(route('finance.payments'))->assertOk();
    $this->get(route('review.queue'))->assertOk();
    $this->get(route('review.show', $this->application))->assertOk();
    $this->get(route('applications.quote', $this->application))->assertForbidden();
    $this->get(route('applications.create'))->assertForbidden();
    $this->get(route('applications.edit', $this->draft))->assertForbidden();
    $this->get('/admin')->assertOk();
    $this->get('/admin/outstanding-tasks')->assertOk();
    $this->get('/admin/fee-lines')->assertForbidden();
    $this->get('/admin/users')->assertForbidden();

    expect($finance->can('verifyPayment', $this->payable))->toBeTrue()
        ->and($finance->can('review', $this->application))->toBeFalse()
        ->and(stageDenial($finance, $this->payable, ApplicationStage::PaymentVerified))
        ->not->toBe('You cannot make this stage change.')
        ->and(stageDenial($finance, $this->application, ApplicationStage::ChangesRequested))
        ->toBe('You cannot make this stage change.');

    Livewire::test(ReviewWorkspace::class, ['application' => $this->application])
        ->call('acceptDocument', rbacDocument($this->application)->id)
        ->assertForbidden();
});

it('lets an auditor read and blocks every write', function () {
    $auditor = rbacUser('auditor');
    $this->actingAs($auditor);

    $this->get(route('dashboard'))->assertRedirect(route('review.queue'));
    $this->get(route('review.queue'))->assertOk();
    $this->get(route('review.show', $this->application))->assertOk();
    $this->get(route('applications.show', $this->foreign))->assertOk();
    $this->get(route('business-clients.show', $this->foreignBusiness))->assertOk();
    $this->get(route('applications.create'))->assertForbidden();
    $this->get(route('applications.edit', $this->draft))->assertForbidden();
    $this->get(route('applications.quote', $this->application))->assertForbidden();
    $this->get(route('finance.payments'))->assertForbidden();
    $this->get('/admin')->assertOk();
    $this->get(route('audit.index'))->assertOk();
    $this->get('/admin/fee-lines')->assertForbidden();
    $this->get('/admin/users')->assertForbidden();

    expect($auditor->can('review', $this->application))->toBeFalse()
        ->and($auditor->can('verifyPayment', $this->payable))->toBeFalse()
        ->and($auditor->can('update', $this->business))->toBeFalse()
        ->and(stageDenial($auditor, $this->application, ApplicationStage::ChangesRequested))
        ->toBe('You cannot make this stage change.');
});

it('opens admin, finance, and review to customer and super admins', function (string $role) {
    $admin = rbacUser($role);
    $this->actingAs($admin);

    $this->get('/admin')->assertOk();
    $this->get(route('review.queue'))->assertOk();
    $this->get(route('review.show', $this->application))->assertOk();
    $this->get(route('applications.quote', $this->application))->assertOk();
    $this->get(route('finance.payments'))->assertOk();
    $this->get(route('applications.create'))->assertForbidden();
    $this->get(route('applications.show', $this->foreign))->assertOk();

    expect($admin->can('review', $this->application))->toBeTrue()
        ->and($admin->can('verifyPayment', $this->payable))->toBeTrue()
        ->and(stageDenial($admin, $this->application, ApplicationStage::ChangesRequested))
        ->not->toBe('You cannot make this stage change.');
})->with(['customer_admin', 'super_admin']);

it('rejects a user with no role', function () {
    $user = User::factory()->create(['is_active' => true]);
    $this->actingAs($user);

    $this->get(route('applications.index'))->assertForbidden();
    $this->get(route('review.queue'))->assertForbidden();
    $this->get(route('review.show', $this->application))->assertForbidden();
    $this->get(route('finance.payments'))->assertForbidden();
    $this->get(route('business-clients.index'))->assertForbidden();
    $this->get('/admin')->assertForbidden();
});

it('blocks an inactive user at login and on every portal', function () {
    $user = rbacUser('customer_admin', active: false);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors();

    $this->actingAs($user);
    $this->get(route('review.queue'))->assertForbidden();
    $this->get(route('finance.payments'))->assertForbidden();
    $this->get('/admin')->assertForbidden();

    expect(stageDenial($user, $this->application, ApplicationStage::ChangesRequested))
        ->toBe('This user is inactive.');
});

it('downloads identity documents only for roles that hold the permission', function (string $role, bool $identity, int $status) {
    Storage::fake('documents');
    $version = rbacVersion($this->application, $identity);
    $accountId = str_starts_with($role, 'client') ? $this->dealer->id : null;

    $this->actingAs(rbacUser($role, $accountId))
        ->get(route('documents.download', $version))
        ->assertStatus($status);
})->with([
    'client admin identity' => ['client_admin', true, 403],
    'client admin vehicle file' => ['client_admin', false, 200],
    'reviewer identity' => ['reviewer', true, 200],
    'finance identity' => ['finance', true, 403],
    'finance vehicle file' => ['finance', false, 200],
    'auditor identity' => ['auditor', true, 403],
    'customer admin identity' => ['customer_admin', true, 200],
    'super admin identity' => ['super_admin', true, 200],
]);

it('hides another account file from the client that owns the request', function () {
    Storage::fake('documents');
    $version = rbacVersion($this->foreign, false);

    $this->actingAs(rbacUser('client_admin', $this->dealer->id))
        ->get(route('documents.download', $version))
        ->assertNotFound();
});

it('lets a reviewer accept a document and refuses the same action to an auditor', function () {
    $document = rbacDocument($this->application);

    Livewire::actingAs(rbacUser('reviewer'))
        ->test(ReviewWorkspace::class, ['application' => $this->application])
        ->call('acceptDocument', $document->id);

    expect($document->refresh()->status)->toBe(DocumentStatus::Accepted);

    Livewire::actingAs(rbacUser('auditor'))
        ->test(ReviewWorkspace::class, ['application' => $this->application])
        ->call('acceptDocument', $document->id)
        ->assertForbidden();

    expect($document->refresh()->status)->toBe(DocumentStatus::Accepted);
});

function rbacUser(string $role, ?int $accountId = null, bool $active = true): User
{
    $user = User::factory()->create([
        'client_account_id' => str_starts_with($role, 'client') ? $accountId : null,
        'is_active' => $active,
    ]);
    $user->assignRole($role);

    return $user;
}

function stageDenial(User $user, Application $application, ApplicationStage $to): ?string
{
    try {
        app(TransitionApplication::class)->handle($application->fresh(), $to, $user);
    } catch (InvalidTransition $exception) {
        return $exception->getMessage();
    }

    return null;
}

function rbacDocument(Application $application, bool $identity = false): ApplicationDocument
{
    $type = DocumentType::query()->create([
        'code' => ($identity ? 'id' : 'srf').'-'.$application->id.'-'.uniqid(),
        'name' => $identity ? 'Proxy ID copy' : 'Sales registration form',
        'is_identity_document' => $identity,
    ]);

    return ApplicationDocument::query()->create([
        'application_id' => $application->id,
        'document_type_id' => $type->id,
        'status' => 'awaiting_review',
    ]);
}

function rbacVersion(Application $application, bool $identity): DocumentVersion
{
    $document = rbacDocument($application, $identity);
    Storage::disk('documents')->put('files/'.$document->id.'.pdf', 'pdf');

    return DocumentVersion::query()->create([
        'application_document_id' => $document->id,
        'storage_path' => 'files/'.$document->id.'.pdf',
        'original_filename' => 'demo.pdf',
        'mime' => 'application/pdf',
        'size' => 3,
        'sha256' => str_repeat('ab', 32),
    ]);
}
