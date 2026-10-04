<?php

namespace App\Models;

use App\Models\Concerns\ScopesToClientAccount;
use Database\Factories\BusinessClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessClient extends Model
{
    /** @use HasFactory<BusinessClientFactory> */
    use HasFactory, ScopesToClientAccount;

    protected $fillable = [
        'client_account_id', 'business_name', 'registration_number', 'proxy_name',
        'proxy_contact', 'proxy_id_number', 'address', 'usable_as',
        'retention_period_months', 'retention_expires_at', 'legal_hold', 'status',
    ];

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
        ];
    }
}
