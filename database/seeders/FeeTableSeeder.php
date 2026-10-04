<?php

namespace Database\Seeders;

use App\Enums\FeePeriod;
use App\Enums\Province;
use App\Enums\TaxTreatment;
use App\Models\FeeTable;
use Illuminate\Database\Seeder;

class FeeTableSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Province::cases() as $province) {
            $table = FeeTable::query()->updateOrCreate(
                ['province' => $province->value],
                ['name' => $province->label().' fees'],
            );

            $version = $table->versions()->updateOrCreate(
                ['version' => 1],
                ['status' => 'active'],
            );

            /**
             * Each row: [code, label, cents, service, category, tax_treatment, period].
             *
             * - 'licence' is the annual provincial licence fee: exempt from VAT.
             * - 'registration' is a once-off government fee: exempt.
             * - 'datafix' is a once-off government fee: exempt.
             * - 'admin', 'plates', 'runner' are our own service fees: standard-rated.
             */
            $lines = [
                ['registration', 'Authority registration fee (demo)', 50000, null, null, TaxTreatment::Exempt, FeePeriod::OnceOff],
                ['licence', 'Annual licence fee (demo)', 80000, 'register_and_license', null, TaxTreatment::Exempt, FeePeriod::Annual],
                ['datafix', 'Datafix fee (demo)', 35000, null, 'commercial', TaxTreatment::Exempt, FeePeriod::OnceOff],
                ['admin', 'Admin fee (demo)', 15000, null, null, TaxTreatment::Standard, FeePeriod::OnceOff],
                ['runner', 'Runner fee (demo)', 0, null, null, TaxTreatment::Standard, FeePeriod::OnceOff],
                ['plates', 'Number plate fee (demo)', 20000, 'register_and_license', null, TaxTreatment::Standard, FeePeriod::OnceOff],
            ];

            foreach ($lines as [$code, $label, $cents, $service, $category, $tax, $period]) {
                $version->lines()->updateOrCreate(
                    ['code' => $code, 'service_type' => $service, 'vehicle_category' => $category],
                    [
                        'label' => $label,
                        'amount_cents' => $cents,
                        'client_visible' => $code !== 'runner',
                        'tax_treatment' => $tax->value,
                        'period' => $period->value,
                    ],
                );
            }
        }
    }
}
