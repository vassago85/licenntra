<?php

namespace App\Models;

use App\Enums\DatafixStatus;
use App\Models\Concerns\ScopesThroughApplication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatafixRecord extends Model
{
    use ScopesThroughApplication;

    protected $fillable = [
        'application_id', 'status', 'tare_kg', 'body_type', 'gvm_kg', 'client_tare_kg',
        'client_body_type', 'client_gvm_kg', 'confirmed_by', 'confirmed_at', 'completed_at',
        'authority_reference', 'query_note',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    protected function casts(): array
    {
        return [
            'status' => DatafixStatus::class,
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
