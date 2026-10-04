<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetentionConsent extends Model
{
    protected $fillable = ['business_client_id', 'user_id', 'period_months', 'confirmed_at', 'wording_version'];

    public function businessClient(): BelongsTo
    {
        return $this->belongsTo(BusinessClient::class);
    }

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }
}
