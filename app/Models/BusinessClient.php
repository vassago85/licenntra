<?php

namespace App\Models;

use Database\Factories\BusinessClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessClient extends Model
{
    /** @use HasFactory<BusinessClientFactory> */
    use HasFactory;

    protected $fillable = [
        'client_account_id', 'business_name', 'registration_number', 'proxy_name',
        'proxy_contact', 'proxy_id_number', 'address', 'usable_as', 'is_shared',
        'retention_period_months', 'retention_expires_at', 'legal_hold', 'status',
    ];

    /**
     * Global scope: a client user sees records on their own dealership
     * PLUS any record marked is_shared=true (shared title holders /
     * finance houses, visible across all dealerships). Staff users have
     * no client_account_id so the scope short-circuits and they see
     * everything, matching the behaviour of ScopesToClientAccount on the
     * other models that still use it.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('visible_to_dealer', function (Builder $query): void {
            $user = auth()->user();

            if (! $user?->client_account_id) {
                return;
            }

            $table = $query->getModel()->getTable();

            $query->where(function (Builder $q) use ($user, $table): void {
                $q->where($table.'.client_account_id', $user->client_account_id)
                    ->orWhere($table.'.is_shared', true);
            });
        });
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BusinessClientDocument::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(RetentionConsent::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registration_number' => 'encrypted',
            'proxy_id_number' => 'encrypted',
            'retention_expires_at' => 'datetime',
            'legal_hold' => 'boolean',
            'is_shared' => 'boolean',
        ];
    }

    /**
     * True when this record is visible across all dealerships.
     *
     * Only title-holder records (and the "both" dual role) can legitimately
     * be shared - owner records contain the dealer's own customer data and
     * must stay dealership-private regardless of the flag.
     */
    public function isShared(): bool
    {
        if (! in_array($this->usable_as, ['title_holder', 'both'], true)) {
            return false;
        }

        return (bool) $this->is_shared;
    }
}
