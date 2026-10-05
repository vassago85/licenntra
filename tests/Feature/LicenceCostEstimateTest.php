<?php

use App\Enums\ApplicationStage;
use App\Enums\FeePeriod;
use App\Enums\LicenceFeeCategory;
use App\Enums\Province;
use App\Enums\TaxTreatment;
use App\Livewire\Portal\LicenceCostEstimator;
use App\Models\Application;
use App\Models\ClientAccount;
use App\Models\FeeTable;
use App\Models\FeeTableVersion;
use App\Models\LicenceEstimate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\EstimateLicenceCost;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    SystemSetting::query()->updateOrCreate(['id' => 1], [
        'vat_basis_points' => 1500,
        'admin_charge_cents' => 15000,
        'admin_charge_tax_treatment' => TaxTreatment::Standard->value,
    ]);

    $this->account = ClientAccount::query()->create([
        'name' => 'Ridgeline Dealers',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);

    $this->user = User::factory()->create([
        'client_account_id' => $this->account->id,
        'is_active' => true,
    ]);
    $this->user->assignRole('customer_admin');

    $this->service = app(EstimateLicenceCost::class);
});

/**
 * Build and activate a fee-schedule version for a province.
 *
 * @param  list<array{category: LicenceFeeCategory, cents: int, tax: TaxTreatment, tare_min: int|null, tare_max: int|null}>  $lines
 */
function seedActiveFeeVersion(
    Province $province,
    array $lines,
    ?Carbon $from = null,
    ?Carbon $until = null,
    int $version = 1,
): FeeTableVersion {
    $table = FeeTable::query()->firstOrCreate(
        ['province' => $province->value],
        ['name' => $province->label().' fees'],
    );

    $v = $table->versions()->create([
        'version' => $version,
        'status' => 'active',
        'effective_from' => $from?->toDateString(),
        'effective_until' => $until?->toDateString(),
    ]);

    foreach ($lines as $line) {
        $v->lines()->create([
            'code' => 'licence',
            'label' => $line['category']->label().($line['tare_min'] !== null ? ' '.$line['tare_min'].'-'.($line['tare_max'] ?? '').' kg' : ''),
            'amount_cents' => $line['cents'],
            'client_visible' => true,
            'tax_treatment' => $line['tax']->value,
            'period' => FeePeriod::Annual->value,
            'licence_category' => $line['category']->value,
            'tare_min_kg' => $line['tare_min'],
            'tare_max_kg' => $line['tare_max'],
        ]);
    }

    return $v;
}

it('matches the correct province, category and weight band', function () {
    seedActiveFeeVersion(Province::Gauteng, [
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 60000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 751, 'tare_max' => 1000],
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 72000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1001, 'tare_max' => 1250],
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 85000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1251, 'tare_max' => 1500],
    ]);

    $result = $this->service->compute(
        Province::Gauteng,
        LicenceFeeCategory::MotorCar,
        1200,
        Carbon::parse('2026-10-04'),
    );

    expect($result['status'])->toBe(LicenceEstimate::STATUS_ESTIMATED)
        ->and($result['licence_fee_cents'])->toBe(72000)
        ->and($result['fee_line_tare_min_kg'])->toBe(1001)
        ->and($result['fee_line_tare_max_kg'])->toBe(1250);
});

it('applies the band at exact weight boundaries (lower and upper edges)', function () {
    seedActiveFeeVersion(Province::Gauteng, [
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 72000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1001, 'tare_max' => 1250],
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 85000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1251, 'tare_max' => 1500],
    ]);

    $lower = $this->service->compute(Province::Gauteng, LicenceFeeCategory::MotorCar, 1001, Carbon::now());
    $upper = $this->service->compute(Province::Gauteng, LicenceFeeCategory::MotorCar, 1250, Carbon::now());
    $nextBandLower = $this->service->compute(Province::Gauteng, LicenceFeeCategory::MotorCar, 1251, Carbon::now());

    expect($lower['licence_fee_cents'])->toBe(72000)
        ->and($upper['licence_fee_cents'])->toBe(72000)
        ->and($nextBandLower['licence_fee_cents'])->toBe(85000);
});

it('scopes to the matching province and ignores other provinces', function () {
    seedActiveFeeVersion(Province::Gauteng, [
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 72000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1001, 'tare_max' => 1500],
    ]);

    $result = $this->service->compute(
        Province::WesternCape,
        LicenceFeeCategory::MotorCar,
        1200,
        Carbon::now(),
    );

    expect($result['status'])->toBe(LicenceEstimate::STATUS_CONFIRMATION_REQUIRED)
        ->and($result['licence_fee_cents'])->toBe(0)
        ->and($result['confirmation_reason'])->toContain('Western Cape');
});

it('refuses to use an expired schedule and never substitutes a stale rate', function () {
    seedActiveFeeVersion(
        Province::Gauteng,
        [['category' => LicenceFeeCategory::MotorCar, 'cents' => 60000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1001, 'tare_max' => 1500]],
        from: Carbon::parse('2024-01-01'),
        until: Carbon::parse('2024-12-31'),
    );

    $result = $this->service->compute(
        Province::Gauteng,
        LicenceFeeCategory::MotorCar,
        1200,
        Carbon::parse('2026-10-04'),
    );

    expect($result['status'])->toBe(LicenceEstimate::STATUS_CONFIRMATION_REQUIRED)
        ->and($result['licence_fee_cents'])->toBe(0)
        ->and($result['total_cents'])->toBe(0)
        ->and($result['confirmation_reason'])->toContain('No approved fee schedule');
});

it('picks the version that is active on the applicable date, not today', function () {
    $table = FeeTable::query()->create([
        'province' => Province::Gauteng->value,
        'name' => 'Gauteng',
    ]);

    $historic = $table->versions()->create([
        'version' => 1,
        'status' => 'active',
        'effective_from' => Carbon::parse('2024-01-01')->toDateString(),
        'effective_until' => Carbon::parse('2024-12-31')->toDateString(),
    ]);
    $historic->lines()->create([
        'code' => 'licence', 'label' => 'Old rate', 'amount_cents' => 60000,
        'client_visible' => true, 'tax_treatment' => TaxTreatment::Exempt->value,
        'period' => FeePeriod::Annual->value, 'licence_category' => LicenceFeeCategory::MotorCar->value,
        'tare_min_kg' => 1001, 'tare_max_kg' => 1500,
    ]);

    $current = $table->versions()->create([
        'version' => 2,
        'status' => 'active',
        'effective_from' => Carbon::parse('2025-01-01')->toDateString(),
        'effective_until' => null,
    ]);
    $current->lines()->create([
        'code' => 'licence', 'label' => 'New rate', 'amount_cents' => 80000,
        'client_visible' => true, 'tax_treatment' => TaxTreatment::Exempt->value,
        'period' => FeePeriod::Annual->value, 'licence_category' => LicenceFeeCategory::MotorCar->value,
        'tare_min_kg' => 1001, 'tare_max_kg' => 1500,
    ]);

    $historicResult = $this->service->compute(
        Province::Gauteng,
        LicenceFeeCategory::MotorCar,
        1200,
        Carbon::parse('2024-06-01'),
    );

    $currentResult = $this->service->compute(
        Province::Gauteng,
        LicenceFeeCategory::MotorCar,
        1200,
        Carbon::parse('2025-06-01'),
    );

    expect($historicResult['licence_fee_cents'])->toBe(60000)
        ->and($historicResult['fee_table_version_id'])->toBe($historic->id)
        ->and($currentResult['licence_fee_cents'])->toBe(80000)
        ->and($currentResult['fee_table_version_id'])->toBe($current->id);
});

it('adds the admin charge and VAT exactly once', function () {
    seedActiveFeeVersion(Province::Gauteng, [
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 100000, 'tax' => TaxTreatment::Exempt, 'tare_min' => null, 'tare_max' => null],
    ]);

    $result = $this->service->compute(
        Province::Gauteng,
        LicenceFeeCategory::MotorCar,
        null,
        Carbon::now(),
    );

    // Licence 100000 exempt, admin 15000 standard, VAT 15% → 2250, total 117250.
    // No RTMC line exists on this skeletal fee version, so RTMC must stay 0.
    expect($result['licence_fee_cents'])->toBe(100000)
        ->and($result['admin_charge_cents'])->toBe(15000)
        ->and($result['rtmc_transaction_fee_cents'])->toBe(0)
        ->and($result['vat_cents'])->toBe(2250)
        ->and($result['total_cents'])->toBe(117250);
});

it('adds the R72 RTMC national transaction fee when it is present on the fee version', function () {
    $version = seedActiveFeeVersion(Province::Gauteng, [
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 100000, 'tax' => TaxTreatment::Exempt, 'tare_min' => null, 'tare_max' => null],
    ]);

    // Attach the national R72 pass-through to the version. Exempt from VAT.
    $version->lines()->create([
        'code' => 'rtmc_transaction_fee',
        'label' => 'RTMC transaction fee',
        'amount_cents' => 7200,
        'client_visible' => true,
        'tax_treatment' => TaxTreatment::Exempt->value,
        'period' => FeePeriod::OnceOff->value,
        'licence_category' => LicenceFeeCategory::TransactionFee->value,
    ]);

    $result = $this->service->compute(
        Province::Gauteng,
        LicenceFeeCategory::MotorCar,
        null,
        Carbon::now(),
    );

    // Licence 100000 exempt, admin 15000 standard, RTMC 7200 exempt,
    // VAT 15% on 15000 → 2250, total 100000 + 15000 + 7200 + 2250 = 124450.
    expect($result['licence_fee_cents'])->toBe(100000)
        ->and($result['admin_charge_cents'])->toBe(15000)
        ->and($result['rtmc_transaction_fee_cents'])->toBe(7200)
        ->and($result['rtmc_transaction_fee_tax_treatment'])->toBe(TaxTreatment::Exempt)
        ->and($result['vat_cents'])->toBe(2250)
        ->and($result['total_cents'])->toBe(124450);
});

it('persists the R72 RTMC line in the saved estimate snapshot', function () {
    $version = seedActiveFeeVersion(Province::Gauteng, [
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 70000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1001, 'tare_max' => 1500],
    ]);
    $version->lines()->create([
        'code' => 'rtmc_transaction_fee',
        'label' => 'RTMC transaction fee',
        'amount_cents' => 7200,
        'client_visible' => true,
        'tax_treatment' => TaxTreatment::Exempt->value,
        'period' => FeePeriod::OnceOff->value,
        'licence_category' => LicenceFeeCategory::TransactionFee->value,
    ]);

    Livewire::actingAs($this->user)
        ->test(LicenceCostEstimator::class)
        ->set('province', Province::Gauteng->value)
        ->set('licence_category', LicenceFeeCategory::MotorCar->value)
        ->set('tare_kg', 1200)
        ->set('applicable_date', Carbon::now()->toDateString())
        ->call('calculate')
        ->call('save');

    $estimate = LicenceEstimate::query()->firstOrFail();

    expect($estimate->rtmc_transaction_fee_cents)->toBe(7200)
        ->and($estimate->rtmc_transaction_fee_tax_treatment)->toBe(TaxTreatment::Exempt)
        // The snapshot must not change if the licensing company later edits
        // the RTMC line on the active version.
        ->and($estimate->total_cents)->toBeGreaterThanOrEqual(70000 + 7200);

    $version->lines()->where('code', 'rtmc_transaction_fee')->update(['amount_cents' => 9999]);

    expect($estimate->refresh()->rtmc_transaction_fee_cents)->toBe(7200);
});

it('applies the configured tax treatment to each charge', function () {
    SystemSetting::query()->updateOrCreate(['id' => 1], [
        'vat_basis_points' => 1500,
        'admin_charge_cents' => 10000,
        'admin_charge_tax_treatment' => TaxTreatment::Exempt->value,
    ]);

    seedActiveFeeVersion(Province::Gauteng, [
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 80000, 'tax' => TaxTreatment::Standard, 'tare_min' => null, 'tare_max' => null],
    ]);

    $result = $this->service->compute(
        Province::Gauteng,
        LicenceFeeCategory::MotorCar,
        null,
        Carbon::now(),
    );

    // Licence 80000 standard → 12000 VAT. Admin exempt → no VAT.
    expect($result['vat_cents'])->toBe(12000)
        ->and($result['total_cents'])->toBe(80000 + 10000 + 12000);
});

it('never returns a misleading zero when the matched band has no captured amount', function () {
    seedActiveFeeVersion(Province::Gauteng, [
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 0, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1001, 'tare_max' => 1500],
    ]);

    $result = $this->service->compute(
        Province::Gauteng,
        LicenceFeeCategory::MotorCar,
        1200,
        Carbon::now(),
    );

    expect($result['status'])->toBe(LicenceEstimate::STATUS_CONFIRMATION_REQUIRED)
        ->and($result['licence_fee_cents'])->toBe(0)
        ->and($result['total_cents'])->toBe(0)
        ->and($result['confirmation_reason'])->toContain('no approved amount');
});

it('saves a historical snapshot that does not change when the active rate changes', function () {
    $version = seedActiveFeeVersion(Province::Gauteng, [
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 70000, 'tax' => TaxTreatment::Exempt, 'tare_min' => 1001, 'tare_max' => 1500],
    ]);

    Livewire::actingAs($this->user)
        ->test(LicenceCostEstimator::class)
        ->set('province', Province::Gauteng->value)
        ->set('licence_category', LicenceFeeCategory::MotorCar->value)
        ->set('tare_kg', 1200)
        ->set('applicable_date', Carbon::now()->toDateString())
        ->call('calculate')
        ->call('save');

    $estimate = LicenceEstimate::query()->firstOrFail();

    expect($estimate->client_account_id)->toBe($this->account->id)
        ->and($estimate->licence_fee_cents)->toBe(70000)
        ->and($estimate->fee_table_version_id)->toBe($version->id);

    // The licensing company revises the schedule — the saved estimate must not drift.
    $version->lines()->first()->update(['amount_cents' => 90000]);

    expect($estimate->refresh()->licence_fee_cents)->toBe(70000);
});

it('scopes saved estimates to the dealer that created them', function () {
    seedActiveFeeVersion(Province::Gauteng, [
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 70000, 'tax' => TaxTreatment::Exempt, 'tare_min' => null, 'tare_max' => null],
    ]);

    Livewire::actingAs($this->user)
        ->test(LicenceCostEstimator::class)
        ->call('calculate')
        ->call('save');

    $otherAccount = ClientAccount::query()->create([
        'name' => 'Other Dealer',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);
    $otherUser = User::factory()->create([
        'client_account_id' => $otherAccount->id,
        'is_active' => true,
    ]);
    $otherUser->assignRole('customer_user');

    Livewire::actingAs($otherUser)
        ->test(LicenceCostEstimator::class)
        ->assertSee('No saved estimates yet');
});

it('refuses to attach an estimate to another account\'s application', function () {
    seedActiveFeeVersion(Province::Gauteng, [
        ['category' => LicenceFeeCategory::MotorCar, 'cents' => 70000, 'tax' => TaxTreatment::Exempt, 'tare_min' => null, 'tare_max' => null],
    ]);

    $otherAccount = ClientAccount::query()->create([
        'name' => 'Other',
        'type' => 'dealer',
        'status' => 'active',
        'markup_basis_points' => 0,
    ]);
    $foreignApp = Application::query()->create([
        'reference' => 'FOR-'.uniqid(),
        'client_account_id' => $otherAccount->id,
        'stage' => ApplicationStage::Draft,
    ]);

    Livewire::actingAs($this->user)
        ->test(LicenceCostEstimator::class)
        ->set('application_id', $foreignApp->id)
        ->call('calculate')
        ->call('save');

    expect(LicenceEstimate::query()->firstOrFail()->application_id)->toBeNull();
});
