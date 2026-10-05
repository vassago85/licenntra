<?php

use App\Enums\LicenceFeeCategory;
use App\Enums\Province;
use App\Models\FeeTable;
use App\Models\FeeTableVersion;
use Database\Seeders\FeeTableSeeder;
use Database\Seeders\LicenceFeeBandSeeder;
use Database\Seeders\LicenceFeeRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(FeeTableSeeder::class);
    $this->seed(LicenceFeeBandSeeder::class);
    $this->seed(LicenceFeeRateSeeder::class);
});

function gautengDraft(): FeeTableVersion
{
    return FeeTable::query()
        ->where('province', Province::Gauteng->value)
        ->firstOrFail()
        ->versions()
        ->where('status', 'draft')
        ->orderByDesc('version')
        ->firstOrFail();
}

it('seeds the full gazette band structure for every province', function () {
    $expectedCount = (new LicenceFeeBandSeeder)->bands();

    foreach (Province::cases() as $province) {
        $draft = FeeTable::query()
            ->where('province', $province->value)
            ->firstOrFail()
            ->versions()
            ->where('status', 'draft')
            ->orderByDesc('version')
            ->firstOrFail();

        expect($draft->lines()->whereIn('code', ['licence', 'rtmc_transaction_fee'])->count())->toBe(count($expectedCount))
            ->and($draft->lines()->where('code', 'registration')->exists())->toBeTrue("{$province->value} draft must keep the registration fee");
    }
});

it('populates Gauteng rigid vehicle amounts to the exact gazette rand values', function () {
    $draft = gautengDraft();

    $expected = [
        250 => 25200,
        500 => 39600,
        750 => 43200,
        1000 => 45600,
        1250 => 52800,
        1500 => 73200,
        1750 => 85200,
        2000 => 110400,
        3500 => 248400,
        5000 => 441600,
        7500 => 1341600,
        8000 => 1479600,
        12000 => 3211200,
    ];

    foreach ($expected as $maxKg => $cents) {
        $line = $draft->lines()
            ->where('licence_category', LicenceFeeCategory::MotorCar->value)
            ->where('tare_max_kg', $maxKg)
            ->first();

        expect($line)->not->toBeNull("missing rigid vehicle band ending at {$maxKg} kg")
            ->and($line->amount_cents)->toBe($cents, "wrong rigid amount at {$maxKg} kg");
    }
});

it('populates Gauteng trailer amounts to the exact gazette rand values', function () {
    $draft = gautengDraft();

    $expected = [
        250 => 25200,
        750 => 43200,
        3250 => 378000,
        5000 => 698400,
        12000 => 3224400,
    ];

    foreach ($expected as $maxKg => $cents) {
        $line = $draft->lines()
            ->where('licence_category', LicenceFeeCategory::Trailer->value)
            ->where('tare_max_kg', $maxKg)
            ->first();

        expect($line)->not->toBeNull()
            ->and($line->amount_cents)->toBe($cents);
    }
});

it('carries the +R2 652 per 500 kg surcharge above 12 000 kg for rigid vehicles and trailers up to 32 000 kg', function () {
    $draft = gautengDraft();

    foreach ([12500, 13000, 15000, 20000, 32000] as $maxKg) {
        $steps = ($maxKg - 12000) / 500;

        $rigid = $draft->lines()
            ->where('licence_category', LicenceFeeCategory::MotorCar->value)
            ->where('tare_max_kg', $maxKg)
            ->first();
        $trailer = $draft->lines()
            ->where('licence_category', LicenceFeeCategory::Trailer->value)
            ->where('tare_max_kg', $maxKg)
            ->first();

        expect($rigid?->amount_cents)->toBe(3211200 + $steps * 265200, "rigid amount drifted at {$maxKg} kg")
            ->and($trailer?->amount_cents)->toBe(3224400 + $steps * 265200, "trailer amount drifted at {$maxKg} kg");
    }
});

it('populates Gauteng breakdown vehicle and tractor-on-public-road tiers to gazette amounts', function () {
    $draft = gautengDraft();

    $spot = [
        [LicenceFeeCategory::BreakdownVehicle, 0, 5000, 133200],
        [LicenceFeeCategory::BreakdownVehicle, 5001, null, 1136400],
        [LicenceFeeCategory::TractorPublicRoad, 0, 2000, 24000],
        [LicenceFeeCategory::TractorPublicRoad, 2001, 4000, 25200],
        [LicenceFeeCategory::TractorPublicRoad, 4001, 8000, 34800],
        [LicenceFeeCategory::TractorPublicRoad, 8001, null, 45600],
    ];

    foreach ($spot as [$category, $min, $max, $cents]) {
        $query = $draft->lines()
            ->where('licence_category', $category->value)
            ->where('tare_min_kg', $min);

        $max === null
            ? $query->whereNull('tare_max_kg')
            : $query->where('tare_max_kg', $max);

        $line = $query->first();

        expect($line)->not->toBeNull()
            ->and($line->amount_cents)->toBe($cents);
    }
});

it('populates Gauteng flat fees (motorcycle, caravan, trade plates, permits, admin fees) to gazette amounts', function () {
    $draft = gautengDraft();

    $expected = [
        LicenceFeeCategory::Motorcycle->value => 25200,
        LicenceFeeCategory::Caravan->value => 42000,
        LicenceFeeCategory::Dealer->value => 42000,
        LicenceFeeCategory::TradePlateMotorcycle->value => 26400,
        LicenceFeeCategory::TradePlateOther->value => 103200,
        LicenceFeeCategory::PermitTemporary->value => 25200,
        LicenceFeeCategory::PermitSpecial->value => 12000,
        LicenceFeeCategory::SpecialClass->value => 42000,
        LicenceFeeCategory::RegistrationFee->value => 22800,
        LicenceFeeCategory::TradePlateApplication->value => 12000,
    ];

    foreach ($expected as $categoryValue => $cents) {
        $line = $draft->lines()
            ->where('licence_category', $categoryValue)
            ->whereNull('tare_min_kg')
            ->whereNull('tare_max_kg')
            ->first();

        expect($line)->not->toBeNull("missing flat line for {$categoryValue}")
            ->and($line->amount_cents)->toBe($cents, "wrong amount for {$categoryValue}");
    }
});

it('populates each province rigid vehicle band to its own gazetted amount', function () {
    // Spot-check one representative rigid band per province against the Foresight-sourced gazette.
    $expectations = [
        [Province::EasternCape, 1500, 59400],      // Prov Gaz 4049/2018 - R594
        [Province::FreeState, 1500, 63000],        // Prov Notice 41/2026 - R630
        [Province::KwaZuluNatal, 1500, 67200],     // Prov Gaz 2158/2020 - R672
        [Province::Limpopo, 1500, 81000],          // Prov Gaz 54375/2026 - R810
        [Province::Mpumalanga, 1500, 68400],       // Prov Gaz 3913/2026 - R684
        [Province::NorthernCape, 1500, 65400],     // Prov Gaz 2845/2026 - R654
        [Province::NorthWest, 1500, 58200],        // Prov Gaz 9027/2026 - R582
        [Province::WesternCape, 1500, 69000],      // Prov Gaz 9248/2026 - R690
    ];

    foreach ($expectations as [$province, $tareMax, $expectedCents]) {
        $draft = FeeTable::query()
            ->where('province', $province->value)
            ->firstOrFail()
            ->versions()
            ->where('status', 'draft')
            ->orderByDesc('version')
            ->firstOrFail();

        $amount = $draft->lines()
            ->where('licence_category', LicenceFeeCategory::MotorCar->value)
            ->where('tare_max_kg', $tareMax)
            ->value('amount_cents');

        expect($amount)->toBe($expectedCents, "{$province->value} rigid {$tareMax}kg");
    }
});

it('populates each province above-12 000 kg surcharge from its own gazette', function () {
    // The gazetted per-500 kg step above 12 000 kg differs materially between provinces.
    $steps = [
        [Province::Gauteng, 265200, 265200],
        [Province::EasternCape, 232200, 232200],
        [Province::FreeState, 280800, 280800],
        [Province::KwaZuluNatal, 253800, 253800],
        [Province::Limpopo, 204600, 202800],       // rigid 2046, trailer 2028
        [Province::Mpumalanga, 233000, 233000],
        [Province::NorthernCape, 292200, 292200],
        [Province::NorthWest, 249000, 244800],     // rigid 2490, trailer 2448
        [Province::WesternCape, 286800, 286800],
    ];

    foreach ($steps as [$province, $rigidStepCents, $trailerStepCents]) {
        $draft = FeeTable::query()
            ->where('province', $province->value)
            ->firstOrFail()
            ->versions()
            ->where('status', 'draft')
            ->orderByDesc('version')
            ->firstOrFail();

        $rigid12000 = $draft->lines()
            ->where('licence_category', LicenceFeeCategory::MotorCar->value)
            ->where('tare_max_kg', 12000)
            ->value('amount_cents');
        $rigid12500 = $draft->lines()
            ->where('licence_category', LicenceFeeCategory::MotorCar->value)
            ->where('tare_max_kg', 12500)
            ->value('amount_cents');

        expect($rigid12500 - $rigid12000)->toBe($rigidStepCents, "{$province->value} rigid step");

        $trailer12000 = $draft->lines()
            ->where('licence_category', LicenceFeeCategory::Trailer->value)
            ->where('tare_max_kg', 12000)
            ->value('amount_cents');
        $trailer12500 = $draft->lines()
            ->where('licence_category', LicenceFeeCategory::Trailer->value)
            ->where('tare_max_kg', 12500)
            ->value('amount_cents');

        expect($trailer12500 - $trailer12000)->toBe($trailerStepCents, "{$province->value} trailer step");
    }
});

it('populates each province flat fees from its own gazette', function () {
    $registrationFees = [
        [Province::Gauteng, 22800],
        [Province::EasternCape, 13200],
        [Province::FreeState, 13200],
        [Province::KwaZuluNatal, 13800],
        [Province::Limpopo, 18000],
        [Province::Mpumalanga, 13800],
        [Province::NorthernCape, 20400],
        [Province::NorthWest, 14400],
        [Province::WesternCape, 31000],
    ];

    foreach ($registrationFees as [$province, $expectedCents]) {
        $draft = FeeTable::query()
            ->where('province', $province->value)
            ->firstOrFail()
            ->versions()
            ->where('status', 'draft')
            ->orderByDesc('version')
            ->firstOrFail();

        $amount = $draft->lines()
            ->where('licence_category', LicenceFeeCategory::RegistrationFee->value)
            ->whereNull('tare_min_kg')
            ->whereNull('tare_max_kg')
            ->value('amount_cents');

        expect($amount)->toBe($expectedCents, "{$province->value} registration fee");
    }
});

it('never touches an active version', function () {
    $draft = gautengDraft();

    // Activate v2 and change one amount to a value that no seeder would produce.
    $draft->update(['status' => 'active']);
    $line = $draft->lines()->where('licence_category', LicenceFeeCategory::MotorCar->value)->where('tare_max_kg', 250)->first();
    $line->update(['amount_cents' => 999999]);

    $this->seed(LicenceFeeBandSeeder::class);
    $this->seed(LicenceFeeRateSeeder::class);

    expect($line->refresh()->amount_cents)->toBe(999999);
});

it('does not overwrite an admin-captured amount on the draft version', function () {
    $draft = gautengDraft();

    $line = $draft->lines()
        ->where('licence_category', LicenceFeeCategory::Taxi->value)
        ->first();

    $line->update(['amount_cents' => 123456]);

    $this->seed(LicenceFeeRateSeeder::class);

    expect($line->refresh()->amount_cents)->toBe(123456);
});

it('seeds the national R72 RTMC transaction fee on every provincial draft', function () {
    foreach (Province::cases() as $province) {
        $draft = FeeTable::query()
            ->where('province', $province->value)
            ->firstOrFail()
            ->versions()
            ->where('status', 'draft')
            ->orderByDesc('version')
            ->firstOrFail();

        $line = $draft->lines()
            ->where('code', 'rtmc_transaction_fee')
            ->first();

        expect($line)->not->toBeNull("missing RTMC fee for {$province->value}")
            ->and($line->amount_cents)->toBe(7200, "RTMC fee wrong for {$province->value}")
            ->and($line->licence_category)->toBe(LicenceFeeCategory::TransactionFee);
    }
});
