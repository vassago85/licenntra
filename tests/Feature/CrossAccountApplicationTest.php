<?php

use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\DocumentRuleSeeder;

it('does not show another client account application', function () {
    $this->seed(DocumentRuleSeeder::class);

    $ownAccount = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
    ]);
    $otherAccount = ClientAccount::query()->create([
        'name' => 'Kestrel Logistics',
        'type' => 'fleet_operator',
    ]);

    $own = Application::query()->create([
        'reference' => 'LIC-OWN-1',
        'client_account_id' => $ownAccount->id,
        'stage' => 'draft',
    ]);
    $other = Application::query()->create([
        'reference' => 'LIC-OTHER-1',
        'client_account_id' => $otherAccount->id,
        'stage' => 'document_review',
    ]);

    $user = User::factory()->create([
        'client_account_id' => $ownAccount->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('applications.show', $other))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('applications.show', $own))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('review.show', $own))
        ->assertForbidden();
});
