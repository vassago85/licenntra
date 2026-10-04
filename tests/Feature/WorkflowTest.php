<?php

use App\Actions\CalculateFees;
use App\Actions\ResolveRequiredDocuments;
use App\Actions\TransitionApplication;
use App\Enums\ApplicationStage;
use App\Enums\DocumentStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\ClientAccount;
use App\Models\FeeLine;
use App\Models\User;
use Database\Seeders\DocumentRuleSeeder;
use Database\Seeders\FeeTableSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(DocumentRuleSeeder::class);
    foreach (['super_admin', 'customer_admin', 'reviewer', 'finance', 'auditor', 'client_admin', 'client_user'] as $role) {
        Role::findOrCreate($role);
    }
});

function accountUser(string $role, ?ClientAccount $account = null): User
{
    $account ??= ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'quote_acceptance_allowed' => true,
    ]);

    $user = User::factory()->create([
        'client_account_id' => str_starts_with($role, 'client') ? $account->id : null,
        'is_active' => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function sampleApplication(array $overrides = []): Application
{
    $account = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'markup_basis_points' => 0,
    ]);

    $application = Application::query()->create(array_merge([
        'reference' => 'LIC-'.uniqid(),
        'client_account_id' => $account->id,
        'request_type' => 'new_registration',
        'service_type' => 'register_and_license',
        'vehicle_category' => 'commercial',
        'owner_type' => 'business',
        'province' => 'gauteng',
        'is_financed' => true,
        'stage' => 'draft',
    ], $overrides));

    $application->vehicle()->create([
        'vin' => 'JHHGD8JLA7K104512',
        'vehicle_register_number' => 'TLX914G',
        'make' => 'Hino',
        'model' => '500 1627',
        'tare_kg' => 8420,
    ]);

    app(ResolveRequiredDocuments::class)->handle($application);

    return $application->refresh();
}

it('requires the commercial financed checklist and not a passenger one', function () {
    $commercial = sampleApplication();
    $codes = $commercial->documents()->where('required', true)->with('documentType')->get()
        ->map(fn ($document) => $document->party_role.':'.$document->documentType->code)
        ->sort()->values()->all();

    expect($codes)->toBe([
        'owner:brn_certificate',
        'owner:poa',
        'owner:proxy_id',
        'title_holder:title_holder_brn',
        'title_holder:title_holder_proxy_id',
        'vehicle:body_builder_certificate',
        'vehicle:cof',
        'vehicle:srf',
        'vehicle:weighbridge_certificate',
    ]);

    $passenger = sampleApplication([
        'vehicle_category' => 'passenger',
        'owner_type' => 'individual',
        'is_financed' => false,
        'reference' => 'LIC-'.uniqid(),
    ]);

    $passengerCodes = $passenger->documents()->where('required', true)->with('documentType')->get()
        ->map(fn ($document) => $document->documentType->code)
        ->sort()->values()->all();

    expect($passengerCodes)->toBe(['id_copy', 'poa', 'srf']);
});

it('blocks submission until the vin and register number are valid and documents are uploaded', function () {
    $application = sampleApplication();
    $application->vehicle->update(['vin' => 'SHORT']);
    $client = accountUser('client_user', $application->clientAccount);

    expect(fn () => app(TransitionApplication::class)->handle($application, ApplicationStage::Submitted, $client))
        ->toThrow(InvalidTransition::class);

    $application->vehicle->update(['vin' => 'JHHGD8JLA7K104512']);

    expect(fn () => app(TransitionApplication::class)->handle($application->refresh(), ApplicationStage::Submitted, $client))
        ->toThrow(InvalidTransition::class);
});

it('refuses a forbidden stage change', function () {
    $application = sampleApplication();
    $reviewer = accountUser('reviewer');

    expect(fn () => app(TransitionApplication::class)->handle($application, ApplicationStage::Approved, $reviewer))
        ->toThrow(InvalidTransition::class);
});

it('stops a commercial vehicle reaching the authority without a completed datafix', function () {
    $this->seed(FeeTableSeeder::class);
    $application = sampleApplication(['is_financed' => false, 'reference' => 'LIC-'.uniqid()]);
    $application->documents()->where('required', true)->update(['status' => DocumentStatus::Accepted->value]);
    $client = accountUser('client_user', $application->clientAccount);
    $reviewer = accountUser('reviewer');
    $finance = accountUser('finance');
    $transition = app(TransitionApplication::class);

    $transition->handle($application, ApplicationStage::Submitted, $client);
    $application->refresh();
    $transition->handle($application, ApplicationStage::DocumentReview, null, isSystem: true);
    $application->refresh();
    $transition->handle($application, ApplicationStage::PaymentPending, $reviewer);
    $application->refresh();
    $application->payments()->create([
        'amount_cents' => $application->fee_snapshot['total_cents'],
        'method' => 'eft',
        'reference' => 'PAY-1',
    ]);
    $transition->handle($application, ApplicationStage::PaymentVerified, $finance);
    $application->refresh();

    expect(fn () => $transition->handle($application, ApplicationStage::SubmittedToAuthority, $reviewer))
        ->toThrow(InvalidTransition::class);
});

it('excludes licence fees from register only and keeps a snapshot after the fee table changes', function () {
    $this->seed(FeeTableSeeder::class);
    $licensed = sampleApplication(['reference' => 'LIC-'.uniqid()]);
    $registerOnly = sampleApplication([
        'service_type' => 'register_only',
        'reference' => 'LIC-'.uniqid(),
        'client_account_id' => $licensed->client_account_id,
    ]);

    $withLicence = app(CalculateFees::class)->snapshot($licensed->refresh());
    $without = app(CalculateFees::class)->snapshot($registerOnly->refresh());

    expect(collect($withLicence['lines'])->pluck('code'))->toContain('licence')
        ->and(collect($without['lines'])->pluck('code'))->not->toContain('licence');

    $licensed->fee_snapshot = $withLicence;
    $licensed->save();
    FeeLine::query()->update(['amount_cents' => 1]);

    expect($licensed->refresh()->fee_snapshot['total_cents'])->toBe($withLicence['total_cents']);
});

it('rejects updates and deletes on the audit log', function () {
    $event = AuditEvent::query()->create([
        'occurred_at' => now(),
        'action' => 'test',
        'summary' => 'Created',
        'is_system' => true,
    ]);

    expect(fn () => DB::table('audit_events')->where('id', $event->id)->update(['summary' => 'Changed']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('audit_events')->where('id', $event->id)->delete())
        ->toThrow(QueryException::class);

    expect(AuditEvent::query()->count())->toBe(1);
});

it('hides another client account from a client user', function () {
    $own = sampleApplication(['reference' => 'LIC-OWN-'.uniqid()]);
    $other = sampleApplication(['reference' => 'LIC-OTHER-'.uniqid()]);
    $user = accountUser('client_user', $own->clientAccount);

    $this->actingAs($user);

    expect(Application::query()->pluck('id'))->toContain($own->id)->not->toContain($other->id);
});
