<?php

use App\Enums\ApplicationStage;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Livewire\Portal\Admin\AuditLog;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|------------------------------------------------------------------------
| The former Filament "Audit log" resource now lives in the portal shell
| at /audit. Read-only; visible to the owner only. Reviewers and finance
| should get 403.
|------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->owner = User::factory()->create(['is_active' => true]);
    $this->owner->assignRole('owner');

    $this->reviewer = User::factory()->create(['is_active' => true]);
    $this->reviewer->assignRole('reviewer');

    $this->finance = User::factory()->create(['is_active' => true]);
    $this->finance->assignRole('finance');

    $this->dealer = ClientAccount::query()->create([
        'name' => 'Highveld',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);
});

function makeAuditEvent(array $overrides = []): AuditEvent
{
    return AuditEvent::query()->create(array_merge([
        'occurred_at' => Carbon::now(),
        'actor_user_id' => null,
        'actor_role' => null,
        'ip' => null,
        'user_agent' => null,
        'subject_type' => 'App\\Models\\Application',
        'subject_id' => 1,
        'action' => 'application.stage_changed',
        'summary' => 'Stage Draft to Submitted.',
        'before' => null,
        'after' => null,
        'is_system' => false,
    ], $overrides));
}

/*
|------------------------------------------------------------------------
| Role gates
|------------------------------------------------------------------------
*/

it('lets the owner reach the audit log', function (): void {
    $this->actingAs($this->owner)
        ->get(route('audit.index'))
        ->assertOk();
});

it('forbids a reviewer from the audit log', function (): void {
    $this->actingAs($this->reviewer)
        ->get(route('audit.index'))
        ->assertForbidden();
});

it('forbids a finance user from the audit log', function (): void {
    $this->actingAs($this->finance)
        ->get(route('audit.index'))
        ->assertForbidden();
});

it('redirects a guest from the audit log to login', function (): void {
    $this->get(route('audit.index'))->assertRedirect(route('login'));
});

/*
|------------------------------------------------------------------------
| Table + filters
|------------------------------------------------------------------------
*/

it('renders audit events newest first', function (): void {
    makeAuditEvent([
        'occurred_at' => Carbon::parse('2026-01-01 09:00:00'),
        'summary' => 'Oldest entry.',
    ]);
    makeAuditEvent([
        'occurred_at' => Carbon::parse('2026-06-01 09:00:00'),
        'summary' => 'Newest entry.',
    ]);

    $this->actingAs($this->owner);

    Livewire::test(AuditLog::class)
        ->assertSeeInOrder(['Newest entry.', 'Oldest entry.']);
});

it('filters by action', function (): void {
    makeAuditEvent(['action' => 'application.stage_changed', 'summary' => 'Stage change entry.']);
    makeAuditEvent(['action' => 'document.rejected', 'summary' => 'Rejection entry.']);

    $this->actingAs($this->owner);

    Livewire::test(AuditLog::class)
        ->set('actionFilter', 'document.rejected')
        ->assertSee('Rejection entry.')
        ->assertDontSee('Stage change entry.');
});

it('filters by actor role', function (): void {
    makeAuditEvent(['actor_role' => 'reviewer', 'summary' => 'Reviewer did something.']);
    makeAuditEvent(['actor_role' => 'finance', 'summary' => 'Finance did something.']);

    $this->actingAs($this->owner);

    Livewire::test(AuditLog::class)
        ->set('actorRoleFilter', 'finance')
        ->assertSee('Finance did something.')
        ->assertDontSee('Reviewer did something.');
});

it('hides system entries when the include-system toggle is off', function (): void {
    makeAuditEvent(['is_system' => true, 'summary' => 'System housekeeping.']);
    makeAuditEvent(['is_system' => false, 'summary' => 'User action.']);

    $this->actingAs($this->owner);

    Livewire::test(AuditLog::class)
        ->set('includeSystem', false)
        ->assertSee('User action.')
        ->assertDontSee('System housekeeping.');
});

it('searches actor name and summary', function (): void {
    $alice = User::factory()->create(['name' => 'Alice Kgomo', 'is_active' => true]);
    $alice->assignRole('reviewer');

    makeAuditEvent(['actor_user_id' => $alice->id, 'summary' => 'Approved an invoice.']);
    makeAuditEvent(['summary' => 'Dispatched a notification.']);

    $this->actingAs($this->owner);

    Livewire::test(AuditLog::class)
        ->set('search', 'Alice')
        ->assertSee('Approved an invoice.')
        ->assertDontSee('Dispatched a notification.');
});

/*
|------------------------------------------------------------------------
| Subject rendering
|------------------------------------------------------------------------
*/

it('renders a link to the application for Application-subject events using the public reference', function (): void {
    $application = Application::query()->create([
        'reference' => 'LIC-AUD-00042',
        'client_account_id' => $this->dealer->id,
        'stage' => ApplicationStage::Draft,
        'request_type' => RequestType::LicenceRenewal,
        'vehicle_category' => VehicleCategory::Passenger,
    ]);

    makeAuditEvent([
        'subject_type' => Application::class,
        'subject_id' => $application->id,
        'summary' => 'Application audit entry.',
    ]);

    $this->actingAs($this->owner);

    Livewire::test(AuditLog::class)
        ->assertSee('LIC-AUD-00042')
        ->assertSee(route('applications.show', $application));
});

it('falls back to a generic class #id label for non-Application subjects', function (): void {
    makeAuditEvent([
        'subject_type' => 'App\\Models\\Payment',
        'subject_id' => 7,
        'summary' => 'Payment audit entry.',
    ]);

    $this->actingAs($this->owner);

    Livewire::test(AuditLog::class)
        ->assertSee('Payment #7');
});
