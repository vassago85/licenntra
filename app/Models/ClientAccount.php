<?php

namespace App\Models;

use App\Enums\BillingMode;
use App\Enums\ClientAccountType;
use App\Enums\IdentificationType;
use Database\Factories\ClientAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;

class ClientAccount extends Model
{
    /** @use HasFactory<ClientAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'type', 'additional_types', 'status', 'quote_acceptance_allowed', 'markup_basis_points',
        'billing_mode', 'payment_terms_days', 'credit_limit_cents',
        'contact_name', 'contact_email', 'contact_phone',

        // The user at this dealership who receives every invoice by
        // default. One stock controller per dealership; nullable until
        // the client_admin nominates someone from the Team page.
        'stock_controller_user_id',

        // The reviewer at the licensing company who normally owns this
        // dealership's work. New submissions auto-land on this reviewer's
        // queue; coverage by any other reviewer is still allowed but gets
        // its own audit annotation.
        'primary_reviewer_user_id',

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

    /**
     * The user nominated to receive every invoice by default. May be
     * null if the client_admin hasn't picked one yet, or if the
     * nominated user has been deactivated/deleted.
     */
    public function stockController(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stock_controller_user_id');
    }

    /**
     * The reviewer at the licensing company who exclusively owns this
     * dealership's work. Nullable - when unset, submissions land in the
     * general review queue instead.
     */
    public function primaryReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_reviewer_user_id');
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
            'additional_types' => 'array',
            'billing_mode' => BillingMode::class,
            'quote_acceptance_allowed' => 'boolean',
            'proxy_id_type' => IdentificationType::class,
            'representative_id_type' => IdentificationType::class,
            'proxy_id_number' => 'encrypted',
            'representative_id_number' => 'encrypted',
        ];
    }

    /**
     * Every ClientAccountType this account plays, primary first, with
     * duplicates removed. A dealership that also runs a rental fleet
     * returns [Dealer, FleetOperator] here.
     *
     * @return Collection<int, ClientAccountType>
     */
    public function types(): Collection
    {
        $additional = collect($this->additional_types ?? [])
            ->map(fn ($value): ?ClientAccountType => $value instanceof ClientAccountType
                ? $value
                : ClientAccountType::tryFrom((string) $value))
            ->filter();

        return collect([$this->type])
            ->concat($additional)
            ->filter()
            ->unique(fn (ClientAccountType $t): string => $t->value)
            ->values();
    }

    /**
     * True when the account plays this role, whether it is the primary
     * type or one of the additional types.
     */
    public function hasType(ClientAccountType $type): bool
    {
        return $this->types()->contains(
            fn (ClientAccountType $t): bool => $t === $type,
        );
    }

    /**
     * Query scope: accounts that play the given role, whether as their
     * primary `type` or inside `additional_types`.
     */
    public function scopeOfType(Builder $query, ClientAccountType $type): Builder
    {
        return $query->where(function (Builder $q) use ($type): void {
            $q->where('type', $type->value)
                ->orWhereJsonContains('additional_types', $type->value);
        });
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
