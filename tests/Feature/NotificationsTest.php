<?php

use App\Actions\RecordAuthorityReturn;
use App\Actions\SendQuote;
use App\Actions\SubmitToAuthority;
use App\Actions\TransitionApplication;
use App\Actions\VerifyPayment;
use App\Enums\ApplicationStage;
use App\Enums\DatafixStatus;
use App\Enums\DocumentStatus;
use App\Enums\QuoteStatus;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ClientAccount;
use App\Models\DocumentType;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\ApplicationReadyForCollection;
use App\Notifications\AuthoritySubmittedForDealer;
use App\Notifications\ChangesRequestedForDealer;
use App\Notifications\MailgunTestNotification;
use App\Notifications\PaymentVerifiedForDealer;
use App\Notifications\QuoteSentToDealer;
use App\Providers\AppServiceProvider;
use App\Services\NotificationDispatcher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $this->dealership = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'status' => 'active',
        'contact_email' => 'ops@highveld.example',
        'markup_basis_points' => 0,
    ]);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('owner');

    $this->clientAdmin = User::factory()->create([
        'is_active' => true,
        'email' => 'owner@highveld.example',
        'client_account_id' => $this->dealership->id,
    ]);
    $this->clientAdmin->assignRole('customer_admin');
});

function makeReadyApplication(ClientAccount $account, ApplicationStage $stage = ApplicationStage::PaymentVerified): Application
{
    $application = Application::query()->create([
        'reference' => 'NOTIF-'.uniqid(),
        'client_account_id' => $account->id,
        'stage' => $stage,
        'request_type' => RequestType::NewRegistration,
        'vehicle_category' => VehicleCategory::Passenger,
        'owner_type' => 'business',
        'province' => 'gauteng',
        'datafix_status' => DatafixStatus::NotRequired,
        'fee_snapshot' => ['total_cents' => 10000],
    ]);

    $application->payments()->create([
        'amount_cents' => 10000,
        'method' => 'eft',
        'reference' => 'PAY-'.uniqid(),
        'verified_at' => now()->subHour(),
    ]);

    return $application->refresh();
}

it('sends a quote notification to the dealership contact and client admins', function (): void {
    $application = Application::query()->create([
        'reference' => 'NOTIF-Q-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::DocumentReview,
        'request_type' => RequestType::NewRegistration,
        'vehicle_category' => VehicleCategory::Commercial,
        'owner_type' => 'business',
        'province' => 'gauteng',
        'datafix_status' => DatafixStatus::NotRequired,
        'fee_snapshot' => ['total_cents' => 50000],
    ]);

    $quote = Quote::query()->create([
        'application_id' => $application->id,
        'status' => QuoteStatus::Draft,
        'client_total_cents' => 50000,
        'expires_at' => now()->addDays(14),
    ]);

    QuoteLine::query()->create([
        'quote_id' => $quote->id,
        'description' => 'Government fee',
        'client_price_cents' => 50000,
        'internal_cost_cents' => 50000,
    ]);

    app(SendQuote::class)->handle($application->refresh(), $this->admin, $quote);

    Notification::assertSentOnDemand(QuoteSentToDealer::class, function ($notification, $channels, $notifiable) {
        $emails = (array) $notifiable->routes['mail'];

        return in_array('ops@highveld.example', $emails, true)
            && in_array('owner@highveld.example', $emails, true);
    });
});

it('sends a changes-requested notification when a reviewer sends an application back', function (): void {
    $application = Application::query()->create([
        'reference' => 'NOTIF-CR-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::DocumentReview,
        'request_type' => RequestType::NewRegistration,
        'vehicle_category' => VehicleCategory::Passenger,
        'owner_type' => 'business',
        'province' => 'gauteng',
        'datafix_status' => DatafixStatus::NotRequired,
        'fee_snapshot' => ['total_cents' => 10000],
    ]);

    $documentType = DocumentType::query()->firstOrCreate(
        ['code' => 'poa'],
        ['name' => 'Proof of address', 'identity' => false],
    );

    ApplicationDocument::query()->create([
        'application_id' => $application->id,
        'document_type_id' => $documentType->id,
        'party_role' => 'owner',
        'required' => true,
        'status' => DocumentStatus::Rejected,
    ]);

    app(TransitionApplication::class)->handle(
        $application,
        ApplicationStage::ChangesRequested,
        $this->admin,
        'Replace the proof of address — expired.',
    );

    Notification::assertSentOnDemand(ChangesRequestedForDealer::class, function ($notification) {
        return $notification->reason === 'Replace the proof of address — expired.';
    });
});

it('sends a payment verified notification when finance verifies a payment', function (): void {
    $application = Application::query()->create([
        'reference' => 'NOTIF-PV-'.uniqid(),
        'client_account_id' => $this->dealership->id,
        'stage' => ApplicationStage::PaymentPending,
        'request_type' => RequestType::NewRegistration,
        'vehicle_category' => VehicleCategory::Passenger,
        'owner_type' => 'business',
        'province' => 'gauteng',
        'datafix_status' => DatafixStatus::NotRequired,
        'fee_snapshot' => ['total_cents' => 10000],
    ]);

    app(VerifyPayment::class)->handle($application, $this->admin, 10000, 'eft', 'ABC-123');

    Notification::assertSentOnDemandTimes(PaymentVerifiedForDealer::class, 1);
});

it('sends an authority-submitted notification when a reviewer submits', function (): void {
    $application = makeReadyApplication($this->dealership);

    app(SubmitToAuthority::class)->handle(
        $application,
        $this->admin,
        'eNaTIS-AUTH-99',
        now(),
    );

    Notification::assertSentOnDemand(AuthoritySubmittedForDealer::class, function ($notification) {
        return $notification->application->authority_reference === 'eNaTIS-AUTH-99';
    });
});

it('sends a ready-for-collection notification when the returned documents are received', function (): void {
    $application = makeReadyApplication($this->dealership, ApplicationStage::Approved);
    $application->forceFill(['authority_submitted_at' => now()->subDays(3)])->save();

    app(RecordAuthorityReturn::class)->handle($application, $this->admin, now());

    Notification::assertSentOnDemandTimes(ApplicationReadyForCollection::class, 1);
});

it('sends no workflow notifications when the admin toggle is off', function (): void {
    SystemSetting::current()->update(['notifications_enabled' => false]);

    $application = makeReadyApplication($this->dealership);

    app(SubmitToAuthority::class)->handle(
        $application,
        $this->admin,
        'eNaTIS-AUTH-999',
        now(),
    );

    Notification::assertNothingSent();
});

it('still delivers the test email when the workflow toggle is off', function (): void {
    SystemSetting::current()->update(['notifications_enabled' => false]);

    app(NotificationDispatcher::class)->sendTestEmail('qa@example.co.za');

    Notification::assertSentOnDemand(MailgunTestNotification::class);
});

it('applies mailgun credentials from the database at boot', function (): void {
    SystemSetting::current()->update([
        'mailgun_domain' => 'mg.example.co.za',
        'mailgun_secret' => 'secret-value',
        'mailgun_endpoint' => 'api.eu.mailgun.net',
        'mail_from_address' => 'no-reply@example.co.za',
        'mail_from_name' => 'Example Licensing',
    ]);

    app(AppServiceProvider::class, ['app' => $this->app])
        ->boot();

    expect(Config::get('services.mailgun.domain'))->toBe('mg.example.co.za')
        ->and(Config::get('services.mailgun.secret'))->toBe('secret-value')
        ->and(Config::get('services.mailgun.endpoint'))->toBe('api.eu.mailgun.net')
        ->and(Config::get('mail.default'))->toBe('mailgun')
        ->and(Config::get('mail.from.address'))->toBe('no-reply@example.co.za')
        ->and(Config::get('mail.from.name'))->toBe('Example Licensing');
});
