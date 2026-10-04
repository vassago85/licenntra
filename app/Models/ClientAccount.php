<?php

namespace App\Models;

use App\Enums\BillingMode;
use App\Enums\ClientAccountType;
use App\Enums\IdentificationType;
use Database\Factories\ClientAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class ClientAccount extends Model
{
    /** @use HasFactory<ClientAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'type', 'status', 'quote_acceptance_allowed', 'markup_basis_points',
        'billing_mode', 'payment_terms_days', 'credit_limit_cents',
        'contact_name', 'contact_email', 'contact_phone',

        // Dealership particulars printed into every ALV / RLV prepared on
        // behalf of this account.
        'brn',
        'proxy_name', 'proxy_initials', 'proxy_id_type', 'proxy_id_number', 'proxy_id_country',
        'representative_name', 'representative_initials', 'representative_id_type',
        'representative_id_number', 'representative_id_country',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function businessClients(): HasMany
    {
        return $this->hasMany(BusinessClient::class);
    }

    /**
     * Every payment ever recorded against any of this account's applications.
     */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Application::class);
    }

    /**
     * True when the account is billed on a running statement rather than
     * paying each application up-front.
     */
    public function isOnAccount(): bool
    {
        return $this->billing_mode === BillingMode::AccountStatement;
    }

    /**
     * Sum (in cents) of every on-account payment that still hasn't been
     * marked settled on this account's statement.
     */
    public function statementOutstandingCents(): int
    {
        return (int) $this->payments()
            ->where('payments.on_account', true)
            ->whereNull('payments.statement_settled_at')
            ->sum('payments.amount_cents');
    }

    /**
     * Query scope: only accounts billed on an account statement.
     */
    public function scopeOnAccount(Builder $query): Builder
    {
        return $query->where('billing_mode', BillingMode::AccountStatement->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ClientAccountType::class,
            'billing_mode' => BillingMode::class,
            'quote_acceptance_allowed' => 'boolean',
            'proxy_id_type' => IdentificationType::class,
            'representative_id_type' => IdentificationType::class,
            'proxy_id_number' => 'encrypted',
            'representative_id_number' => 'encrypted',
        ];
    }

    /**
     * True when the account has captured enough BRN + proxy data for the
     * ALV / RLV declaration blocks to print without a human filling in
     * the dealership particulars by hand.
     */
    public function hasDealershipParticulars(): bool
    {
        return filled($this->brn)
            && filled($this->proxy_name)
            && filled($this->proxy_initials)
            && $this->proxy_id_type !== null
            && filled($this->proxy_id_number);
    }
}
