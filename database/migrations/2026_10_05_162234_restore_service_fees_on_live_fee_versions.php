<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The seeded gazette fee versions only carried licence bands. Approving them
 * superseded the version holding the registration, datafix, admin, runner and
 * plate fees, so those stopped being charged. For every live version, copy
 * back any fee code the version it replaced charged and it does not, except a
 * flat 'licence' line where the live version already prices licences by band.
 * Safe to run more than once.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'code', 'label', 'amount_cents', 'client_visible', 'tax_treatment', 'period',
            'request_type', 'vehicle_category', 'licence_category', 'service_type', 'vehicle_class',
            'tare_min_kg', 'tare_max_kg', 'sort_order',
        ];

        foreach (DB::table('fee_table_versions')->where('status', 'active')->get() as $live) {
            $previous = DB::table('fee_table_versions')
                ->where('fee_table_id', $live->fee_table_id)
                ->where('status', 'superseded')
                ->where('version', '<', $live->version)
                ->orderByDesc('version')
                ->first();

            if ($previous === null) {
                continue;
            }

            $liveLines = DB::table('fee_lines')->where('fee_table_version_id', $live->id);
            $codes = (clone $liveLines)->distinct()->pluck('code')->all();
            $pricesLicenceByBand = (clone $liveLines)->where('code', 'licence')->whereNotNull('licence_category')->exists();

            $missing = DB::table('fee_lines')
                ->where('fee_table_version_id', $previous->id)
                ->whereNotIn('code', $codes)
                ->when($pricesLicenceByBand, fn ($query) => $query->where('code', '!=', 'licence'))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($missing as $line) {
                DB::table('fee_lines')->insert(
                    ['fee_table_version_id' => $live->id, 'created_at' => now(), 'updated_at' => now()]
                    + array_intersect_key((array) $line, array_flip($columns)),
                );
            }
        }
    }

    public function down(): void
    {
        //
    }
};
