<?php

namespace Database\Seeders;

use App\Enums\Province;
use App\Models\FeeLine;
use App\Models\FeeTable;
use App\Models\FeeTableVersion;
use Database\Data\ProvincialLicenceFees;
use Illuminate\Database\Seeder;

/**
 * Populates the licence fee amounts for the current draft version using
 * actual per-province gazette data (sourced from Foresight Publications'
 * motor-vehicle licence fee tables). Each province carries its own gazette
 * reference inside {@see ProvincialLicenceFees}.
 *
 * Only runs on draft versions and only fills rows that are still at R0, so
 * admins who have already captured a specific rate keep their edits. Where
 * a province's gazette does not price a specific tare band the seeder
 * falls back to Gauteng x the province's heavy-vehicle multiplier so the
 * ladder never leaves gaps.
 */
class LicenceFeeRateSeeder extends Seeder
{
    public function run(): void
    {
        $data = ProvincialLicenceFees::data();
        $gauteng = $data['gauteng'];

        foreach (Province::cases() as $province) {
            $provinceData = $data[$province->value] ?? null;

            if ($provinceData === null) {
                continue;
            }

            $this->seedProvince($province, $provinceData, $gauteng);
        }
    }

    /**
     * @param  array<string, mixed>  $provinceData
     * @param  array<string, mixed>  $gauteng
     */
    private function seedProvince(Province $province, array $provinceData, array $gauteng): void
    {
        $table = FeeTable::query()->where('province', $province->value)->first();

        if (! $table) {
            return;
        }

        /** @var FeeTableVersion|null $version */
        $version = $table->versions()
            ->where('status', 'draft')
            ->orderByDesc('version')
            ->first();

        if (! $version) {
            return;
        }

        $rates = $this->buildRateMap($provinceData, $gauteng);

        $version->lines()
            ->where('code', 'licence')
            ->where('amount_cents', 0)
            ->get()
            ->each(function (FeeLine $line) use ($rates): void {
                $key = $this->bandKey($line);
                $amountRand = $rates[$key] ?? null;

                if ($amountRand === null) {
                    return;
                }

                $line->update([
                    'amount_cents' => $this->roundToWholeRand((int) round($amountRand * 100)),
                ]);
            });

        // RTMC transaction fee - R72 nationally, Gazette 4673 of 14 January 2022.
        $version->lines()
            ->where('code', 'rtmc_transaction_fee')
            ->where('amount_cents', 0)
            ->update(['amount_cents' => 7200]);
    }

    private function bandKey(FeeLine $line): string
    {
        $category = $line->licence_category?->value ?? 'unknown';
        $min = $line->tare_min_kg ?? 'null';
        $max = $line->tare_max_kg ?? 'null';

        return $category.'|'.$min.'|'.$max;
    }

    private function roundToWholeRand(int $cents): int
    {
        return (int) (round($cents / 100) * 100);
    }

    /**
     * Build the "category|tare_min|tare_max" => rand amount map for a province.
     * Falls back to Gauteng x the province's computed heavy multiplier where
     * the province's own gazette does not price that specific band.
     *
     * @param  array<string, mixed>  $provinceData
     * @param  array<string, mixed>  $gauteng
     * @return array<string, float>
     */
    private function buildRateMap(array $provinceData, array $gauteng): array
    {
        $out = [];

        // Flat annual fees.
        $flat = $provinceData['flat'];
        $out['motorcycle|null|null'] = (float) $flat['motorcycle'];
        $out['caravan|null|null'] = (float) $flat['caravan'];
        $out['dealer|null|null'] = (float) $flat['dealer'];
        $out['trade_plate_motorcycle|null|null'] = (float) $flat['trade_plate_motorcycle'];
        $out['trade_plate_other|null|null'] = (float) $flat['trade_plate_other'];
        $out['permit_temporary|null|null'] = (float) $flat['permit_temporary'];
        $out['permit_special|null|null'] = (float) $flat['permit_special'];
        $out['special_class|null|null'] = (float) $flat['special_class'];
        $out['registration_fee|null|null'] = (float) $flat['registration_fee'];
        $out['trade_plate_application|null|null'] = (float) $flat['trade_plate_application'];

        // Breakdown vehicles - two tiers.
        $out['breakdown_vehicle|0|5000'] = (float) $provinceData['breakdown']['light'];
        $out['breakdown_vehicle|5001|null'] = (float) $provinceData['breakdown']['heavy'];

        // Tractor on public road - four tiers.
        $tractor = $provinceData['tractor_public_road'];
        $out['tractor_public_road|0|2000'] = (float) $tractor['t1'];
        $out['tractor_public_road|2001|4000'] = (float) $tractor['t2'];
        $out['tractor_public_road|4001|8000'] = (float) $tractor['t3'];
        $out['tractor_public_road|8001|null'] = (float) $tractor['t4'];

        // Rigid vehicle and trailer tare bands.
        $multiplier = $this->computeHeavyMultiplier($provinceData, $gauteng);
        $this->populateTareBands($out, 'motor_car', $provinceData['rigid'], $gauteng['rigid'], $multiplier);
        $this->populateTareBands($out, 'trailer', $provinceData['trailer'], $gauteng['trailer'], $multiplier);

        // Above-12 000 kg surcharge (per 500 kg) applied step-wise up to 32 000 kg.
        $this->populateAbove12000(
            $out,
            'motor_car',
            $provinceData['rigid'][12000] ?? $gauteng['rigid'][12000] * $multiplier,
            (int) $provinceData['above_12000_rigid_step']
        );
        $this->populateAbove12000(
            $out,
            'trailer',
            $provinceData['trailer'][12000] ?? $gauteng['trailer'][12000] * $multiplier,
            (int) $provinceData['above_12000_trailer_step']
        );

        return $out;
    }

    /**
     * @param  array<string, float>  $out
     * @param  array<int, int|null>  $provinceBands
     * @param  array<int, int>  $gautengBands
     */
    private function populateTareBands(array &$out, string $category, array $provinceBands, array $gautengBands, float $multiplier): void
    {
        $tareMaxValues = ProvincialLicenceFees::TARE_MAX_BANDS;
        $prevMax = 0;

        foreach ($tareMaxValues as $max) {
            // Bands are half-open at the top: 0-250, 251-500, 501-750, ...
            // so the min of each band after the first is prevMax + 1, matching
            // LicenceFeeBandSeeder's tare_min_kg values.
            $min = $prevMax === 0 ? 0 : $prevMax + 1;
            $prevMax = $max;

            $value = $provinceBands[$max] ?? null;

            if ($value === null) {
                $gautengValue = $gautengBands[$max] ?? null;

                if ($gautengValue === null) {
                    continue;
                }

                $value = (int) round($gautengValue * $multiplier);
            }

            $out[$category.'|'.$min.'|'.$max] = (float) $value;
        }
    }

    /**
     * @param  array<string, float>  $out
     */
    private function populateAbove12000(array &$out, string $category, float $start12000, int $stepRand): void
    {
        $current = $start12000;

        for ($max = 12500; $max <= 32000; $max += 500) {
            $current += $stepRand;
            // Bands above 12 000 kg use the same half-open rule: 12 001-12 500,
            // 12 501-13 000, ..., matching LicenceFeeBandSeeder's tare_min_kg.
            $out[$category.'|'.($max - 500 + 1).'|'.$max] = $current;
        }
    }

    /**
     * Derive a province's heavy-vehicle multiplier vs Gauteng using the
     * 11 500 - 12 000 kg rigid band (the largest shared band). Used only to
     * fill gaps in a province's gazette extraction.
     *
     * @param  array<string, mixed>  $provinceData
     * @param  array<string, mixed>  $gauteng
     */
    private function computeHeavyMultiplier(array $provinceData, array $gauteng): float
    {
        $provinceAnchor = $provinceData['rigid'][12000] ?? null;
        $gautengAnchor = $gauteng['rigid'][12000] ?? null;

        if ($provinceAnchor === null || $gautengAnchor === null || $gautengAnchor === 0) {
            return 1.0;
        }

        return $provinceAnchor / $gautengAnchor;
    }
}
