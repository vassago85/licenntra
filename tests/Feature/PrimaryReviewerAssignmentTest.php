<?php

use App\Actions\AssignReviewer;
use App\Actions\AutoAssignPrimaryReviewer;
use App\Enums\ApplicationStage;
use App\Enums\OwnerType;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The licensing company nominates a primary reviewer per dealership so
 * recurring customers land on the right reviewer's queue automatically.
 * Any other reviewer can still pick the work up when the primary is on
 * leave - that handover is recorded in the audit log as "covering for X"
 * so the operations lead can see the coverage pattern at a glance.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->dealer = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'brn' => '1996/001234/07',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->primary = User::factory()->create([
        'name' => 'Thandi Primary',
        'is_active' => true,
    ]);
    $this->primary->assignRole('reviewer');

    $this->coverer = User::factory()->create([
        'name' => 'Jacob Coverer',
        'is_active' => true,
    ]);
    $this->coverer->assignRole('reviewer');

    $this->customerAdmin = User::factory()->create([
        'name' => 'Lerato Lead',
        'is_active' => true,
    ]);
    $this->customerAdmin->assignRole('owner');

    $this->dealerUser = User::factory()->create([
        'client_account_id' => $this->dealer->id,
        'is_active' => true,
    ]);
    $this->dealerUser->assignRole('customer_admin');
});

/**
 * Build a minimal Application row in DocumentReview stage. We skip the
 * submission guards because the behaviour under test is reviewer
 * assignment, not submission validation.
 */
function makeReviewableApplication(ClientAccount $dealer, User $actor): Application
{
    return Application::query()->create([
        'reference' => 'LIC-TEST-'.random_int(10000, 99999),
        'client_account_id' => $dealer->id,
        'created_by_user_id' => $actor->id,
        'stage' => ApplicationStage::DocumentReview,
        'request_type' => RequestType::LicenceRenewal,
        'vehicle_category' => VehicleCategory::Commercial,
        'owner_type' => OwnerType::Business,
    ]);
}

it('auto-assigns a submitted application to the dealership primary reviewer', function (): void {
    $this->dealer->update(['primary_reviewer_user_id' => $this->primary->id]);

    $application = makeReviewableApplication($this->dealer, $this->dealerUser);

    $result = app(AutoAssignPrimaryReviewer::class)->handle($application, $this->dealerUser);

    expect($result->assigned_reviewer_id)->toBe($this->primary->id);
});

it('leaves assigned_reviewer_id null when the dealership has no primary reviewer', function (): void {
    $application = makeReviewableApplication($this->dealer, $this->dealerUser);

    $result = app(AutoAssignPrimaryReviewer::class)->handle($application, $this->dealerUser);

    expect($result->assigned_reviewer_id)->toBeNull();
});

it('skips auto-assignment when the nominated primary reviewer is inactive (on long leave)', function (): void {
    $this->primary->update(['is_active' => false]);
    $this->dealer->update(['primary_reviewer_user_id' => $this->primary->id]);

    $application = makeReviewableApplication($this->dealer, $this->dealerUser);

    $result = app(AutoAssignPrimaryReviewer::class)->handle($application, $this->dealerUser);

    expect($result->assigned_reviewer_id)->toBeNull();
});

it('does not overwrite an existing manual assignment', function (): void {
    $this->dealer->update(['primary_reviewer_user_id' => $this->primary->id]);

    $application = makeReviewableApplication($this->dealer, $this->dealerUser);
    $application->update(['assigned_reviewer_id' => $this->coverer->id]);

    $result = app(AutoAssignPrimaryReviewer::class)->handle($application, $this->dealerUser);

    expect($result->assigned_reviewer_id)->toBe($this->coverer->id);
});

it('records a plain "Reviewer assigned" audit entry when the primary reviewer takes their own account', function (): void {
    $this->dealer->update(['primary_reviewer_user_id' => $this->primary->id]);

    $application = makeReviewableApplication($this->dealer, $this->dealerUser);

    app(AssignReviewer::class)->handle($application, $this->primary, $this->primary);

    $audit = AuditEvent::query()
        ->where('action', 'application.reviewer_assigned')
        ->where('subject_id', $application->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->summary)->toBe('Reviewer assigned.')
        ->and($audit->after['covering_for_user_id'])->toBeNull();
});

it('annotates the audit entry with "covering for X" when another reviewer picks up the primary\'s account', function (): void {
    $this->dealer->update(['primary_reviewer_user_id' => $this->primary->id]);

    $application = makeReviewableApplication($this->dealer, $this->dealerUser);

    app(AssignReviewer::class)->handle($application, $this->coverer, $this->coverer);

    $audit = AuditEvent::query()
        ->where('action', 'application.reviewer_assigned')
        ->where('subject_id', $application->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->summary)->toContain('Jacob Coverer')
        ->and($audit->summary)->toContain('covering for Thandi Primary')
        ->and($audit->after['covering_for_user_id'])->toBe($this->primary->id);
});

it('does not add a covering annotation when the dealership has no primary reviewer configured', function (): void {
    $application = makeReviewableApplication($this->dealer, $this->dealerUser);

    app(AssignReviewer::class)->handle($application, $this->coverer, $this->coverer);

    $audit = AuditEvent::query()
        ->where('action', 'application.reviewer_assigned')
        ->where('subject_id', $application->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->summary)->toBe('Reviewer assigned.');
});

it('accepts a customer_admin as a valid reviewer (they also staff the review queue)', function (): void {
    $application = makeReviewableApplication($this->dealer, $this->dealerUser);

    $result = app(AssignReviewer::class)->handle(
        $application,
        $this->customerAdmin,
        $this->customerAdmin,
    );

    expect($result->assigned_reviewer_id)->toBe($this->customerAdmin->id);
});

it('clears the covering annotation when the primary reviewer later takes over from a cover', function (): void {
    $this->dealer->update(['primary_reviewer_user_id' => $this->primary->id]);

    $application = makeReviewableApplication($this->dealer, $this->dealerUser);

    // Jacob covers first, then Thandi comes back from leave and takes
    // the application back onto her own queue.
    app(AssignReviewer::class)->handle($application, $this->coverer, $this->coverer);
    app(AssignReviewer::class)->handle($application->refresh(), $this->primary, $this->primary);

    $audit = AuditEvent::query()
        ->where('action', 'application.reviewer_assigned')
        ->where('subject_id', $application->id)
        ->latest('id')
        ->first();

    expect($audit->summary)->toBe('Reviewer assigned.')
        ->and($audit->after['covering_for_user_id'])->toBeNull();
});

it('allows the licensing company to nominate the primary reviewer on the ClientAccount model', function (): void {
    $this->dealer->update(['primary_reviewer_user_id' => $this->primary->id]);

    expect($this->dealer->refresh()->primaryReviewer?->id)->toBe($this->primary->id);

    $this->dealer->update(['primary_reviewer_user_id' => null]);

    expect($this->dealer->refresh()->primaryReviewer)->toBeNull();
});
