<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteLine extends Model
{
    protected $fillable = ['quote_id', 'description', 'client_price_cents', 'internal_cost_cents'];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
