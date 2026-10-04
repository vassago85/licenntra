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
 * DB used to throw "There is no role named `super_admin` for guard
 * `web`" because syncRoles was called before the roles existed.
 */
it('runs standalone on an empty database and seats all seven demo users with their roles', function (): void {
    $this->seed(DemoSeeder::class);

    $expected = [
        'super.admin@licentra.test' => 'super_admin',
        'customer.admin@licentra.test' => 'customer_admin',
        'reviewer@licentra.test' => 'reviewer',
        'finance@licentra.test' => 'finance',
        'auditor@licentra.test' => 'auditor',
        'thandi.mokoena@highveld.test' => 'client_admin',
        'johan.botha@highveld.test' => 'client_user',
    ];

    foreach ($expected as $email => $role) {
        $user = User::query()->where('email', $email)->first();
        expect($user)->not->toBeNull("Expected seeded user {$email} to exist");
        expect($user->getRoleNames()->all())->toBe([$role]);
        expect(Hash::check('password', $user->password))->toBeTrue("Expected {$email} to use the demo 'password'");
    }
});
