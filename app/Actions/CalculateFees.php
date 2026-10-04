<?php

namespace App\Actions;

use App\Enums\RequestType;
use App\Enums\ServiceType;
use App\Enums\TaxTreatment;
use App\Enums\VehicleCategory;
use App\Models\Application;
use App\Models\FeeLine;
use App\Models\FeeTableVersion;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

class CalculateFees
{
    /**
     * @return array{
     *     vat_basis_points: int,
     *     fee_table_version_id: int|null,
     *     lines: list<array{code: string, label: string, amount_cents: int, client_visible: bool, tax_treatment: string, period: string}>,
     *     taxable_subtotal_cents: int,
     *     exempt_subtotal_cents: int,
     *     vat_cents: int,
     *     total_cents: int
     * }
     */
    public function snapshot(Application $application): array
    {
        $version = $this->resolveVersion($application);

        $lines = [];

        if ($version !== null) {
            foreach ($version->lines as $line) {
                if ($this->applies($line, $application)) {
                    $lines[] = [
                        'code' => $line->code,
                        'label' => $line->label,
                        'amount_cents' => (int) $line->amount_cents,
                        'client_visible' => (bool) $line->client_visible,
                        'tax_treatment' => $line->tax_treatment?->value ?? TaxTreatment::Exempt->value,
                        'period' => $line->period?->value ?? 'once_off',
                    ];
                }
            }
        }

        $markupBasisPoints = (int) ($application->clientAccount?->markup_basis_points ?? 0);
        $preMarkupSubtotal = array_sum(array_column($lines, 'amount_cents'));
        $markup = (int) round($preMarkupSubtotal * $markupBasisPoints / 10000);

        if ($markup > 0) {
            $lines[] = [
                'code' => 'markup',
                'label' => 'Account markup',
                'amount_cents' => $markup,
                'client_visible' => false,
                'tax_treatment' => TaxTreatment::Standard->value,
                'period' => 'once_off',
            ];
        }

        $taxableSubtotal = 0;
        $exemptSubtotal = 0;

        foreach ($lines as $line) {
            if ($line['tax_treatment'] === TaxTreatment::Standard->value) {
                $taxableSubtotal += $line['amount_cents'];
            } else {
                $exemptSubtotal += $line['amount_cents'];
            }
        }

        $vatBasisPoints = (int) SystemSetting::current()->vat_basis_points;
        $vat = (int) round($taxableSubtotal * $vatBasisPoints / 10000);

        if ($vat > 0) {
            $lines[] = [
                'code' => 'vat',
                'label' => 'VAT ('.number_format($vatBasisPoints / 100, 1).'% on taxable items)',
                'amount_cents' => $vat,
                'client_visible' => true,
                'tax_treatment' => TaxTreatment::Standard->value,
                'period' => 'once_off',
            ];
        }

        return [
            'vat_basis_points' => $vatBasisPoints,
            'fee_table_version_id' => $version?->id,
            'lines' => $lines,
            'taxable_subtotal_cents' => $taxableSubtotal,
            'exempt_subtotal_cents' => $exemptSubtotal,
            'vat_cents' => $vat,
            'total_cents' => $taxableSubtotal + $exemptSubtotal + $vat,
        ];
    }

    /**
     * True when the application actually results in a licence disc being
     * issued. Both the provincial licence fee and the national RTMC
     * transaction fee hinge on this.
     */
    private function chargesLicence(Application $application): bool
    {
        if ($application->request_type === RequestType::LicenceRenewal) {
            return true;
        }

        if ($application->request_type === RequestType::NewRegistration) {
            return $application->service_type === ServiceType::RegisterAndLicense;
        }

        return false;
    }

    private function resolveVersion(Application $application): ?FeeTableVersion
    {
        $province = $application->province?->value;

        if ($province === null) {
            return null;
        }

        $today = Carbon::now()->toDateString();

        $query = FeeTableVersion::query()
            ->where('status', 'active')
            ->whereHas('feeTable', fn ($q) => $q->where('province', $province));

        $dated = (clone $query)
            ->where(function ($q) use ($today): void {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today);
            })
            ->where(function ($q) use ($today): void {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', $today);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $dated ?? $query->latest('id')->first();
    }

    private function applies(FeeLine $line, Application $application): bool
    {
        if ($line->code === 'licence' && ! $this->chargesLicence($application)) {
            return false;
        }

        if ($line->code === 'rtmc_transaction_fee' && ! $this->chargesLicence($application)) {
            return false;
        }

        if ($line->code === 'datafix') {
            $applies = $application->vehicle_category === VehicleCategory::Commercial
                || $application->request_type === RequestType::DataChange;

            if (! $applies) {
                return false;
            }
        }

        if ($line->service_type !== null && $line->service_type !== $application->service_type?->value) {
            return false;
        }

        if ($line->request_type !== null && $line->request_type !== $application->request_type?->value) {
            return false;
        }

        if ($line->vehicle_category !== null && $line->vehicle_category !== $application->vehicle_category?->value) {
            return false;
        }

        $tare = $application->vehicle?->tare_kg;

        if ($line->tare_min_kg !== null && ($tare === null || $tare < $line->tare_min_kg)) {
            return false;
        }

        if ($line->tare_max_kg !== null && ($tare === null || $tare > $line->tare_max_kg)) {
            return false;
        }

        return true;
    }
}
