<?php

use App\Livewire\Portal\Admin\ClientAccounts;
use App\Livewire\Portal\Admin\DocumentRules;
use App\Livewire\Portal\Admin\DocumentTypes;
use App\Livewire\Portal\Admin\FeeLines;
use App\Livewire\Portal\Admin\FeeTables;
use App\Livewire\Portal\Admin\FeeTableVersionEditor;
use App\Livewire\Portal\Admin\FeeTableVersions;
use App\Livewire\Portal\Admin\Users;
use App\Models\ClientAccount;
use App\Models\DocumentRule;
use App\Models\DocumentType;
use App\Models\FeeLine;
use App\Models\FeeTable;
use App\Models\FeeTableVersion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->owner = adminPageUser('owner');
    $this->dealer = ClientAccount::query()->create(['name' => 'Highveld', 'type' => 'dealer']);
});

function adminPageUser(string $role, ?int $clientAccountId = null): User
{
    $user = User::factory()->create(['is_active' => true, 'client_account_id' => $clientAccountId]);
    $user->assignRole($role);

    return $user;
}

/**
 * @return array{0: FeeTable, 1: FeeTableVersion}
 */
function liveFeeTable(): array
{
    $table = FeeTable::query()->create(['province' => 'gauteng', 'name' => 'Gauteng tariffs']);
    $version = $table->versions()->create(['version' => 1, 'status' => 'active', 'approved_at' => now()]);
    $version->lines()->create(['code' => 'licence', 'label' => 'Licence fee', 'amount_cents' => 50000, 'tax_treatment' => 'exempt', 'period' => 'annual', 'client_visible' => true, 'sort_order' => 10]);
    $version->lines()->create(['code' => 'admin', 'label' => 'Admin fee', 'amount_cents' => 25000, 'tax_treatment' => 'standard', 'period' => 'once_off', 'client_visible' => true, 'sort_order' => 20]);

    return [$table, $version];
}

it('serves every configuration page to the owner inside the portal shell', function (string $routeName): void {
    $this->actingAs($this->owner)
        ->get(route($routeName))
        ->assertOk()
        ->assertSee('Powered by Licentra');
})->with([
    'admin.users',
    'admin.client-accounts',
    'admin.document-rules',
    'admin.document-types',
    'admin.fee-tables',
    'admin.fee-table-versions',
    'admin.fee-lines',
]);

it('forbids configuration pages to reviewers, finance and dealer logins', function (string $routeName): void {
    foreach ([adminPageUser('reviewer'), adminPageUser('finance'), adminPageUser('customer_admin', $this->dealer->id)] as $user) {
        $this->actingAs($user)->get(route($routeName))->assertForbidden();
    }
})->with([
    'admin.users',
    'admin.client-accounts',
    'admin.document-rules',
    'admin.fee-table-versions',
    'admin.fee-lines',
]);

it('creates a staff user and a dealer login, requiring an account for the dealer', function (): void {
    Livewire::actingAs($this->owner)
        ->test(Users::class)
        ->call('create')
        ->set('name', 'Nadia Reviewer')
        ->set('email', 'nadia@licentra.test')
        ->set('password', 'secret-password')
        ->set('role', 'reviewer')
        ->call('save')
        ->assertHasNoErrors()
        ->call('create')
        ->set('name', 'Dealer Dan')
        ->set('email', 'dan@highveld.test')
        ->set('password', 'secret-password')
        ->set('role', 'customer_user')
        ->call('save')
        ->assertHasErrors(['clientAccountId'])
        ->set('clientAccountId', (string) $this->dealer->id)
        ->call('save')
        ->assertHasNoErrors();

    $reviewer = User::query()->where('email', 'nadia@licentra.test')->firstOrFail();
    $dealerUser = User::query()->where('email', 'dan@highveld.test')->firstOrFail();

    expect($reviewer->hasRole('reviewer'))->toBeTrue()
        ->and($reviewer->client_account_id)->toBeNull()
        ->and($dealerUser->hasRole('customer_user'))->toBeTrue()
        ->and($dealerUser->client_account_id)->toBe($this->dealer->id);
});

it('refuses to demote or deactivate the last active owner', function (): void {
    $component = Livewire::actingAs($this->owner)
        ->test(Users::class)
        ->call('edit', $this->owner->id)
        ->set('role', 'reviewer')
        ->call('save')
        ->assertHasErrors(['role']);

    expect($this->owner->fresh()->hasRole('owner'))->toBeTrue();

    $secondOwner = adminPageUser('owner');
    $component->call('deactivate', $secondOwner->id)->assertSet('errorMessage', null);

    expect($secondOwner->fresh()->is_active)->toBeFalse();
});

it('offboards a staff member from the users page', function (): void {
    $finance = adminPageUser('finance');

    Livewire::actingAs($this->owner)
        ->test(Users::class)
        ->call('startOffboarding', $finance->id)
        ->call('confirmOffboarding')
        ->assertHasErrors(['offboardReason'])
        ->set('offboardReason', 'resigned')
        ->call('confirmOffboarding')
        ->assertHasNoErrors();

    $finance->refresh();

    expect($finance->isOffboarded())->toBeTrue()
        ->and($finance->is_active)->toBeFalse();
});

it('hides developer accounts from the users page', function (): void {
    $developer = adminPageUser('developer');

    Livewire::actingAs($this->owner)
        ->test(Users::class)
        ->assertDontSee($developer->email)
        ->call('edit', $developer->id)
        ->assertNotFound();
});

it('creates a statement account with terms and drops terms when switched to pay per transaction', function (): void {
    $component = Livewire::actingAs($this->owner)
        ->test(ClientAccounts::class)
        ->call('create')
        ->set('name', 'Kestrel Fleet')
        ->set('type', 'fleet_operator')
        ->set('additionalTypes', ['dealer', 'fleet_operator'])
        ->set('billingMode', 'account_statement')
        ->set('paymentTermsDays', '30')
        ->set('creditLimitRands', '15000.50')
        ->set('markupBasisPoints', '250')
        ->call('save')
        ->assertHasNoErrors();

    $account = ClientAccount::query()->where('name', 'Kestrel Fleet')->firstOrFail();

    expect($account->additional_types)->toBe(['dealer'])
        ->and($account->payment_terms_days)->toBe(30)
        ->and($account->credit_limit_cents)->toBe(1500050)
        ->and($account->markup_basis_points)->toBe(250);

    $component->call('edit', $account->id)
        ->set('billingMode', 'pay_per_transaction')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->payment_terms_days)->toBeNull()
        ->and($account->fresh()->credit_limit_cents)->toBeNull();
});

it('will not delete a client account that has users', function (): void {
    adminPageUser('customer_admin', $this->dealer->id);

    Livewire::actingAs($this->owner)
        ->test(ClientAccounts::class)
        ->call('delete', $this->dealer->id)
        ->assertSet('statusMessage', null);

    expect(ClientAccount::query()->whereKey($this->dealer->id)->exists())->toBeTrue();
});

it('manages document types and rules', function (): void {
    Livewire::actingAs($this->owner)
        ->test(DocumentTypes::class)
        ->call('create')
        ->set('code', 'Bad Code')
        ->set('name', 'Police clearance')
        ->call('save')
        ->assertHasErrors(['code'])
        ->set('code', 'police_clearance')
        ->set('maxAgeDays', '30')
        ->call('save')
        ->assertHasNoErrors();

    $type = DocumentType::query()->where('code', 'police_clearance')->firstOrFail();
    expect($type->max_age_days)->toBe(30);

    $rules = Livewire::actingAs($this->owner)
        ->test(DocumentRules::class)
        ->call('create')
        ->set('documentTypeId', (string) $type->id)
        ->set('requestType', 'change_of_ownership')
        ->set('ownerType', 'business')
        ->set('isFinanced', '1')
        ->set('partyRole', 'owner')
        ->call('save')
        ->assertHasNoErrors();

    $rule = DocumentRule::query()->where('document_type_id', $type->id)->firstOrFail();

    expect($rule->request_type?->value)->toBe('change_of_ownership')
        ->and($rule->owner_type?->value)->toBe('business')
        ->and((bool) $rule->is_financed)->toBeTrue()
        ->and($rule->is_dealer_stock)->toBeNull()
        ->and($rule->active)->toBeTrue();

    $rules->call('toggleActive', $rule->id);
    expect($rule->fresh()->active)->toBeFalse();

    Livewire::actingAs($this->owner)
        ->test(DocumentTypes::class)
        ->call('delete', $type->id)
        ->assertSet('statusMessage', null);
    expect(DocumentType::query()->whereKey($type->id)->exists())->toBeTrue();
});

it('allows only one fee table per province', function (): void {
    FeeTable::query()->create(['province' => 'gauteng', 'name' => 'Gauteng tariffs']);

    Livewire::actingAs($this->owner)
        ->test(FeeTables::class)
        ->call('create')
        ->set('province', 'gauteng')
        ->set('name', 'Duplicate')
        ->call('save')
        ->assertHasErrors(['province']);
});

it('drafts a fee table version from the live lines and needs a second person to approve it', function (): void {
    [$table, $live] = liveFeeTable();

    Livewire::actingAs($this->owner)
        ->test(FeeTableVersions::class)
        ->call('create')
        ->set('feeTableId', (string) $table->id)
        ->call('createDraft')
        ->assertHasNoErrors();

    $draft = $table->versions()->where('status', 'draft')->firstOrFail();

    expect($draft->version)->toBe(2)
        ->and($draft->created_by)->toBe($this->owner->id)
        ->and($draft->lines()->count())->toBe(2);

    Livewire::actingAs($this->owner)
        ->test(FeeTableVersions::class)
        ->call('approve', $draft->id)
        ->assertSet('errorMessage', 'The approver must not be the person who created this version.');

    expect($draft->fresh()->status)->toBe('draft');

    $secondOwner = adminPageUser('owner');

    Livewire::actingAs($secondOwner)
        ->test(FeeTableVersions::class)
        ->call('approve', $draft->id)
        ->assertSet('errorMessage', null);

    expect($draft->fresh()->status)->toBe('active')
        ->and($draft->fresh()->approved_by)->toBe($secondOwner->id)
        ->and($live->fresh()->status)->toBe('superseded');
});

it('edits lines on a draft and keeps live versions read-only', function (): void {
    [$table, $live] = liveFeeTable();
    $draft = $table->versions()->create(['version' => 2, 'status' => 'draft', 'created_by' => $this->owner->id]);

    Livewire::actingAs($this->owner)
        ->test(FeeTableVersionEditor::class, ['version' => $draft])
        ->call('addLine')
        ->set('line.code', 'plates')
        ->set('line.label', 'Number plates')
        ->set('line.amount_rand', '180.50')
        ->set('line.tax_treatment', 'standard')
        ->set('line.period', 'once_off')
        ->set('line.tare_min_kg', '3500')
        ->set('line.tare_max_kg', '1000')
        ->call('saveLine')
        ->assertHasErrors(['line.tare_max_kg'])
        ->set('line.tare_max_kg', '')
        ->call('saveLine')
        ->assertHasNoErrors();

    $line = $draft->lines()->firstOrFail();

    expect($line->amount_cents)->toBe(18050)
        ->and($line->tare_min_kg)->toBe(3500)
        ->and($line->tare_max_kg)->toBeNull();

    Livewire::actingAs($this->owner)
        ->test(FeeTableVersionEditor::class, ['version' => $live])
        ->call('addLine')
        ->assertForbidden();
});

it('corrects draft amounts inline from the fee lines list but not live ones', function (): void {
    [$table, $live] = liveFeeTable();
    $draft = $table->versions()->create(['version' => 2, 'status' => 'draft', 'created_by' => $this->owner->id]);
    $draftLine = $draft->lines()->create(['code' => 'licence', 'label' => 'Licence fee', 'amount_cents' => 50000, 'tax_treatment' => 'exempt', 'period' => 'annual', 'client_visible' => true, 'sort_order' => 10]);
    $liveLine = FeeLine::query()->where('fee_table_version_id', $live->id)->firstOrFail();

    Livewire::actingAs($this->owner)
        ->test(FeeLines::class)
        ->set('statusFilter', 'draft')
        ->assertSee('Licence fee')
        ->call('updateAmount', $draftLine->id, '612.40')
        ->call('toggleVisible', $draftLine->id);

    expect($draftLine->fresh()->amount_cents)->toBe(61240)
        ->and($draftLine->fresh()->client_visible)->toBeFalse();

    Livewire::actingAs($this->owner)
        ->test(FeeLines::class)
        ->call('updateAmount', $liveLine->id, '1.00')
        ->assertForbidden();

    expect($liveLine->fresh()->amount_cents)->toBe(50000);
});
