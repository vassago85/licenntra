<?php

use App\Actions\AcceptQuote;
use App\Actions\ChangeServiceType;
use App\Actions\SendQuote;
use App\Actions\TransitionApplication;
use App\Enums\ApplicationStage;
use App\Enums\BillingMode;
use App\Enums\DatafixStatus;
use App\Enums\FeePeriod;
use App\Enums\QuoteStatus;
use App\Enums\RequestType;
use App\Enums\ServiceType;
use App\Enums\TaxTreatment;
use App\Enums\VehicleCategory;
use App\Exceptions\InvalidTransition;
use App\Livewire\Portal\Admin\ClientAccounts;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\FeeTable;
use App\Models\Quote;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\FeatureFlags;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    SystemSetting::query()->updateOrCreate(['id' => 1], ['vat_basis_points' => 1500]);

    $table = FeeTable::query()->create(['province' => 'gauteng', 'name' => 'Gauteng fees']);
    $version = $table->versions()->create(['version' => 1, 'status' => 'active']);
    $version->lines()->create([
        'code' => 'registration', 'label' => 'Registration fee', 'amount_cents' => 50000,
        'client_visible' => true, 'tax_treatment' => TaxTreatment::Exempt->value, 'period' => FeePeriod::OnceOff->value,
    ]);

    $this->reviewer = User::factory()->create(['is_active' => true]);
    $this->reviewer->assignRole('reviewer');
});

function quoteBillingAccount(bool $standingAgreement = false, BillingMode $billingMode = BillingMode::PayPerTransaction): ClientAccount
{
    return ClientAccount::query()->create([
        'name' => 'Quote Billing Dealer '.uniqid(),
        'type' => 'dealer',
        'status' => 'active',
        'billing_mode' => $billingMode->value,
        'quote_acceptance_allowed' => true,
        'has_standing_agreement' => $standingAgreement,
        'markup_basis_points' => 1000,
    ]);
}

function quoteBillingApplication(ClientAccount $account, RequestType $requestType = RequestType::DuplicateDisc): Application
{
    return Application::query()->create([
        'reference' => 'QB-'.uniqid(),
        'client_account_id' => $account->id,
        'stage' => ApplicationStage::DocumentReview,
        'request_type' => $requestType,
        'vehicle_category' => VehicleCategory::Passenger,
        'owner_type' => 'individual',
        'province' => 'gauteng',
        'datafix_status' => DatafixStatus::NotRequired,
    ]);
}

function draftQuoteFor(Application $application, User $author, int $cents = 185000): Quote
{
    $quote = Quote::query()->create([
        'application_id' => $application->id,
        'status' => QuoteStatus::Draft,
        'expires_at' => now()->addDays(14),
        'created_by' => $author->id,
    ]);
    $quote->lines()->create([
        'description' => 'All-in licensing service',
        'client_price_cents' => $cents,
        'internal_cost_cents' => 100000,
    ]);

    return $quote;
}

function dealerUserFor(ClientAccount $account): User
{
    $dealer = User::factory()->create(['is_active' => true, 'client_account_id' => $account->id]);
    $dealer->assignRole('customer_admin');

    return $dealer;
}

it('bills the accepted quote total instead of the fee table', function (): void {
    $account = quoteBillingAccount();
    $application = quoteBillingApplication($account);
    $quote = draftQuoteFor($application, $this->reviewer);

    app(SendQuote::class)->handle($application, $this->reviewer, $quote);
    app(AcceptQuote::class)->handle($application->refresh(), dealerUserFor($account));
    $application = app(TransitionApplication::class)->handle($application->refresh(), ApplicationStage::PaymentPending, $this->reviewer);

    expect($application->fee_snapshot['total_cents'])->toBe(185000)
        ->and($application->fee_snapshot['quote_id'])->toBe($quote->id)
        ->and(array_column($application->fee_snapshot['lines'], 'label'))->toBe(['All-in licensing service']);
});

it('bills the fee table plus account markup when the application skips the quote', function (): void {
    $account = quoteBillingAccount();
    $application = quoteBillingApplication($account);
    draftQuoteFor($application, $this->reviewer);

    $application = app(TransitionApplication::class)->handle($application, ApplicationStage::PaymentPending, $this->reviewer);

    expect($application->fee_snapshot['total_cents'])->toBe(55000 + 750)
        ->and($application->fee_snapshot)->not->toHaveKey('quote_id');
});

it('puts the accepted quote total on the statement for on-account clients', function (): void {
    $account = quoteBillingAccount(billingMode: BillingMode::AccountStatement);
    $application = quoteBillingApplication($account);
    $quote = draftQuoteFor($application, $this->reviewer, 240000);

    app(SendQuote::class)->handle($application, $this->reviewer, $quote);
    app(AcceptQuote::class)->handle($application->refresh(), dealerUserFor($account));
    $application = app(TransitionApplication::class)->handle($application->refresh(), ApplicationStage::PaymentPending, $this->reviewer);

    expect($application->stage)->toBe(ApplicationStage::PaymentVerified)
        ->and($application->payments()->sole()->amount_cents)->toBe(240000);
});

it('keeps the accepted quote price when the service type changes', function (): void {
    $account = quoteBillingAccount();
    $application = quoteBillingApplication($account, RequestType::NewRegistration);
    $quote = draftQuoteFor($application, $this->reviewer);

    app(SendQuote::class)->handle($application, $this->reviewer, $quote);
    app(AcceptQuote::class)->handle($application->refresh(), dealerUserFor($account));
    $application = app(TransitionApplication::class)->handle($application->refresh(), ApplicationStage::PaymentPending, $this->reviewer);
    $application = app(ChangeServiceType::class)->handle($application, $this->reviewer, ServiceType::RegisterOnly, 'Dealer keeps the disc');

    expect($application->fee_snapshot['total_cents'])->toBe(185000);
});

it('accepts a sent quote straight away for a standing-agreement client', function (): void {
    $account = quoteBillingAccount(standingAgreement: true);
    $application = quoteBillingApplication($account);
    $quote = draftQuoteFor($application, $this->reviewer);

    $application = app(SendQuote::class)->handle($application, $this->reviewer, $quote);

    expect($application->stage)->toBe(ApplicationStage::QuoteAccepted)
        ->and($quote->refresh()->status)->toBe(QuoteStatus::Accepted);

    $application = app(TransitionApplication::class)->handle($application, ApplicationStage::PaymentPending, $this->reviewer);

    expect($application->fee_snapshot['total_cents'])->toBe(185000);
});

it('still waits for the dealer to accept when there is no standing agreement', function (): void {
    $account = quoteBillingAccount();
    $application = quoteBillingApplication($account);

    $application = app(SendQuote::class)->handle($application, $this->reviewer, draftQuoteFor($application, $this->reviewer));

    expect($application->stage)->toBe(ApplicationStage::QuoteSent);
});

it('lets a standing-agreement import skip the quote when quotes are on', function (): void {
    FeatureFlags::swapQuotesEnabled(true);

    try {
        $standing = quoteBillingApplication(quoteBillingAccount(standingAgreement: true), RequestType::Import);
        $application = app(TransitionApplication::class)->handle($standing, ApplicationStage::PaymentPending, $this->reviewer);

        expect($application->stage)->toBe(ApplicationStage::PaymentPending);

        $regular = quoteBillingApplication(quoteBillingAccount(), RequestType::Import);

        expect(fn () => app(TransitionApplication::class)->handle($regular, ApplicationStage::PaymentPending, $this->reviewer))
            ->toThrow(InvalidTransition::class, 'Imports and exports need a quote before payment.');
    } finally {
        FeatureFlags::swapQuotesEnabled(null);
    }
});

it('lets the owner mark a client account as having a standing agreement', function (): void {
    $owner = User::factory()->create(['is_active' => true]);
    $owner->assignRole('owner');
    $account = quoteBillingAccount();

    Livewire::actingAs($owner)
        ->test(ClientAccounts::class)
        ->call('edit', $account->id)
        ->assertSet('hasStandingAgreement', false)
        ->set('hasStandingAgreement', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($account->refresh()->has_standing_agreement)->toBeTrue();
});
