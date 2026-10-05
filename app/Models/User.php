<?php

namespace App\Models;

use App\Enums\OffboardReason;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name',
    'email',
    'password',
    'client_account_id',
    'is_active',
    'offboarded_at',
    'offboard_reason',
    'offboard_note',
    'offboarded_by_id',
    'anonymised_at',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * How long an offboarded staff record is retained in identifiable form
     * before the scheduled anonymisation sweep scrubs its PII.
     */
    public const RETENTION_YEARS = 5;

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function offboardedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'offboarded_by_id');
    }

    public function isClient(): bool
    {
        return $this->client_account_id !== null;
    }

    public function isLicensingStaff(): bool
    {
        return $this->hasAnyRole(['super_admin', 'customer_admin', 'reviewer', 'finance', 'auditor']);
    }

    /**
     * The licensing company owner. In this product the super_admin IS the
     * owner for platform-billing-visibility purposes - they can see the
     * running completed-transaction counter and the resulting bill, but
     * cannot change the per-transaction fee itself.
     */
    public function isOwner(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Charsley Digital platform staff. Can set the per-completed-
     * transaction fee that the owner is billed, and can see the counter
     * alongside the owner. Not a licensing-company role.
     */
    public function isDeveloper(): bool
    {
        return $this->hasRole('developer');
    }

    public function isOffboarded(): bool
    {
        return $this->offboarded_at !== null;
    }

    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    public function retentionEndsAt(): ?CarbonImmutable
    {
        if ($this->offboarded_at === null) {
            return null;
        }

        return CarbonImmutable::parse($this->offboarded_at)->addYears(self::RETENTION_YEARS);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active
            && ! $this->isOffboarded()
            && $this->hasAnyRole([
                'super_admin',
                'customer_admin',
                'reviewer',
                'finance',
                'auditor',
                'developer',
            ]);
    }

    /**
     * Admin-only roles that may edit configuration (fees, rules, users,
     * branding, system settings). Reviewers, finance and auditors can
     * reach the panel but must not see these pages.
     */
    public function canConfigure(): bool
    {
        return $this->is_active
            && ! $this->isOffboarded()
            && $this->hasAnyRole(['super_admin', 'customer_admin']);
    }

    public function canAcceptQuotes(): bool
    {
        if ($this->hasRole('client_admin')) {
            return true;
        }

        return $this->hasRole('client_user') && (bool) $this->clientAccount?->quote_acceptance_allowed;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'offboarded_at' => 'datetime',
            'offboard_reason' => OffboardReason::class,
            'anonymised_at' => 'datetime',
        ];
    }
}
