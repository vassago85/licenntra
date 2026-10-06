<?php

use App\Enums\ApplicationStage;
use App\Enums\DocumentStatus;
use App\Livewire\Portal\Admin\Overview;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditEvent;
use App\Models\ClientAccount;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\OperationsWorkloadService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

function overviewUser(string $role, ?int $clientAccountId = null): User
{
    $user = User::factory()->create(['is_active' => true, 'client_account_id' => $clientAccountId]);
    $user->assignRole($role);

    return $user;
}

it('shows operations and money panels to finance and the owner', function (string $role): void {
    $this->actingAs(overviewUser($role))
        ->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('Outstanding tasks')
        ->assertSee('Dealerships needing action')
        ->assertSee('Received this month')
        ->assertSee('Top customers');
})->with(['owner', 'finance']);

it('shows a reviewer the operations panels without the money', function (): void {
    $this->actingAs(overviewUser('reviewer'))
        ->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('Outstanding tasks')
        ->assertSee('Recent activity')
        ->assertDontSee('Received this month')
        ->assertDontSee('Top customers');
});

it('lists recent audit activity', function (): void {
    $owner = overviewUser('owner');
    $account = ClientAccount::query()->create(['name' => 'Highveld', 'type' => 'dealer']);

    AuditEvent::query()->create([
        'actor_user_id' => $owner->id,
        'subject_type' => ClientAccount::class,
        'subject_id' => $account->id,
        'action' => 'client_account.updated',
        'summary' => 'Markup changed to 2.5%.',
        'occurred_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('Markup changed to 2.5%.');
});

it('sends the platform developer to platform billing', function (): void {
    $this->actingAs(overviewUser('developer'))
        ->get(route('admin.overview'))
        ->assertRedirect(route('platform.billing'));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function worklistApplication(ClientAccount $account, ApplicationStage $stage, array $attributes = []): Application
{
    return Application::query()->create(array_merge([
        'reference' => 'WL-'.uniqid(),
        'client_account_id' => $account->id,
        'stage' => $stage,
        'province' => 'gauteng',
    ], $attributes));
}

function addUploadedDocument(Application $application, string $name): void
{
    $type = DocumentType::query()->create(['code' => 'wl_'.uniqid(), 'name' => $name, 'is_identity_document' => false]);

    ApplicationDocument::query()->create([
        'application_id' => $application->id,
        'document_type_id' => $type->id,
        'party_role' => 'owner',
        'required' => true,
        'status' => DocumentStatus::Uploaded,
    ]);
}

it('lists the staff tasks under the counters with past-warning work first', function (): void {
    $owner = overviewUser('owner');
    $reviewer = overviewUser('reviewer');
    $reviewer->update(['name' => 'Sipho Ndlovu']);
    $account = ClientAccount::query()->create(['name' => 'Highveld Commercial', 'type' => 'dealer']);
    $submitter = overviewUser('customer_admin', $account->id);
    $submitter->update(['name' => 'Johan Botha']);

    $review = worklistApplication($account, ApplicationStage::DocumentReview, [
        'submitted_by_id' => $submitter->id,
        'assigned_reviewer_id' => $reviewer->id,
        'due_at' => now()->addDays(3),
    ]);
    addUploadedDocument($review, 'Proof of address');

    $handover = worklistApplication($account, ApplicationStage::ReadyForCollection, [
        'submitted_by_id' => $submitter->id,
        'due_at' => now()->subDay(),
        'authority_returned_at' => now()->subDays(4),
    ]);

    worklistApplication($account, ApplicationStage::SubmittedToAuthority, ['due_at' => now()->addDays(5)]);
    worklistApplication($account, ApplicationStage::ChangesRequested);

    $component = Livewire::actingAs($owner)->test(Overview::class);

    $worklist = $component->viewData('worklist');
    expect($worklist->pluck('task_key')->all())->toBe(['handover:'.$handover->id, 'document:'.$review->documents()->value('id')])
        ->and($worklist->first()['urgency'])->toBe(OperationsWorkloadService::URGENCY_OVERDUE)
        ->and($worklist->first()['days_waiting'])->toBe(4);

    $counters = collect(app(OperationsWorkloadService::class)->counters())->keyBy('key');
    expect($component->viewData('worklistTotal'))->toBe(
        $counters['outstanding']['count'] + $counters['submission_packs']['count'] + $counters['returned_handover']['count'],
    );

    $component->assertSeeInOrder(['Worklist', 'Arrange customer handover', 'Review proof of address', 'Dealerships needing action'])
        ->assertSee('Johan Botha')
        ->assertSee('Sipho Ndlovu')
        ->assertSee('Unassigned')
        ->assertSee('Past warning time')
        ->assertSee('4 days');
});

it('lists a review with every document decided as a staff decision, matching the review queue', function (): void {
    $account = ClientAccount::query()->create(['name' => 'Highveld Commercial', 'type' => 'dealer']);

    $accepted = worklistApplication($account, ApplicationStage::DocumentReview);
    $type = DocumentType::query()->create(['code' => 'wl_poa', 'name' => 'Proof of address', 'is_identity_document' => false]);
    ApplicationDocument::query()->create([
        'application_id' => $accepted->id,
        'document_type_id' => $type->id,
        'party_role' => 'owner',
        'required' => true,
        'status' => DocumentStatus::Accepted,
    ]);

    $rejected = worklistApplication($account, ApplicationStage::Submitted);
    ApplicationDocument::query()->create([
        'application_id' => $rejected->id,
        'document_type_id' => $type->id,
        'party_role' => 'owner',
        'required' => true,
        'status' => DocumentStatus::Rejected,
    ]);

    $pending = worklistApplication($account, ApplicationStage::DocumentReview);
    addUploadedDocument($pending, 'Roadworthy certificate');

    $component = Livewire::actingAs(overviewUser('reviewer'))->test(Overview::class);

    expect($component->viewData('worklist')->pluck('task_key')->sort()->values()->all())->toBe(collect([
        'case:'.$accepted->id,
        'case:'.$rejected->id,
        'document:'.$pending->documents()->value('id'),
    ])->sort()->values()->all());

    $component->assertSee('Finish review and bill')
        ->assertSee('Send back for corrections')
        ->assertSee('1 required document rejected or missing.');

    $outstanding = collect(app(OperationsWorkloadService::class)->counters())->firstWhere('key', 'outstanding');
    expect($outstanding['count'])->toBe(3);
});

it('lists a paid application blocked from the department with its blockers', function (): void {
    $account = ClientAccount::query()->create(['name' => 'Highveld Commercial', 'type' => 'dealer']);
    $application = worklistApplication($account, ApplicationStage::PaymentVerified, [
        'vehicle_category' => 'passenger',
        'request_type' => 'new_registration',
    ]);

    Livewire::actingAs(overviewUser('reviewer'))->test(Overview::class)
        ->assertSee('Get ready for the department')
        ->assertSee('Payment not verified or billed yet.')
        ->assertViewHas('worklist', fn ($rows): bool => $rows->pluck('task_key')->all() === ['case:'.$application->id]);
});

it('narrows the worklist to tasks assigned to the signed-in staff member', function (): void {
    $reviewer = overviewUser('reviewer');
    $colleague = overviewUser('reviewer');
    $account = ClientAccount::query()->create(['name' => 'Highveld Commercial', 'type' => 'dealer']);

    $mine = worklistApplication($account, ApplicationStage::DocumentReview, ['assigned_reviewer_id' => $reviewer->id]);
    addUploadedDocument($mine, 'Proof of address');
    $theirs = worklistApplication($account, ApplicationStage::DocumentReview, ['assigned_reviewer_id' => $colleague->id]);
    addUploadedDocument($theirs, 'Roadworthy certificate');

    Livewire::actingAs($reviewer)->test(Overview::class)
        ->assertSee('Review roadworthy certificate')
        ->set('mineOnly', true)
        ->assertSee('Review proof of address')
        ->assertDontSee('Review roadworthy certificate')
        ->assertSet('mineOnly', true);
});

it('shows fifteen worklist rows and loads more on request', function (): void {
    $account = ClientAccount::query()->create(['name' => 'Highveld Commercial', 'type' => 'dealer']);

    foreach (range(1, 17) as $index) {
        worklistApplication($account, ApplicationStage::ReadyForCollection);
    }

    Livewire::actingAs(overviewUser('owner'))->test(Overview::class)
        ->assertViewHas('worklist', fn ($rows): bool => $rows->count() === 15)
        ->assertSee('Show more (2 more)')
        ->call('showMoreWork')
        ->assertViewHas('worklist', fn ($rows): bool => $rows->count() === 17)
        ->assertDontSee('Show more');
});

it('forbids dealer logins', function (): void {
    $account = ClientAccount::query()->create(['name' => 'Highveld', 'type' => 'dealer']);

    $this->actingAs(overviewUser('customer_admin', $account->id))
        ->get(route('admin.overview'))
        ->assertForbidden();
});
