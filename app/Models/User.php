<?php

namespace App\Models;

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

#[Fillable(['name', 'email', 'password', 'client_account_id', 'is_active'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function isClient(): bool
    {
        return $this->client_account_id !== null;
    }

    public function isLicensingStaff(): bool
    {
        return $this->hasAnyRole(['super_admin', 'customer_admin', 'reviewer', 'finance', 'auditor']);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->hasAnyRole([
            'super_admin',
            'customer_admin',
            'reviewer',
            'finance',
            'auditor',
        ]);
    }

    /**
     * Admin-only roles that may edit configuration (fees, rules, users,
     * branding, system settings). Reviewers, finance and auditors can
     * reach the panel but must not see these pages.
     */
    public function canConfigure(): bool
    {
        return $this->is_active && $this->hasAnyRole(['super_admin', 'customer_admin']);
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
        ];
    }
}
