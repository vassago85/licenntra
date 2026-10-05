<?php

namespace App\Models;

use App\Enums\LicenceFeeCategory;
use App\Enums\Province;
use App\Enums\TaxTreatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of a dealer-run licence-cost estimate.
 *
 * Every field that affects the final number is stored once at save time
 * and never recomputed: a saved estimate from last month must keep saying
 * what it originally said even if the active fee schedule changes.
 */
class LicenceEstimate extends Model
{
    protected $fillable = [
        'client_account_id',
        'created_by_id',
        'application_id',

        'province',
        'licence_category',
        'tare_kg',
        'applicable_date',

        'fee_table_version_id',
        'fee_table_version_number',
        'fee_table_effective_from',
        'fee_table_effective_until',
        'fee_line_label',
        'fee_line_tare_min_kg',
        'fee_line_tare_max_kg',

        'licence_fee_cents',
        'licence_fee_tax_treatment',
        'admin_charge_cents',
        'admin_charge_tax_treatment',
        'rtmc_transaction_fee_cents',
        'rtmc_transaction_fee_tax_treatment',
        'vat_basis_points',
        'vat_cents',
        'total_cents',

        'status',
        'confirmation_reason',

        'notes',
        'computed_at',
    ];

    public const STATUS_ESTIMATED = 'estimated';

    public const STATUS_CONFIRMATION_REQUIRED = 'confirmation_required';

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function feeTableVersion(): BelongsTo
    {
        return $this->belongsTo(FeeTableVersion::class);
    }

    protected function casts(): array
    {
        return [
            'province' => Province::class,
            'licence_category' => LicenceFeeCategory::class,
            'licence_fee_tax_treatment' => TaxTreatment::class,
            'admin_charge_tax_treatment' => TaxTreatment::class,
            'rtmc_transaction_fee_tax_treatment' => TaxTreatment::class,
            'applicable_date' => 'date',
            'fee_table_effective_from' => 'date',
            'fee_table_effective_until' => 'date',
            'computed_at' => 'datetime',
        ];
    }

    public function needsConfirmation(): bool
    {
        return $this->status === self::STATUS_CONFIRMATION_REQUIRED;
    }
}
