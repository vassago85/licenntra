<?php

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\FeatureFlags;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

afterEach(function (): void {
    FeatureFlags::swapPaymentTrackingRequired(null);
    FeatureFlags::swapQuotesEnabled(null);
});

/**
 * @return list<string>
 */
function sidebarLinksFor(User $user): array
{
    $html = test()->actingAs($user)->get(route('account.settings'))->assertOk()->getContent();

    preg_match('/<aside\b.*?<\/aside>/s', $html, $aside);
    preg_match_all('/href="([^"]+)"/', $aside[0] ?? '', $hrefs);

    return collect($hrefs[1])
        ->map(fn (string $href): string => html_entity_decode($href))
        ->filter(fn (string $href): bool => str_starts_with($href, url('/')))
        ->unique()
        ->values()
        ->all();
}

function sidebarUser(string $role, ?string $accountType = null): User
{
    $account = $accountType === null ? null : ClientAccount::query()->create([
        'name' => 'Sidebar '.$accountType.' '.uniqid(),
        'type' => $accountType,
        'status' => 'active',
    ]);

    $user = User::factory()->create(['is_active' => true, 'client_account_id' => $account?->id]);
    $user->assignRole($role);

    return $user;
}

dataset('sidebar roles', [
    'owner' => ['owner', null],
    'reviewer' => ['reviewer', null],
    'finance' => ['finance', null],
    'developer' => ['developer', null],
    'dealer admin' => ['customer_admin', 'dealer'],
    'dealer user' => ['customer_user', 'dealer'],
    'fleet admin' => ['customer_admin', 'fleet_operator'],
]);

it('only shows sidebar links the user can open', function (string $role, ?string $accountType, bool $featuresOn): void {
    FeatureFlags::swapPaymentTrackingRequired($featuresOn);
    FeatureFlags::swapQuotesEnabled($featuresOn);

    $user = sidebarUser($role, $accountType);
    $links = sidebarLinksFor($user);

    expect($links)->not->toBeEmpty();

    foreach ($links as $link) {
        $status = $this->actingAs($user)->get($link)->getStatusCode();

        expect($status)->toBe(200, "{$role} sees {$link} in the sidebar but gets {$status}");
    }
})->with('sidebar roles')->with(['features on' => true, 'features off' => false]);
