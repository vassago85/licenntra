<?php

use App\Enums\ApplicationStage;
use App\Livewire\Portal\ReviewQueue;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->reviewer = User::factory()->create(['is_active' => true]);
    $this->reviewer->assignRole('reviewer');

    $this->dealer = ClientAccount::query()->create(['name' => 'Highveld', 'type' => 'dealer']);
    $this->otherDealer = ClientAccount::query()->create(['name' => 'Kestrel', 'type' => 'fleet_operator']);
});

function queueApp(ClientAccount $account, ApplicationStage $stage, string $reference, array $overrides = []): Application
{
    return Application::query()->create(array_merge([
        'reference' => $reference,
        'client_account_id' => $account->id,
        'stage' => $stage,
    ], $overrides));
}

it('shows only work waiting for review by default and hides finished applications', function (): void {
    queueApp($this->dealer, ApplicationStage::Submitted, 'RQ-SUBMITTED');
    queueApp($this->dealer, ApplicationStage::DocumentReview, 'RQ-REVIEW');
    queueApp($this->dealer, ApplicationStage::ChangesRequested, 'RQ-CHANGES');
    queueApp($this->dealer, ApplicationStage::Completed, 'RQ-COMPLETED');
    queueApp($this->dealer, ApplicationStage::Draft, 'RQ-DRAFT');

    Livewire::actingAs($this->reviewer)
        ->test(ReviewQueue::class)
        ->assertSee('RQ-SUBMITTED')
        ->assertSee('RQ-REVIEW')
        ->assertDontSee('RQ-CHANGES')
        ->assertDontSee('RQ-COMPLETED')
        ->assertDontSee('RQ-DRAFT');
});

it('moves finished applications to the Done tab and client-owned work to With client', function (): void {
    queueApp($this->dealer, ApplicationStage::Submitted, 'RQ-SUBMITTED');
    queueApp($this->dealer, ApplicationStage::QuoteSent, 'RQ-QUOTE');
    queueApp($this->dealer, ApplicationStage::Cancelled, 'RQ-CANCELLED');
    queueApp($this->dealer, ApplicationStage::SubmittedToAuthority, 'RQ-AUTHORITY');

    $component = Livewire::actingAs($this->reviewer)->test(ReviewQueue::class);

    $component->call('showTab', 'client')
        ->assertSee('RQ-QUOTE')
        ->assertDontSee('RQ-SUBMITTED')
        ->assertDontSee('RQ-CANCELLED');

    $component->call('showTab', 'progress')
        ->assertSee('RQ-AUTHORITY')
        ->assertDontSee('RQ-QUOTE');

    $component->call('showTab', 'done')
        ->assertSee('RQ-CANCELLED')
        ->assertDontSee('RQ-AUTHORITY');
});

it('lists the oldest waiting application first', function (): void {
    $newer = queueApp($this->dealer, ApplicationStage::Submitted, 'RQ-NEWER');
    $older = queueApp($this->dealer, ApplicationStage::Submitted, 'RQ-OLDER');

    Application::query()->whereKey($older->id)->update(['updated_at' => now()->subDays(5)]);
    Application::query()->whereKey($newer->id)->update(['updated_at' => now()->subHour()]);

    Livewire::actingAs($this->reviewer)
        ->test(ReviewQueue::class)
        ->assertSeeInOrder(['RQ-OLDER', 'RQ-NEWER']);
});

it('paginates instead of rendering one long list', function (): void {
    foreach (range(1, 25) as $i) {
        queueApp($this->dealer, ApplicationStage::Submitted, sprintf('RQ-PAGE-%03d', $i));
    }

    $rows = Livewire::actingAs($this->reviewer)->test(ReviewQueue::class)->viewData('rows');

    expect($rows->count())->toBe(20)
        ->and($rows->total())->toBe(25);
});

it('filters by dealer and searches by dealer name', function (): void {
    queueApp($this->dealer, ApplicationStage::Submitted, 'RQ-HIGHVELD');
    queueApp($this->otherDealer, ApplicationStage::Submitted, 'RQ-KESTREL');

    Livewire::actingAs($this->reviewer)
        ->test(ReviewQueue::class)
        ->set('accountId', (string) $this->otherDealer->id)
        ->assertSee('RQ-KESTREL')
        ->assertDontSee('RQ-HIGHVELD')
        ->set('accountId', '')
        ->set('search', 'Highveld')
        ->assertSee('RQ-HIGHVELD')
        ->assertDontSee('RQ-KESTREL');
});

it('lets a reviewer take an unassigned application in one click and audits it', function (): void {
    $application = queueApp($this->dealer, ApplicationStage::Submitted, 'RQ-TAKE');

    Livewire::actingAs($this->reviewer)
        ->test(ReviewQueue::class)
        ->assertSee('Take it')
        ->call('take', $application->id)
        ->assertSee('RQ-TAKE is now assigned to you.');

    expect($application->refresh()->assigned_reviewer_id)->toBe($this->reviewer->id)
        ->and(AuditEvent::query()->where('action', 'application.reviewer_assigned')->exists())->toBeTrue();
});

it('refuses the take action to finance', function (): void {
    $finance = User::factory()->create(['is_active' => true]);
    $finance->assignRole('finance');
    $application = queueApp($this->dealer, ApplicationStage::Submitted, 'RQ-FIN');

    Livewire::actingAs($finance)
        ->test(ReviewQueue::class)
        ->assertDontSee('Take it')
        ->call('take', $application->id)
        ->assertForbidden();

    expect($application->refresh()->assigned_reviewer_id)->toBeNull();
});

it('narrows to my work when the Assigned to me card is clicked', function (): void {
    queueApp($this->dealer, ApplicationStage::Submitted, 'RQ-MINE', ['assigned_reviewer_id' => $this->reviewer->id]);
    queueApp($this->dealer, ApplicationStage::Submitted, 'RQ-NOT-MINE');

    Livewire::actingAs($this->reviewer)
        ->test(ReviewQueue::class)
        ->call('showMine')
        ->assertSee('RQ-MINE')
        ->assertDontSee('RQ-NOT-MINE');
});
