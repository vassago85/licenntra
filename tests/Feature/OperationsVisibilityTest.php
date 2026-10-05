<?php

use App\Enums\ApplicationStage;
use App\Enums\DatafixStatus;
use App\Enums\DocumentStatus;
use App\Enums\FeePeriod;
use App\Enums\LicenceFeeCategory;
use App\Enums\RequestType;
use App\Enums\TaxTreatment;
use App\Enums\VehicleCategory;
use App\Livewire\Portal\ApplicationForm;
use App\Livewire\Portal\Dashboard;
use App\Livewire\Portal\ReviewWorkspace;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ClientAccount;
use App\Models\DocumentType;
use App\Models\FeeTable;
use App\Models\User;
use App\Services\FeatureFlags;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->dealership = ClientAccount::query()->create([
        'name' => 'Highveld Commercial Centurion',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->owner = User::factory()->create(['is_active' => true]);
    $this->owner->assignRole('owner');

    $this->operations = User::factory()->create(['is_active' => true]);
    $this->operations->assignRole('reviewer');

    $this->dealer = User::factory()->create(['is_active' => true, 'client_account_id' => $this->dealership->id]);
    $this->dealer->assignRole('customer_admin');
});

afterEach(function (): void {
    FeatureFlags::swapQuotesEnabled(null);
    FeatureFlags::swapPaymentTrackingRequired(null);
});

function visibilityApplication(ClientAccount $account, ApplicationStage $stage): Application
{
    return Application::query()->create([
        'reference' => 'VIS-'.uniqid(),
        'client_account_id' => $account->id,
        'stage' => $stage,
        'request_type' => RequestType::LicenceRenewal,
        'vehicle_category' => VehicleCategory::Passenger,
        'province' => 'gauteng',
        'datafix_status' => DatafixStatus::NotRequired,
    ]);
}

it('hides quote totals on the overview when quoting is switched off', function (): void {
    FeatureFlags::swapQuotesEnabled(false);

    $this->actingAs($this->owner)
        ->get(route('admin.overview'))
        ->assertOk()
        ->assertDontSee('Open quotes');

    FeatureFlags::swapQuotesEnabled(true);

    $this->actingAs($this->owner)
        ->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('Open quotes');
});

it('puts customer workload above the money panels on the overview', function (): void {
    $this->actingAs($this->owner)
        ->get(route('admin.overview'))
        ->assertOk()
        ->assertSeeInOrder(['Dealerships needing action', 'Money', 'Customers — outstanding payments']);
});

it('hides the quote builder in the review workspace when quoting is switched off', function (): void {
    $application = visibilityApplication($this->dealership, ApplicationStage::DocumentReview);

    FeatureFlags::swapQuotesEnabled(false);

    Livewire::actingAs($this->operations)
        ->test(ReviewWorkspace::class, ['application' => $application])
        ->assertDontSee('Open quote builder')
        ->assertDontSee("advance('quote_required')", false);

    FeatureFlags::swapQuotesEnabled(true);

    Livewire::actingAs($this->operations)
        ->test(ReviewWorkspace::class, ['application' => $application])
        ->assertSee('Open quote builder');
});

it('shows the same needs-your-action count on the dealer tile as in its filtered list', function (): void {
    FeatureFlags::swapQuotesEnabled(false);
    FeatureFlags::swapPaymentTrackingRequired(false);

    visibilityApplication($this->dealership, ApplicationStage::Draft);
    visibilityApplication($this->dealership, ApplicationStage::ChangesRequested);
    visibilityApplication($this->dealership, ApplicationStage::QuoteSent);
    visibilityApplication($this->dealership, ApplicationStage::PaymentPending);
    $withRejection = visibilityApplication($this->dealership, ApplicationStage::DocumentReview);
    $type = DocumentType::query()->create(['code' => 'poa', 'name' => 'Proof of address', 'is_identity_document' => false]);

    foreach (range(1, 2) as $ignored) {
        ApplicationDocument::query()->create([
            'application_id' => $withRejection->id,
            'document_type_id' => $type->id,
            'party_role' => 'owner',
            'required' => true,
            'status' => DocumentStatus::Rejected,
        ]);
    }

    $component = Livewire::actingAs($this->dealer)->test(Dashboard::class);
    $tileTotal = $component->viewData('tiles')['needsAction']['total'];
    $listTotal = $component->call('setFilter', 'needs_action')->viewData('rows')->total();

    expect($tileTotal)->toBe(3)
        ->and($listTotal)->toBe($tileTotal);
});

it('refreshes the new-application estimate from the form before the draft is saved', function (): void {
    $table = FeeTable::query()->create(['province' => 'gauteng', 'name' => 'Gauteng fees']);
    $version = $table->versions()->create([
        'version' => 1,
        'status' => 'active',
        'effective_from' => now()->subMonth()->toDateString(),
    ]);
    $version->lines()->create([
        'code' => 'licence', 'label' => 'Motor car 1001-1500 kg', 'amount_cents' => 54000,
        'client_visible' => true, 'tax_treatment' => TaxTreatment::Exempt->value, 'period' => FeePeriod::Annual->value,
        'licence_category' => LicenceFeeCategory::MotorCar->value, 'tare_min_kg' => 1001, 'tare_max_kg' => 1500,
    ]);
    $version->lines()->create([
        'code' => 'licence', 'label' => 'Motor car 1501-2000 kg', 'amount_cents' => 67000,
        'client_visible' => true, 'tax_treatment' => TaxTreatment::Exempt->value, 'period' => FeePeriod::Annual->value,
        'licence_category' => LicenceFeeCategory::MotorCar->value, 'tare_min_kg' => 1501, 'tare_max_kg' => 2000,
    ]);

    $component = Livewire::actingAs($this->dealer)
        ->test(ApplicationForm::class)
        ->assertSee('Choose a province to estimate fees.')
        ->set('request_type', RequestType::LicenceRenewal->value)
        ->set('licence_category', LicenceFeeCategory::MotorCar->value)
        ->set('province', 'gauteng')
        ->set('tare_kg', '1200')
        ->assertDontSee('Choose a province to estimate fees.')
        ->assertSee('Motor car 1001-1500 kg');

    expect($component->viewData('estimate')['fee_table_version_id'])->toBe($version->id)
        ->and($component->viewData('estimate')['total_cents'])->toBe(54000);

    $component->set('tare_kg', '1800')->assertSee('Motor car 1501-2000 kg');

    expect($component->viewData('estimate')['total_cents'])->toBe(67000)
        ->and(Application::query()->count())->toBe(0);
});
