<?php

use App\Enums\ApplicationStage;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Livewire\Portal\ApplicationShow;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\StageHistory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|------------------------------------------------------------------------
| The dealer-facing "Requested fixes" panel on /applications/{id} used to
| claim "All feedback from operations is addressed" the moment it had no
| outstanding document fixes - even if the reviewer never wrote any
| feedback. That let a dealer resubmit without changing anything.
|
| The panel must now distinguish three states:
|   1. Outstanding document fixes   - list them, no resubmit button.
|   2. No outstanding + evidence of past reviewer feedback
|                                   - "feedback addressed", resubmit.
|   3. No outstanding + no evidence - "no specific fixes recorded",
|                                     offer draft/note instead of
|                                     resubmit.
|------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->dealer = ClientAccount::query()->create([
        'name' => 'Highveld Commercial',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->admin = User::factory()->create([
        'is_active' => true,
        'client_account_id' => $this->dealer->id,
    ]);
    $this->admin->assignRole('customer_admin');
});

function makeChangesRequestedApplication(ClientAccount $dealer): Application
{
    return Application::query()->create([
        'reference' => 'LIC-CR-'.random_int(10000, 99999),
        'client_account_id' => $dealer->id,
        'stage' => ApplicationStage::ChangesRequested,
        'request_type' => RequestType::LicenceRenewal,
        'vehicle_category' => VehicleCategory::Passenger,
    ]);
}

it('shows "no specific fixes recorded" when there is no outstanding and no reviewer evidence', function (): void {
    $application = makeChangesRequestedApplication($this->dealer);

    $this->actingAs($this->admin);

    Livewire::test(ApplicationShow::class, ['application' => $application])
        ->assertSee('No specific fixes have been recorded')
        ->assertDontSee('All feedback from operations is addressed')
        ->assertDontSee('Send back for review');
});

it('shows "feedback addressed" + resubmit when there is no outstanding but a stage history reason exists', function (): void {
    $application = makeChangesRequestedApplication($this->dealer);

    // Emulate a reviewer having moved the application to ChangesRequested
    // and recorded a reason. That is the server-side evidence we trust.
    StageHistory::query()->create([
        'application_id' => $application->id,
        'from_stage' => ApplicationStage::DocumentReview,
        'to_stage' => ApplicationStage::ChangesRequested,
        'user_id' => null,
        'reason' => 'Supporting ID copy is unclear - please re-upload.',
    ]);

    $this->actingAs($this->admin);

    Livewire::test(ApplicationShow::class, ['application' => $application])
        ->assertSee('All feedback from operations is addressed')
        ->assertSee('Send back for review')
        ->assertDontSee('No specific fixes have been recorded');
});

it('shows "feedback addressed" when the reviewer left a client-visible note instead of a stage reason', function (): void {
    $application = makeChangesRequestedApplication($this->dealer);

    $application->notes()->create([
        'body' => 'Please re-upload the ID copy, it is unreadable.',
        'visibility' => 'client',
    ]);

    $this->actingAs($this->admin);

    Livewire::test(ApplicationShow::class, ['application' => $application])
        ->assertSee('All feedback from operations is addressed')
        ->assertSee('Send back for review');
});
