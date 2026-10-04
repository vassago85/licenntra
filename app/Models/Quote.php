<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use App\Models\Concerns\ScopesThroughApplication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    use ScopesThroughApplication;

    protected $fillable = [
        'application_id', 'status', 'expires_at', 'client_notes', 'internal_notes', 'created_by',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class);
    }

    public function clientTotalCents(): int
    {
        return (int) $this->lines()->sum('client_price_cents');
    }

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'expires_at' => 'datetime',
        ];
    }
}
