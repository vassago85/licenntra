<?php

namespace Database\Seeders;

use App\Enums\FeePeriod;
use App\Enums\LicenceFeeCategory;
use App\Enums\Province;
use App\Enums\TaxTreatment;
use App\Models\FeeTable;
use App\Models\FeeTableVersion;
use Illuminate\Database\Seeder;

/**
 * Seeds the full South African provincial motor-vehicle licence fee
 * structure, modelled on the Gauteng gazette (Prov Gaz 292 of 12 August
 * 2025, effective 1 April 2025) which is the canonical schedule most
 * admins reference.
 *
 * The gazette prices by (tare band) x (vehicle-type column):
 *   - Rigid vehicles   -> populated under LicenceFeeCategory::MotorCar
 *   - Breakdown        -> BreakdownVehicle, two tiers
 *   - Tractor on road  -> TractorPublicRoad, four tiers
 *   - Trailers         -> Trailer
 * plus flat-fee categories for motorcycles, caravans, trade plates,
 * permits, special class and the fixed admin fees.
 *
 * Tare bands are the gazette's: 250 kg steps up to 7 500 kg, 500 kg steps
 * up to 12 000 kg, then 500 kg steps up to 32 000 kg carrying the
 * "+R2 652 per 500 kg" surcharge for rigid vehicles and trailers.
 *
 * Only runs on draft versions. If the current draft already has the
 * complete band set we don't touch it; otherwise we blow away the draft's
 * lines and reseed. Active versions are never touched.
 */
class LicenceFeeBandSeeder extends Seeder
{
    /**
     * Fee codes this seeder owns on the draft. Every other line on the draft
     * belongs to the licensing company and is left alone.
     */
    private const BAND_CODES = ['licence', 'rtmc_transaction_fee'];

    public function run(): void
    {
        foreach (Province::cases() as $province) {
            $this->seedProvince($province);
        }
    }

    private function seedProvince(Province $province): void
    {
        $table = FeeTable::query()->firstOrCreate(
            ['province' => $province->value],
            ['name' => $province->label().' licence fees'],
        );

        /** @var FeeTableVersion $version */
        $version = $table->versions()->firstOrCreate(
            ['version' => 2],
            [
                'status' => 'draft',
                'notes' => 'Full gazette-structure licence fees - amounts seeded with Gauteng rates and per-province multiplier. Review before activating.',
            ],
        );

        if ($version->status !== 'draft') {
            return;
        }

        $this->seedBands($version);
        $this->carryServiceFees($table, $version);
    }

    private function seedBands(FeeTableVersion $version): void
    {
        $expected = $this->bands();
        $bandLines = $version->lines()->whereIn('code', self::BAND_CODES);

        /**
         * Only wipe when the band count or structure drifts from the
         * expected gazette layout, so an admin who has already captured
         * province-specific numbers on the draft keeps their edits.
         */
        if ((clone $bandLines)->count() === count($expected)) {
            return;
        }

        $bandLines->delete();

        $sort = 0;

        foreach ($expected as [$category, $tareMin, $tareMax, $label]) {
            $sort += 10;

            $version->lines()->create([
                'code' => $this->codeFor($category),
                'label' => $label,
                'amount_cents' => 0,
                'client_visible' => true,
                'tax_treatment' => TaxTreatment::Exempt->value,
                'period' => FeePeriod::Annual->value,
                'licence_category' => $category->value,
                'tare_min_kg' => $tareMin,
                'tare_max_kg' => $tareMax,
                'sort_order' => $sort,
            ]);
        }
    }

    /**
     * The gazette only covers licence fees. Registration, datafix, admin,
     * runner and plate fees are copied from the live version so approving
     * the draft does not stop the company charging them.
     */
    private function carryServiceFees(FeeTable $table, FeeTableVersion $draft): void
    {
        $live = $table->versions()->where('status', 'active')->latest('version')->first();

        if ($live === null) {
            return;
        }

        foreach ($draft->linesMissingFrom($live) as $line) {
            $draft->lines()->create($line->only($line->getFillable()));
        }
    }

    /**
     * Map a licence fee category to the FeeLine code the calculator uses to
     * decide when it applies. Most lines are the provincial licence fee itself.
     * The RTMC transaction fee is a national pass-through that only applies
     * when a licence disc is being issued.
     */
    private function codeFor(LicenceFeeCategory $category): string
    {
        return match ($category) {
            LicenceFeeCategory::TransactionFee => 'rtmc_transaction_fee',
            default => 'licence',
        };
    }

    /**
     * The complete set of fee bands modelled on Gauteng Prov Gaz 292/2025.
     *
     * @return list<array{0: LicenceFeeCategory, 1: int|null, 2: int|null, 3: string}>
     */
    public function bands(): array
    {
        $bands = [
            // Motorcycle - flat annual fee.
            [LicenceFeeCategory::Motorcycle, null, null, 'Motorcycle (annual licence)'],

            // Caravan - flat annual fee.
            [LicenceFeeCategory::Caravan, null, null, 'Caravan (annual licence)'],
        ];

        $bands = array_merge($bands, $this->tareBands(
            LicenceFeeCategory::MotorCar,
            'Rigid vehicle',
        ));

        $bands = array_merge($bands, $this->tareBands(
            LicenceFeeCategory::Trailer,
            'Trailer / semi-trailer',
        ));

        $bands = array_merge($bands, [
            // Breakdown vehicles - gazette has two flat tiers.
            [LicenceFeeCategory::BreakdownVehicle, 0, 5000, 'Breakdown vehicle - up to 5 000 kg'],
            [LicenceFeeCategory::BreakdownVehicle, 5001, null, 'Breakdown vehicle - over 5 000 kg'],

            // Tractor on public road - four flat tiers.
            [LicenceFeeCategory::TractorPublicRoad, 0, 2000, 'Tractor on public road - up to 2 000 kg'],
            [LicenceFeeCategory::TractorPublicRoad, 2001, 4000, 'Tractor on public road - 2 001-4 000 kg'],
            [LicenceFeeCategory::TractorPublicRoad, 4001, 8000, 'Tractor on public road - 4 001-8 000 kg'],
            [LicenceFeeCategory::TractorPublicRoad, 8001, null, 'Tractor on public road - over 8 000 kg'],

            // Flat fees.
            [LicenceFeeCategory::Taxi, null, null, 'Taxi (annual licence)'],
            [LicenceFeeCategory::Dealer, null, null, 'Dealer plate (annual licence)'],
            [LicenceFeeCategory::TradePlateMotorcycle, null, null, 'Trade plate - motorcycle (annual)'],
            [LicenceFeeCategory::TradePlateOther, null, null, 'Trade plate - other vehicles (annual)'],
            [LicenceFeeCategory::PermitTemporary, null, null, 'Temporary permit (per issue)'],
            [LicenceFeeCategory::PermitSpecial, null, null, 'Special permit (per issue)'],
            [LicenceFeeCategory::SpecialClass, null, null, 'Special class - farming tractor / construction machinery (annual)'],
            [LicenceFeeCategory::RegistrationFee, null, null, 'Motor vehicle registration fee (per registration)'],
            [LicenceFeeCategory::TradePlateApplication, null, null, 'Application for motor trade plate number (per application)'],

            // National Road Traffic Management Corporation Act, 1999, section 48(1)(b).
            // Pass-through fee added to every licence transaction. Set at R72 per
            // Gazette 4673 of 14 January 2022.
            [LicenceFeeCategory::TransactionFee, null, null, 'RTMC transaction fee (added to each licence)'],
        ]);

        return $bands;
    }

    /**
     * The gazette's tare bands applied to one tare-based category.
     * 250 kg bands to 7 500 kg, 500 kg bands to 12 000 kg, 500 kg bands
     * to 32 000 kg (where the +R2 652 surcharge applies for rigid/trailer).
     *
     * @return list<array{0: LicenceFeeCategory, 1: int, 2: int, 3: string}>
     */
    private function tareBands(LicenceFeeCategory $category, string $prefix): array
    {
        $bands = [];
        $humanPrefix = $prefix;

        // 250 kg steps, 0 -> 7 500 kg. Bands are stored half-open at the top
        // so that a tare of exactly 500 kg sits in 251-500, not 501-750 — the
        // gazette's "part thereof" rule means every step up to and including
        // the top of a band belongs to that band, not the next one.
        for ($max = 250; $max <= 7500; $max += 250) {
            $min = $max === 250 ? 0 : $max - 250 + 1;
            $bands[] = [$category, $min, $max, $humanPrefix.' - '.$this->bandLabel($min, $max)];
        }

        // 500 kg steps, 7 500 -> 12 000 kg (gazette switches to 500 kg bands at 7 500).
        for ($max = 8000; $max <= 12000; $max += 500) {
            $min = $max - 500 + 1;
            $bands[] = [$category, $min, $max, $humanPrefix.' - '.$this->bandLabel($min, $max)];
        }

        // 500 kg steps above 12 000 kg carrying the gazette's surcharge rule.
        // Capped at 32 000 kg which covers heavy rigid trucks and the heaviest trailers.
        for ($max = 12500; $max <= 32000; $max += 500) {
            $min = $max - 500 + 1;
            $bands[] = [$category, $min, $max, $humanPrefix.' - '.$this->bandLabel($min, $max)];
        }

        return $bands;
    }

    private function bandLabel(int $min, int $max): string
    {
        $format = fn (int $kg): string => number_format($kg, 0, '.', ' ').' kg';

        if ($min === 0) {
            return 'up to '.$format($max);
        }

        return $format($min).'-'.$format($max);
    }
}
