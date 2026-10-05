<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'vat_basis_points', 'idle_timeout_minutes', 'absolute_timeout_minutes',
        'retention_period_options', 'retention_max_months', 'archive_after_days',
        'sla_hours', 'enforce_client_two_factor', 'retention_wording_version', 'retention_wording',
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
            'sla_hours' => [],
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

    public function slaHoursFor(string $stage): int
    {
        $hours = $this->sla_hours[$stage] ?? null;

        // ASSUMPTION: 48 hours per stage until the licensing company sets its own SLA.
        return is_numeric($hours) ? (int) $hours : 48;
    }

    protected function casts(): array
    {
        return [
            'retention_period_options' => 'array',
            'sla_hours' => 'array',
            'enforce_client_two_factor' => 'boolean',
            'notifications_enabled' => 'boolean',
            'quotes_enabled' => 'boolean',
            'payment_tracking_required' => 'boolean',
            'mailgun_secret' => 'encrypted',
        ];
    }
}
