<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * Regression: on a prod container the Docker entrypoint runs
 * `db:seed --class=DemoSeeder --force` directly on first boot, which
 * must not require DatabaseSeeder's role/fee prerequisites to have
 * been seeded first. Running DemoSeeder standalone against an empty
 * DB used to throw "There is no role named `owner` for guard `web`"
 * because syncRoles was called before the roles existed.
 */
it('runs standalone on an empty database and seats all demo users with their roles', function (): void {
    $this->seed(DemoSeeder::class);

    $expected = [
        'owner@licentra.test' => 'owner',
        'reviewer@licentra.test' => 'reviewer',
        'finance@licentra.test' => 'finance',
        'thandi.mokoena@highveld.test' => 'customer_admin',
        'johan.botha@highveld.test' => 'customer_user',
    ];

    foreach ($expected as $email => $role) {
        $user = User::query()->where('email', $email)->first();
        expect($user)->not->toBeNull("Expected seeded user {$email} to exist");
        expect($user->getRoleNames()->all())->toBe([$role]);
        expect(Hash::check('password', $user->password))->toBeTrue("Expected {$email} to use the demo 'password'");
    }
});
