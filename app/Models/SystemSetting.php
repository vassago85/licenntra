<?php

namespace App\Models;

use App\Enums\ApplicationStage;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    public const DEFAULT_WARNING_HOURS = 48;

    protected $fillable = [
        'vat_basis_points', 'idle_timeout_minutes', 'absolute_timeout_minutes',
        'retention_period_options', 'retention_max_months', 'archive_after_days',
        'stage_warning_hours', 'enforce_client_two_factor', 'retention_wording_version', 'retention_wording',
        'mailgun_domain', 'mailgun_secret', 'mailgun_endpoint',
        'mail_from_address', 'mail_from_name', 'notifications_enabled',
        'quotes_enabled', 'payment_tracking_required',
        'admin_charge_cents', 'admin_charge_tax_treatment',
        'platform_fee_per_transaction_cents',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'vat_basis_points' => 1500,
            'retention_period_options' => [3, 6, 12, 24],
            'retention_max_months' => 24,
            'archive_after_days' => 90,
            'stage_warning_hours' => [],
            'retention_wording_version' => '1',
            'retention_wording' => 'I confirm I have this business\'s authorisation to keep these documents for the selected period.',
            'notifications_enabled' => true,
            'quotes_enabled' => false,
            'payment_tracking_required' => false,
            'admin_charge_cents' => 0,
            'admin_charge_tax_treatment' => 'standard',
            'platform_fee_per_transaction_cents' => 0,
        ]);
    }

    public function hasMailgunCredentials(): bool
    {
        return filled($this->mailgun_domain) && filled($this->mailgun_secret);
    }

    public function resolvedFromAddress(): ?string
    {
        return $this->mail_from_address
            ?: config('mail.from.address')
            ?: null;
    }

    public function resolvedFromName(): ?string
    {
        return $this->mail_from_name
            ?: BrandingSetting::current()->company_name
            ?: config('mail.from.name');
    }

    /**
     * Hours an application may sit in a step before it is flagged as waiting
     * too long. Null means the step never raises a warning. Steps the
     * licensing company has not configured fall back to the default.
     */
    public function warningHoursFor(ApplicationStage $stage): ?int
    {
        if (! in_array($stage, ApplicationStage::warningStages(), true)) {
            return null;
        }

        $configured = $this->stage_warning_hours ?? [];

        if (! array_key_exists($stage->value, $configured)) {
            return self::DEFAULT_WARNING_HOURS;
        }

        $hours = $configured[$stage->value];

        return is_numeric($hours) && (int) $hours > 0 ? (int) $hours : null;
    }

    protected function casts(): array
    {
        return [
            'retention_period_options' => 'array',
            'stage_warning_hours' => 'array',
            'enforce_client_two_factor' => 'boolean',
            'notifications_enabled' => 'boolean',
            'quotes_enabled' => 'boolean',
            'payment_tracking_required' => 'boolean',
            'mailgun_secret' => 'encrypted',
        ];
    }
}
