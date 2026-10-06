<?php

namespace Tests\Feature;

use App\Actions\CalculateFees;
use App\Actions\TransitionApplication;
use App\Enums\ApplicationStage;
use App\Enums\FeePeriod;
use App\Enums\LicenceFeeCategory;
use App\Enums\Province;
use App\Enums\ServiceType;
use App\Enums\TaxTreatment;
use App\Enums\VehicleCategory;
use App\Exceptions\InvalidTransition;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\FeeTable;
use App\Models\FeeTableVersion;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\EstimateLicenceCost;
use Database\Seeders\LicenceFeeBandSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CalculateFeesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::query()->updateOrCreate(['id' => 1], ['vat_basis_points' => 1500]);
    }

    public function test_vat_is_not_applied_to_exempt_lines(): void
    {
        $application = $this->makeApplication(tareKg: 1200, province: Province::Gauteng);

        $this->seedActiveTable(Province::Gauteng, [
            ['code' => 'licence',  'label' => 'Annual licence', 'cents' => 80000, 'tax' => TaxTreatment::Exempt,   'tare_min' => 1001, 'tare_max' => 1500, 'period' => FeePeriod::Annual],
            ['code' => 'admin',    'label' => 'Admin fee',      'cents' => 15000, 'tax' => TaxTreatment::Standard, 'tare_min' => null, 'tare_max' => null, 'period' => FeePeriod::OnceOff],
        ]);

        $snapshot = app(CalculateFees::class)->snapshot($application->refresh());

        $this->assertSame(15000, $snapshot['taxable_subtotal_cents']);
        $this->assertSame(80000, $snapshot['exempt_subtotal_cents']);
        $this->assertSame(2250, $snapshot['vat_cents']);
        $this->assertSame(97250, $snapshot['total_cents']);
    }

    public function test_all_exempt_table_adds_no_vat_line(): void
    {
        $application = $this->makeApplication(tareKg: 900, province: Province::WesternCape);

        $this->seedActiveTable(Province::WesternCape, [
            ['code' => 'licence',      'label' => 'Annual licence', 'cents' => 72000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 751, 'tare_max' => 1000, 'period' => FeePeriod::Annual],
            ['code' => 'registration', 'label' => 'Gov reg fee',    'cents' => 50000, 'tax' => TaxTreatment::Exempt, 'tare_min' => null, 'tare_max' => null, 'period' => FeePeriod::OnceOff],
        ]);

        $snapshot = app(CalculateFees::class)->snapshot($application->refresh());

        $codes = array_column($snapshot['lines'], 'code');

        $this->assertNotContains('vat', $codes);
        $this->assertSame(0, $snapshot['vat_cents']);
        $this->assertSame(0, $snapshot['taxable_subtotal_cents']);
        $this->assertSame(122000, $snapshot['total_cents']);
    }

    public function test_tare_band_resolves_to_the_correct_licence_fee(): void
    {
        $application = $this->makeApplication(tareKg: 1600, province: Province::KwaZuluNatal);

        $this->seedActiveTable(Province::KwaZuluNatal, [
            ['code' => 'licence', 'label' => 'Licence 1251-1500', 'cents' => 70000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1251, 'tare_max' => 1500, 'period' => FeePeriod::Annual],
            ['code' => 'licence', 'label' => 'Licence 1501-1750', 'cents' => 85000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1501, 'tare_max' => 1750, 'period' => FeePeriod::Annual],
            ['code' => 'licence', 'label' => 'Licence 1751-2000', 'cents' => 95000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1751, 'tare_max' => 2000, 'period' => FeePeriod::Annual],
        ]);

        $snapshot = app(CalculateFees::class)->snapshot($application->refresh());

        $licenceLines = array_values(array_filter($snapshot['lines'], fn (array $l): bool => $l['code'] === 'licence'));

        $this->assertCount(1, $licenceLines);
        $this->assertSame('Licence 1501-1750', $licenceLines[0]['label']);
        $this->assertSame(85000, $licenceLines[0]['amount_cents']);
    }

    public function test_effective_dating_picks_the_current_active_version(): void
    {
        $application = $this->makeApplication(tareKg: 1200, province: Province::Gauteng);

        $table = FeeTable::query()->create([
            'province' => Province::Gauteng->value,
            'name' => 'Gauteng licence fees',
        ]);

        $priorYear = $table->versions()->create([
            'version' => 1,
            'status' => 'active',
            'effective_from' => Carbon::now()->subYears(2)->toDateString(),
            'effective_until' => Carbon::now()->subYear()->toDateString(),
        ]);
        $priorYear->lines()->create([
            'code' => 'licence', 'label' => 'Old rate', 'amount_cents' => 60000,
            'client_visible' => true, 'tax_treatment' => TaxTreatment::Exempt->value,
            'period' => FeePeriod::Annual->value, 'tare_min_kg' => 1001, 'tare_max_kg' => 1500,
        ]);

        $currentYear = $table->versions()->create([
            'version' => 2,
            'status' => 'active',
            'effective_from' => Carbon::now()->subMonths(3)->toDateString(),
            'effective_until' => null,
        ]);
        $currentYear->lines()->create([
            'code' => 'licence', 'label' => 'Current rate', 'amount_cents' => 82000,
            'client_visible' => true, 'tax_treatment' => TaxTreatment::Exempt->value,
            'period' => FeePeriod::Annual->value, 'tare_min_kg' => 1001, 'tare_max_kg' => 1500,
        ]);

        $snapshot = app(CalculateFees::class)->snapshot($application->refresh());

        $this->assertSame($currentYear->id, $snapshot['fee_table_version_id']);
        $this->assertSame(82000, $snapshot['exempt_subtotal_cents']);
    }

    public function test_an_expired_fee_table_never_prices_an_application(): void
    {
        $application = $this->makeApplication(tareKg: 1200, province: Province::Gauteng);

        $expired = $this->seedActiveTable(Province::Gauteng, [
            ['code' => 'licence', 'label' => 'Old rate', 'cents' => 60000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1001, 'tare_max' => 1500, 'period' => FeePeriod::Annual],
        ]);
        $expired->update([
            'effective_from' => Carbon::now()->subYears(2)->toDateString(),
            'effective_until' => Carbon::now()->subDay()->toDateString(),
        ]);

        $snapshot = app(CalculateFees::class)->snapshot($application->refresh());

        $this->assertNull($snapshot['fee_table_version_id']);
        $this->assertSame(0, $snapshot['total_cents']);
    }

    public function test_a_future_dated_fee_table_never_prices_an_application(): void
    {
        $application = $this->makeApplication(tareKg: 1200, province: Province::Gauteng);

        $future = $this->seedActiveTable(Province::Gauteng, [
            ['code' => 'licence', 'label' => 'Next year', 'cents' => 90000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1001, 'tare_max' => 1500, 'period' => FeePeriod::Annual],
        ]);
        $future->update(['effective_from' => Carbon::now()->addMonth()->toDateString()]);

        $this->assertNull(app(CalculateFees::class)->versionInEffect($application->refresh()));
    }

    public function test_billing_is_refused_when_no_fee_table_is_in_effect(): void
    {
        $this->seed(RoleSeeder::class);
        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->assignRole('reviewer');

        $application = $this->makeApplication(tareKg: 1200, province: Province::Gauteng);
        $expired = $this->seedActiveTable(Province::Gauteng, [
            ['code' => 'registration', 'label' => 'Registration', 'cents' => 50000, 'tax' => TaxTreatment::Exempt, 'tare_min' => null, 'tare_max' => null, 'period' => FeePeriod::OnceOff],
        ]);
        $expired->update(['effective_until' => Carbon::now()->subDay()->toDateString()]);

        $this->expectException(InvalidTransition::class);
        $this->expectExceptionMessage('No fee table is in effect today for Gauteng');

        app(TransitionApplication::class)->handle($application->refresh(), ApplicationStage::PaymentPending, $reviewer);
    }

    public function test_billing_is_refused_while_the_licence_fee_has_no_matching_band(): void
    {
        $this->seed(RoleSeeder::class);
        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->assignRole('reviewer');

        $application = $this->makeApplication(tareKg: 3000, province: Province::Gauteng);
        $version = $this->seedActiveTable(Province::Gauteng, [
            ['code' => 'admin', 'label' => 'Admin fee', 'cents' => 15000, 'tax' => TaxTreatment::Standard, 'tare_min' => null, 'tare_max' => null, 'period' => FeePeriod::OnceOff],
        ]);
        $this->addBand($version, LicenceFeeCategory::MotorCar, 72000, 1001, 1500);

        $snapshot = app(CalculateFees::class)->snapshot($application->refresh());
        $this->assertNotContains('licence', array_column($snapshot['lines'], 'code'));
        $this->assertCount(1, $snapshot['unpriced']);
        $this->assertStringContainsString('3 000 kg', $snapshot['unpriced'][0]);

        $this->expectException(InvalidTransition::class);
        $this->expectExceptionMessage('The fees are incomplete');

        app(TransitionApplication::class)->handle($application->refresh(), ApplicationStage::PaymentPending, $reviewer);
    }

    public function test_the_dealer_estimator_prices_the_same_licence_band_and_admin_charge_as_an_application(): void
    {
        $application = $this->makeApplication(tareKg: 1200, province: Province::Gauteng);
        $version = $this->seedActiveTable(Province::Gauteng, [
            ['code' => 'admin', 'label' => 'Admin fee', 'cents' => 15000, 'tax' => TaxTreatment::Standard, 'tare_min' => null, 'tare_max' => null, 'period' => FeePeriod::OnceOff],
        ]);
        $this->addBand($version, LicenceFeeCategory::MotorCar, 72000, 1001, 1500);
        $this->addBand($version, LicenceFeeCategory::Trailer, 99000, 1001, 1500);

        $snapshot = app(CalculateFees::class)->snapshot($application->refresh());
        $estimate = app(EstimateLicenceCost::class)->compute(Province::Gauteng, LicenceFeeCategory::MotorCar, 1200, Carbon::now());

        $byCode = collect($snapshot['lines'])->keyBy('code');
        $this->assertSame($byCode['licence']['amount_cents'], $estimate['licence_fee_cents']);
        $this->assertSame($byCode['admin']['amount_cents'], $estimate['admin_charge_cents']);
        $this->assertSame(TaxTreatment::Standard, $estimate['admin_charge_tax_treatment']);
    }

    public function test_licence_fee_band_seeder_populates_all_provinces(): void
    {
        $this->seed(LicenceFeeBandSeeder::class);

        foreach (Province::cases() as $province) {
            $table = FeeTable::query()->where('province', $province->value)->firstOrFail();
            $draft = $table->versions()->where('version', 2)->firstOrFail();

            $this->assertSame('draft', $draft->status, "{$province->value} draft should be a draft version");
            $this->assertGreaterThanOrEqual(28, $draft->lines()->count(), "{$province->value} should have the full band set");

            $motorcycle = $draft->lines()->where('licence_category', LicenceFeeCategory::Motorcycle->value)->first();
            $this->assertNotNull($motorcycle);
            $this->assertSame(TaxTreatment::Exempt, $motorcycle->tax_treatment);
            $this->assertSame(FeePeriod::Annual, $motorcycle->period);
            $this->assertSame(0, $motorcycle->amount_cents, 'Seeded amounts must be zero so admin fills in the real rate.');
        }
    }

    private function makeApplication(int $tareKg, Province $province = Province::Gauteng): Application
    {
        $account = ClientAccount::query()->create([
            'name' => 'Ridgeline Haulage',
            'type' => 'dealer',
            'status' => 'active',
            'markup_basis_points' => 0,
        ]);

        $application = Application::query()->create([
            'client_account_id' => $account->id,
            'reference' => 'FEE-'.uniqid(),
            'province' => $province,
            'stage' => ApplicationStage::DocumentReview,
            'service_type' => ServiceType::RegisterAndLicense,
            'request_type' => 'new_registration',
            'vehicle_category' => VehicleCategory::Passenger,
        ]);

        Vehicle::query()->create([
            'application_id' => $application->id,
            'vin' => 'VIN'.uniqid(),
            'tare_kg' => $tareKg,
        ]);

        return $application->load(['vehicle', 'clientAccount']);
    }

    /**
     * @param  list<array{code: string, label: string, cents: int, tax: TaxTreatment, tare_min: int|null, tare_max: int|null, period: FeePeriod}>  $lines
     */
    private function seedActiveTable(Province $province, array $lines): FeeTableVersion
    {
        $table = FeeTable::query()->firstOrCreate(
            ['province' => $province->value],
            ['name' => $province->label().' fees'],
        );

        $version = $table->versions()->create([
            'version' => $table->versions()->max('version') + 1,
            'status' => 'active',
        ]);

        foreach ($lines as $line) {
            $version->lines()->create([
                'code' => $line['code'],
                'label' => $line['label'],
                'amount_cents' => $line['cents'],
                'client_visible' => true,
                'tax_treatment' => $line['tax']->value,
                'period' => $line['period']->value,
                'tare_min_kg' => $line['tare_min'],
                'tare_max_kg' => $line['tare_max'],
            ]);
        }

        return $version;
    }

    private function addBand(FeeTableVersion $version, LicenceFeeCategory $category, int $cents, int $tareMin, int $tareMax): void
    {
        $version->lines()->create([
            'code' => 'licence',
            'label' => $category->label().' '.$tareMin.'-'.$tareMax.' kg',
            'amount_cents' => $cents,
            'client_visible' => true,
            'tax_treatment' => TaxTreatment::Exempt->value,
            'period' => FeePeriod::Annual->value,
            'licence_category' => $category->value,
            'tare_min_kg' => $tareMin,
            'tare_max_kg' => $tareMax,
        ]);
    }
}
