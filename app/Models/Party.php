<?php

namespace App\Models;

use App\Models\Concerns\ScopesThroughApplication;
use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory, ScopesThroughApplication;

    protected $fillable = [
        'application_id', 'role', 'party_type', 'name', 'identifier', 'address', 'business_client_id',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function businessClient(): BelongsTo
    {
        return $this->belongsTo(BusinessClient::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'identifier' => 'encrypted',
        ];
    }
}
