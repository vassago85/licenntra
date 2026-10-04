<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('renders the login page without the demo panel when demo mode is off', function (): void {
    config(['demo.show_credentials' => false]);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Sign in')
        ->assertDontSee('Demo accounts')
        ->assertDontSee('super.admin@licentra.test');
});

it('renders every seeded persona on the login page when demo mode is on', function (): void {
    config(['demo.show_credentials' => true]);

    $response = $this->get(route('login'));

    $response
        ->assertOk()
        ->assertSee('Demo accounts')
        ->assertSee('super.admin@licentra.test')
        ->assertSee('customer.admin@licentra.test')
        ->assertSee('reviewer@licentra.test')
        ->assertSee('finance@licentra.test')
        ->assertSee('auditor@licentra.test')
        ->assertSee('thandi.mokoena@highveld.test')
        ->assertSee('johan.botha@highveld.test')
        ->assertSee('Highveld Commercial Centurion');
});
