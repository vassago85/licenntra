<?php

use App\Actions\ResolveRequiredDocuments;
use App\Actions\ReviewDocument;
use App\Actions\SaveApplicationDraft;
use App\Enums\DocumentStatus;
use App\Livewire\Portal\ReviewWorkspace;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\DocumentRuleSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(DocumentRuleSeeder::class);
    foreach (['owner', 'reviewer', 'finance', 'customer_admin', 'customer_user'] as $role) {
        Role::findOrCreate($role);
    }
});

it('stores a dangerous goods request on the application', function () {
    $client = dangerousGoodsUser('customer_user');

    $application = app(SaveApplicationDraft::class)->handle($client, [
        'request_type' => 'new_registration',
        'vehicle_category' => 'commercial',
        'dangerous_goods' => true,
    ]);

    expect($application->dangerous_goods)->toBeTrue();
});

it('refuses a certificate of fitness until it is stamped dangerous goods', function () {
    $application = dangerousGoodsApplication(['dangerous_goods' => true]);
    $reviewer = dangerousGoodsUser('reviewer');
    $cof = dangerousGoodsCof($application);

    expect(fn () => app(ReviewDocument::class)->handle($cof, $reviewer, DocumentStatus::Accepted))
        ->toThrow(ValidationException::class);

    expect($cof->refresh()->status)->not->toBe(DocumentStatus::Accepted)
        ->and($cof->dangerous_goods_stamped)->not->toBeTrue();
});

it('stamps the certificate of fitness when dangerous goods was requested', function () {
    $application = dangerousGoodsApplication(['dangerous_goods' => true]);
    $reviewer = dangerousGoodsUser('reviewer');
    $cof = dangerousGoodsCof($application);

    app(ReviewDocument::class)->handle($cof, $reviewer, DocumentStatus::Accepted, dangerousGoodsStamped: true);

    expect($cof->refresh()->status)->toBe(DocumentStatus::Accepted)
        ->and($cof->dangerous_goods_stamped)->toBeTrue();
});

it('accepts a certificate of fitness without a stamp when dangerous goods was not requested', function () {
    $application = dangerousGoodsApplication(['dangerous_goods' => false]);
    $reviewer = dangerousGoodsUser('reviewer');
    $cof = dangerousGoodsCof($application);

    app(ReviewDocument::class)->handle($cof, $reviewer, DocumentStatus::Accepted);

    expect($cof->refresh()->status)->toBe(DocumentStatus::Accepted)
        ->and($cof->dangerous_goods_stamped)->toBeNull();
});

it('asks the reviewer to confirm the dangerous goods stamp before accepting the certificate', function () {
    $application = dangerousGoodsApplication(['dangerous_goods' => true, 'stage' => 'document_review']);
    $reviewer = dangerousGoodsUser('reviewer');
    $cof = dangerousGoodsCof($application);

    Livewire::actingAs($reviewer)
        ->test(ReviewWorkspace::class, ['application' => $application])
        ->assertSee('Dangerous goods requested')
        ->assertSee('Certificate of fitness is stamped Dangerous goods')
        ->call('acceptDocument', $cof->id)
        ->assertHasErrors(['dangerous_goods_stamped']);

    expect($cof->refresh()->dangerous_goods_stamped)->not->toBeTrue();

    Livewire::actingAs($reviewer)
        ->test(ReviewWorkspace::class, ['application' => $application])
        ->set('dangerousGoodsStamped', true)
        ->call('acceptDocument', $cof->id)
        ->assertHasNoErrors();

    expect($cof->refresh()->status)->toBe(DocumentStatus::Accepted)
        ->and($cof->dangerous_goods_stamped)->toBeTrue();
});

function dangerousGoodsUser(string $role, ?ClientAccount $account = null): User
{
    $account ??= ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'quote_acceptance_allowed' => true,
    ]);

    $user = User::factory()->create([
        'client_account_id' => str_starts_with($role, 'customer') ? $account->id : null,
        'is_active' => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function dangerousGoodsApplication(array $overrides = []): Application
{
    $account = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'markup_basis_points' => 0,
    ]);

    $application = Application::query()->create(array_merge([
        'reference' => 'LIC-'.uniqid(),
        'client_account_id' => $account->id,
        'request_type' => 'new_registration',
        'service_type' => 'register_and_license',
        'vehicle_category' => 'commercial',
        'owner_type' => 'business',
        'province' => 'gauteng',
        'is_financed' => true,
        'stage' => 'draft',
    ], $overrides));

    $application->vehicle()->create([
        'vin' => 'JHHGD8JLA7K104512',
        'vehicle_register_number' => 'TLX914G',
        'make' => 'Hino',
        'model' => '500 1627',
        'tare_kg' => 8420,
    ]);

    app(ResolveRequiredDocuments::class)->handle($application);

    return $application->refresh();
}

function dangerousGoodsCof(Application $application): ApplicationDocument
{
    return $application->documents()
        ->whereHas('documentType', fn ($query) => $query->where('code', 'cof'))
        ->firstOrFail();
}
