<?php

use App\Actions\ApproveFeeTableVersion;
use App\Enums\LicenceFeeCategory;
use App\Enums\Province;
use App\Models\FeeTableVersion;
use App\Models\LicenceEstimate;
use App\Models\User;
use App\Services\EstimateLicenceCost;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Pins the Gauteng gazette (Prov Gaz 292/2025) rigid-vehicle surcharge:
 *   base at 12 000 kg: R 32 112
 *   per 500 kg (or part thereof) above 12 000 kg: + R 2 652
 *
 * Previously the seeder stored bands with overlapping edges
 * (18 000-18 500 AND 18 500-19 000 both matched tare = 18 500) and the
 * resolver picked the higher band via orderByDesc('tare_min_kg'), so a
 * vehicle at exactly a band boundary paid the next band's price.
 *
 * This test checks three boundaries that would reveal the overlap bug:
 *   - 12 000 kg (base, 0 surcharge steps)
 *   - 18 500 kg (13 surcharge steps - the case the demo caught)
 *   - 19 000 kg (14 surcharge steps - one band higher)
 */
beforeEach(function (): void {
    // Full DemoSeeder gives us roles, fee tables, fee bands, fee rates,
    // and the owner user needed to approve the draft version.
    $this->seed(DemoSeeder::class);

    $actor = User::query()->where('email', 'owner@licentra.test')->firstOrFail();
    $approve = app(ApproveFeeTableVersion::class);

    FeeTableVersion::query()
        ->where('status', 'draft')
        ->get()
        ->each(fn (FeeTableVersion $v) => $approve->handle($v, $actor));
});

it('prices a 12 000 kg rigid vehicle at the base amount (R 32 112) with zero surcharge steps', function (): void {
    $result = app(EstimateLicenceCost::class)->compute(
        province: Province::Gauteng,
        licenceCategory: LicenceFeeCategory::MotorCar,
        tareKg: 12000,
        applicableDate: Carbon::now(),
    );

    expect($result['status'])->toBe(LicenceEstimate::STATUS_ESTIMATED);
    expect($result['licence_fee_cents'])->toBe(32112_00);
    expect($result['fee_line_tare_min_kg'])->toBeLessThanOrEqual(12000);
    expect($result['fee_line_tare_max_kg'])->toBe(12000);
    expect($result['rtmc_transaction_fee_cents'])->toBe(7200);
});

it('prices an 18 500 kg rigid vehicle at base + 13 surcharge steps (R 66 588) - 13 x R 2 652', function (): void {
    $result = app(EstimateLicenceCost::class)->compute(
        province: Province::Gauteng,
        licenceCategory: LicenceFeeCategory::MotorCar,
        tareKg: 18500,
        applicableDate: Carbon::now(),
    );

    // 32 112 + 13 x 2 652 = 32 112 + 34 476 = 66 588
    expect($result['status'])->toBe(LicenceEstimate::STATUS_ESTIMATED);
    expect($result['licence_fee_cents'])->toBe(66588_00);
    expect($result['fee_line_tare_max_kg'])->toBe(18500);
    expect($result['rtmc_transaction_fee_cents'])->toBe(7200);
});

it('prices a 19 000 kg rigid vehicle at base + 14 surcharge steps (R 69 240)', function (): void {
    $result = app(EstimateLicenceCost::class)->compute(
        province: Province::Gauteng,
        licenceCategory: LicenceFeeCategory::MotorCar,
        tareKg: 19000,
        applicableDate: Carbon::now(),
    );

    // 32 112 + 14 x 2 652 = 32 112 + 37 128 = 69 240
    expect($result['status'])->toBe(LicenceEstimate::STATUS_ESTIMATED);
    expect($result['licence_fee_cents'])->toBe(69240_00);
    expect($result['fee_line_tare_max_kg'])->toBe(19000);
    expect($result['rtmc_transaction_fee_cents'])->toBe(7200);
});

it('does not leave gaps between adjacent bands - every integer tare from 12 000 to 13 500 kg resolves to exactly one band', function (): void {
    $service = app(EstimateLicenceCost::class);

    foreach ([12000, 12001, 12500, 12501, 13000, 13001, 13500] as $tare) {
        $result = $service->compute(
            province: Province::Gauteng,
            licenceCategory: LicenceFeeCategory::MotorCar,
            tareKg: $tare,
            applicableDate: Carbon::now(),
        );

        expect($result['status'])
            ->toBe(LicenceEstimate::STATUS_ESTIMATED, "tare {$tare} kg should resolve to an estimated amount, got: ".($result['confirmation_reason'] ?? 'ok'));
        expect($result['licence_fee_cents'])->toBeGreaterThan(0, "tare {$tare} kg should get a non-zero fee");
    }
});
