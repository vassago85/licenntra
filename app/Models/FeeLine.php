<?php

namespace App\Models;

use App\Enums\FeePeriod;
use App\Enums\LicenceFeeCategory;
use App\Enums\TaxTreatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeLine extends Model
{
    protected $fillable = [
        'fee_table_version_id', 'code', 'label', 'amount_cents', 'client_visible',
        'tax_treatment', 'period',
        'request_type', 'vehicle_category', 'licence_category', 'service_type', 'vehicle_class',
        'tare_min_kg', 'tare_max_kg', 'sort_order',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(FeeTableVersion::class, 'fee_table_version_id');
    }

    protected function casts(): array
    {
        return [
            'client_visible' => 'boolean',
            'tax_treatment' => TaxTreatment::class,
            'period' => FeePeriod::class,
            'licence_category' => LicenceFeeCategory::class,
        ];
    }
}
