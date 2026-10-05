<?php

namespace App\Services;

use App\Enums\LicenceFeeCategory;
use App\Enums\Province;
use App\Enums\TaxTreatment;
use App\Models\FeeLine;
use App\Models\FeeTableVersion;
use App\Models\LicenceEstimate;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * Computes a licence-cost estimate for a dealer.
 *
 * This is deliberately conservative. If anything about the fee schedule is
 * missing or ambiguous the result is marked `confirmation_required` with
 * zero figures and a human-readable reason — never a guessed amount, never
 * a silently expired rate.
 */
class EstimateLicenceCost
{
    /**
     * @return array{
     *     status: string,
     *     confirmation_reason: ?string,
     *     province: Province,
     *     licence_category: LicenceFeeCategory,
     *     tare_kg: ?int,
     *     applicable_date: Carbon,
     *     computed_at: Carbon,
     *     fee_table_version_id: ?int,
     *     fee_table_version_number: ?int,
     *     fee_table_effective_from: ?Carbon,
     *     fee_table_effective_until: ?Carbon,
     *     fee_line_label: ?string,
     *     fee_line_tare_min_kg: ?int,
     *     fee_line_tare_max_kg: ?int,
     *     licence_fee_cents: int,
     *     licence_fee_tax_treatment: TaxTreatment,
     *     admin_charge_cents: int,
     *     admin_charge_tax_treatment: TaxTreatment,
     *     vat_basis_points: int,
     *     vat_cents: int,
     *     total_cents: int
     * }
     */
    public function compute(
        Province $province,
        LicenceFeeCategory $licenceCategory,
        ?int $tareKg,
        Carbon $applicableDate,
    ): array {
        $settings = SystemSetting::current();
        $computedAt = Carbon::now();

        $version = $this->resolveVersion($province, $applicableDate);

        if ($version === null) {
            return $this->confirmationRequired(
                province: $province,
                licenceCategory: $licenceCategory,
                tareKg: $tareKg,
                applicableDate: $applicableDate,
                computedAt: $computedAt,
                settings: $settings,
                reason: 'No approved fee schedule is active for '.$province->label().' on '.$applicableDate->toDateString().'.',
            );
        }

        $line = $this->resolveLine($version, $licenceCategory, $tareKg);

        if ($line === null) {
            return $this->confirmationRequired(
                province: $province,
                licenceCategory: $licenceCategory,
                tareKg: $tareKg,
                applicableDate: $applicableDate,
                computedAt: $computedAt,
                settings: $settings,
                reason: $this->matchFailureReason($licenceCategory, $tareKg),
                version: $version,
            );
        }

        if ((int) $line->amount_cents <= 0) {
            // A seeded placeholder (0) must never be shown as a real zero fee.
            return $this->confirmationRequired(
                province: $province,
                licenceCategory: $licenceCategory,
                tareKg: $tareKg,
                applicableDate: $applicableDate,
                computedAt: $computedAt,
                settings: $settings,
                reason: 'The matching band exists but has no approved amount captured yet. Ask the licensing company to confirm the rate before quoting.',
                version: $version,
                line: $line,
            );
        }

        $licenceCents = (int) $line->amount_cents;
        $licenceTax = $line->tax_treatment ?? TaxTreatment::Exempt;

        $adminCents = (int) ($settings->admin_charge_cents ?? 0);
        $adminTax = TaxTreatment::tryFrom((string) ($settings->admin_charge_tax_treatment ?? TaxTreatment::Standard->value))
            ?? TaxTreatment::Standard;

        $vatBasisPoints = (int) $settings->vat_basis_points;

        $taxable = 0;
        if ($licenceTax === TaxTreatment::Standard) {
            $taxable += $licenceCents;
        }
        if ($adminTax === TaxTreatment::Standard) {
            $taxable += $adminCents;
        }

        $vatCents = (int) round($taxable * $vatBasisPoints / 10000);

        $total = $licenceCents + $adminCents + $vatCents;

        return [
            'status' => LicenceEstimate::STATUS_ESTIMATED,
            'confirmation_reason' => null,
            'province' => $province,
            'licence_category' => $licenceCategory,
            'tare_kg' => $tareKg,
            'applicable_date' => $applicableDate,
            'computed_at' => $computedAt,
            'fee_table_version_id' => $version->id,
            'fee_table_version_number' => (int) $version->version,
            'fee_table_effective_from' => $version->effective_from ? Carbon::parse($version->effective_from) : null,
            'fee_table_effective_until' => $version->effective_until ? Carbon::parse($version->effective_until) : null,
            'fee_line_label' => $line->label,
            'fee_line_tare_min_kg' => $line->tare_min_kg !== null ? (int) $line->tare_min_kg : null,
            'fee_line_tare_max_kg' => $line->tare_max_kg !== null ? (int) $line->tare_max_kg : null,
            'licence_fee_cents' => $licenceCents,
            'licence_fee_tax_treatment' => $licenceTax,
            'admin_charge_cents' => $adminCents,
            'admin_charge_tax_treatment' => $adminTax,
            'vat_basis_points' => $vatBasisPoints,
            'vat_cents' => $vatCents,
            'total_cents' => $total,
        ];
    }

    /**
     * Pick the single active fee schedule version whose effective window
     * covers the given date. Returning `null` is the trigger for the
     * "fee confirmation required" branch above — do not fall back to an
     * expired version.
     */
    private function resolveVersion(Province $province, Carbon $applicableDate): ?FeeTableVersion
    {
        $date = $applicableDate->toDateString();

        return FeeTableVersion::query()
            ->where('status', 'active')
            ->whereHas('feeTable', fn ($q) => $q->where('province', $province->value))
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
            })
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    private function resolveLine(FeeTableVersion $version, LicenceFeeCategory $category, ?int $tareKg): ?FeeLine
    {
        $query = $version->lines()
            ->where('licence_category', $category->value);

        if ($tareKg !== null) {
            $query
                ->where(function ($q) use ($tareKg): void {
                    $q->whereNull('tare_min_kg')->orWhere('tare_min_kg', '<=', $tareKg);
                })
                ->where(function ($q) use ($tareKg): void {
                    $q->whereNull('tare_max_kg')->orWhere('tare_max_kg', '>=', $tareKg);
                });
        } else {
            // Without a tare value, prefer the single band that has no tare
            // restrictions. If every band has a weight constraint, we can't
            // pick one unambiguously.
            $query->whereNull('tare_min_kg')->whereNull('tare_max_kg');
        }

        // Prefer the specific (tare-bound) band over an unconstrained one;
        // for tare-bound candidates prefer the LOWEST tare_min_kg so a tare
        // sitting on a band boundary (e.g. 18 500 falling into both the
        // 18 001-18 500 and 18 501-19 000 bands if their edges overlap)
        // resolves to the lower band. The gazette rule "500 kg or part
        // thereof" means a vehicle at exactly the top of a band belongs
        // to that band, not the next one up.
        return $query
            ->orderByRaw('CASE WHEN tare_min_kg IS NULL THEN 1 ELSE 0 END')
            ->orderBy('tare_min_kg')
            ->first();
    }

    private function matchFailureReason(LicenceFeeCategory $category, ?int $tareKg): string
    {
        if ($tareKg === null) {
            return 'No tare-independent band exists for '.$category->label().'. Enter the tare weight so the correct band can be matched.';
        }

        return 'No fee band matches '.$category->label().' at '.$tareKg.' kg. Ask the licensing company to confirm the applicable rate.';
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmationRequired(
        Province $province,
        LicenceFeeCategory $licenceCategory,
        ?int $tareKg,
        Carbon $applicableDate,
        Carbon $computedAt,
        SystemSetting $settings,
        string $reason,
        ?FeeTableVersion $version = null,
        ?FeeLine $line = null,
    ): array {
        return [
            'status' => LicenceEstimate::STATUS_CONFIRMATION_REQUIRED,
            'confirmation_reason' => $reason,
            'province' => $province,
            'licence_category' => $licenceCategory,
            'tare_kg' => $tareKg,
            'applicable_date' => $applicableDate,
            'computed_at' => $computedAt,
            'fee_table_version_id' => $version?->id,
            'fee_table_version_number' => $version !== null ? (int) $version->version : null,
            'fee_table_effective_from' => $version?->effective_from ? Carbon::parse($version->effective_from) : null,
            'fee_table_effective_until' => $version?->effective_until ? Carbon::parse($version->effective_until) : null,
            'fee_line_label' => $line?->label,
            'fee_line_tare_min_kg' => $line?->tare_min_kg !== null ? (int) $line?->tare_min_kg : null,
            'fee_line_tare_max_kg' => $line?->tare_max_kg !== null ? (int) $line?->tare_max_kg : null,
            'licence_fee_cents' => 0,
            'licence_fee_tax_treatment' => TaxTreatment::Exempt,
            'admin_charge_cents' => (int) ($settings->admin_charge_cents ?? 0),
            'admin_charge_tax_treatment' => TaxTreatment::tryFrom((string) ($settings->admin_charge_tax_treatment ?? TaxTreatment::Standard->value)) ?? TaxTreatment::Standard,
            'vat_basis_points' => (int) $settings->vat_basis_points,
            'vat_cents' => 0,
            'total_cents' => 0,
        ];
    }
}
