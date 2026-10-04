<?php

use App\Enums\IdentificationType;
use App\Models\ClientAccount;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('persists dealership BRN and proxy / representative particulars', function () {
    $account = ClientAccount::query()->create([
        'name' => 'Highveld Commercial',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
        'brn' => '2006/034364/07',
        'proxy_name' => 'Mokoena',
        'proxy_initials' => 'TJ',
        'proxy_id_type' => IdentificationType::RsaId->value,
        'proxy_id_number' => '8503125010089',
        'representative_name' => 'Van der Merwe',
        'representative_initials' => 'PJ',
        'representative_id_type' => IdentificationType::RsaId->value,
        'representative_id_number' => '9201115010087',
    ])->refresh();

    expect($account->brn)->toBe('2006/034364/07')
        ->and($account->proxy_name)->toBe('Mokoena')
        ->and($account->proxy_initials)->toBe('TJ')
        ->and($account->proxy_id_type)->toBe(IdentificationType::RsaId)
        ->and($account->proxy_id_number)->toBe('8503125010089')
        ->and($account->representative_id_type)->toBe(IdentificationType::RsaId)
        ->and($account->representative_id_number)->toBe('9201115010087');
});

it('encrypts proxy and representative ID numbers at rest', function () {
    $account = ClientAccount::query()->create([
        'name' => 'Any Dealer',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
        'proxy_id_number' => '8503125010089',
        'representative_id_number' => '9201115010087',
    ]);

    $raw = DB::table('client_accounts')->where('id', $account->id)->first();

    expect($raw->proxy_id_number)->not->toBe('8503125010089')
        ->and($raw->representative_id_number)->not->toBe('9201115010087')
        ->and($account->refresh()->proxy_id_number)->toBe('8503125010089')
        ->and($account->representative_id_number)->toBe('9201115010087');
});

it('hasDealershipParticulars returns true only when BRN + proxy are complete', function () {
    $account = ClientAccount::query()->create([
        'name' => 'Incomplete Dealer',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    expect($account->hasDealershipParticulars())->toBeFalse();

    $account->update([
        'brn' => '2006/000001/07',
        'proxy_name' => 'Smith',
        'proxy_initials' => 'A',
        'proxy_id_type' => IdentificationType::RsaId->value,
        // proxy_id_number still missing
    ]);

    expect($account->refresh()->hasDealershipParticulars())->toBeFalse();

    $account->update(['proxy_id_number' => '1234567890123']);

    expect($account->refresh()->hasDealershipParticulars())->toBeTrue();
});
