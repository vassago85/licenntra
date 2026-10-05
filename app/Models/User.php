<?php

namespace App\Models;

use App\Enums\OffboardReason;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
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
class User extends Authenticatable
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

    /**
     * Any staff member of the licensing company (as opposed to a dealer/
     * fleet staff member on the customer side).
     */
    public function isLicensingStaff(): bool
    {
        return $this->hasAnyRole(['owner', 'reviewer', 'finance']);
    }

    /**
     * The licensing company owner. Does everything on the operator side
     * (configuration, operations, money) and sees the Charsley Digital
     * platform-billing counter alongside the developer.
     */
    public function isOwner(): bool
    {
        return $this->hasRole('owner');
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

    /**
     * The role that may edit configuration (fees, rules, users, branding,
     * system settings). Only the owner. Reviewers and finance can reach
     * the admin shell but must not see configuration pages.
     */
    public function canConfigure(): bool
    {
        return $this->is_active
            && ! $this->isOffboarded()
            && $this->hasRole('owner');
    }

    public function canAcceptQuotes(): bool
    {
        if ($this->hasRole('customer_admin')) {
            return true;
        }

        return $this->hasRole('customer_user') && (bool) $this->clientAccount?->quote_acceptance_allowed;
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
