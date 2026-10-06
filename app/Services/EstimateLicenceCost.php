<?php

namespace App\Services;

use App\Actions\CalculateFees;
use App\Enums\LicenceFeeCategory;
use App\Enums\Province;
use App\Enums\RequestType;
use App\Enums\TaxTreatment;
use App\Models\Application;
use App\Models\FeeLine;
use App\Models\FeeTableVersion;
use App\Models\LicenceEstimate;
use App\Models\SystemSetting;
use App\Models\Vehicle;
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
    public function __construct(
        private CalculateFees $fees,
    ) {}

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
     *     rtmc_transaction_fee_cents: int,
     *     rtmc_transaction_fee_tax_treatment: TaxTreatment,
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

        $version = $this->fees->versionFor($province, $applicableDate);

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

        $renewal = $this->renewalFor($province, $licenceCategory, $tareKg);
        $line = $this->fees->licenceBand($version, $renewal);
        $problem = $this->fees->licenceBandProblem($line, $licenceCategory, $tareKg);

        if ($problem !== null) {
            return $this->confirmationRequired(
                province: $province,
                licenceCategory: $licenceCategory,
                tareKg: $tareKg,
                applicableDate: $applicableDate,
                computedAt: $computedAt,
                settings: $settings,
                reason: $problem,
                version: $version,
                line: $line,
            );
        }

        $licenceCents = (int) $line->amount_cents;
        $licenceTax = $line->tax_treatment ?? TaxTreatment::Exempt;

        [$adminCents, $adminTax] = $this->adminCharge($version, $renewal);

        $rtmc = $this->resolveRtmcLine($version);
        $rtmcCents = $rtmc !== null ? (int) $rtmc->amount_cents : 0;
        $rtmcTax = $rtmc?->tax_treatment ?? TaxTreatment::Exempt;

        $vatBasisPoints = (int) $settings->vat_basis_points;

        $taxable = 0;
        if ($licenceTax === TaxTreatment::Standard) {
            $taxable += $licenceCents;
        }
        if ($adminTax === TaxTreatment::Standard) {
            $taxable += $adminCents;
        }
        if ($rtmcTax === TaxTreatment::Standard) {
            $taxable += $rtmcCents;
        }

        $vatCents = (int) round($taxable * $vatBasisPoints / 10000);

        $total = $licenceCents + $adminCents + $rtmcCents + $vatCents;

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
            'rtmc_transaction_fee_cents' => $rtmcCents,
            'rtmc_transaction_fee_tax_treatment' => $rtmcTax,
            'vat_basis_points' => $vatBasisPoints,
            'vat_cents' => $vatCents,
            'total_cents' => $total,
        ];
    }

    /**
     * The R72 national RTMC transaction fee lives on every fee version as a
     * FeeLine with code 'rtmc_transaction_fee'. It is a straight pass-through
     * to the licensing authority — not provincial, not VATable — so we lift
     * it off the version by code rather than running it through the band
     * resolver. If the version has no RTMC line (seed drift, legacy data),
     * the estimator silently omits it rather than guessing.
     */
    private function resolveRtmcLine(FeeTableVersion $version): ?FeeLine
    {
        return $version->lines()
            ->where('code', 'rtmc_transaction_fee')
            ->where('amount_cents', '>', 0)
            ->first();
    }

    /**
     * An unsaved licence-renewal application carrying the estimator inputs,
     * so the estimate is priced by the same rules as a real application.
     */
    private function renewalFor(Province $province, LicenceFeeCategory $licenceCategory, ?int $tareKg): Application
    {
        $renewal = new Application;
        $renewal->forceFill([
            'province' => $province,
            'request_type' => RequestType::LicenceRenewal,
            'licence_category' => $licenceCategory,
        ]);
        $renewal->setRelation('vehicle', new Vehicle(['tare_kg' => $tareKg]));

        return $renewal;
    }

    /**
     * The admin charge configured on the fee table, the same lines an
     * application estimate charges.
     *
     * @return array{0: int, 1: TaxTreatment}
     */
    private function adminCharge(FeeTableVersion $version, Application $renewal): array
    {
        $lines = $this->fees->adminCharges($version, $renewal);

        return [
            array_sum(array_map(fn (FeeLine $line): int => (int) $line->amount_cents, $lines)),
            ($lines[0] ?? null)?->tax_treatment ?? TaxTreatment::Standard,
        ];
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
        [$adminCents, $adminTax] = $version !== null
            ? $this->adminCharge($version, $this->renewalFor($province, $licenceCategory, $tareKg))
            : [0, TaxTreatment::Standard];

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
            'admin_charge_cents' => $adminCents,
            'admin_charge_tax_treatment' => $adminTax,
            'rtmc_transaction_fee_cents' => 0,
            'rtmc_transaction_fee_tax_treatment' => TaxTreatment::Exempt,
            'vat_basis_points' => (int) $settings->vat_basis_points,
            'vat_cents' => 0,
            'total_cents' => 0,
        ];
    }
}
