<?php

namespace App\Actions;

use App\Enums\QuoteStatus;
use App\Enums\RequestType;
use App\Enums\ServiceType;
use App\Enums\TaxTreatment;
use App\Enums\VehicleCategory;
use App\Models\Application;
use App\Models\FeeLine;
use App\Models\FeeTableVersion;
use App\Models\QuoteLine;
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
        $version = $this->versionInEffect($application);

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
     * The amount the client is billed. An accepted quote is the full agreed
     * price and replaces the fee table; quote prices are all-in, so no VAT is
     * added on top. Without an accepted quote the fee table (plus account
     * markup) applies.
     *
     * @return array{
     *     vat_basis_points: int,
     *     fee_table_version_id: int|null,
     *     quote_id?: int,
     *     lines: list<array{code: string, label: string, amount_cents: int, client_visible: bool, tax_treatment: string, period: string}>,
     *     taxable_subtotal_cents: int,
     *     exempt_subtotal_cents: int,
     *     vat_cents: int,
     *     total_cents: int
     * }
     */
    public function billingSnapshot(Application $application): array
    {
        $quote = $application->quotes()
            ->where('status', QuoteStatus::Accepted)
            ->with('lines')
            ->latest('id')
            ->first();

        if ($quote === null) {
            return $this->snapshot($application);
        }

        $lines = $quote->lines->map(fn (QuoteLine $line): array => [
            'code' => 'quote',
            'label' => $line->description,
            'amount_cents' => (int) $line->client_price_cents,
            'client_visible' => true,
            'tax_treatment' => TaxTreatment::Standard->value,
            'period' => 'once_off',
        ])->values()->all();

        $total = array_sum(array_column($lines, 'amount_cents'));

        return [
            'vat_basis_points' => (int) SystemSetting::current()->vat_basis_points,
            'fee_table_version_id' => null,
            'quote_id' => $quote->id,
            'lines' => $lines,
            'taxable_subtotal_cents' => $total,
            'exempt_subtotal_cents' => 0,
            'vat_cents' => 0,
            'total_cents' => $total,
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

    /**
     * The active fee table version for the application's province that is in
     * effect today. Expired and future-dated versions never price an
     * application, matching the dealer estimator.
     */
    public function versionInEffect(Application $application): ?FeeTableVersion
    {
        $province = $application->province?->value;

        if ($province === null) {
            return null;
        }

        $today = Carbon::now()->toDateString();

        return FeeTableVersion::query()
            ->where('status', 'active')
            ->whereHas('feeTable', fn ($q) => $q->where('province', $province))
            ->where(function ($q) use ($today): void {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today);
            })
            ->where(function ($q) use ($today): void {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', $today);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    private function applies(FeeLine $line, Application $application): bool
    {
        if ($line->code === 'licence') {
            if (! $this->chargesLicence($application)) {
                return false;
            }

            // Gazette licence fees are priced by LicenceFeeCategory (Rigid
            // vehicle, Trailer, Motorcycle, Caravan, Taxi, Breakdown,
            // Tractor-on-public-road, etc). Every category has its own
            // table of tare bands; without this filter a 6 500 kg truck
            // matches the rigid-vehicle band AND the trailer band AND
            // the breakdown band at the same time - fee estimate blows
            // out to 15+ lines. The application captures the chosen
            // category so the estimator only shows the one that applies.
            if ($line->licence_category !== null
                && $application->licence_category !== null
                && $line->licence_category !== $application->licence_category) {
                return false;
            }

            // No licence category pinned yet (brand-new draft) - skip
            // the band lines so we don't double-charge; the admin
            // charges and RTMC fee still appear so the estimate stays
            // meaningful while the dealer is still filling in the form.
            if ($line->licence_category !== null && $application->licence_category === null) {
                return false;
            }
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
