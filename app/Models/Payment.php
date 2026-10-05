<?php

namespace App\Models;

use App\Models\Concerns\ScopesThroughApplication;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use ScopesThroughApplication;

    public const METHOD_ACCOUNT_STATEMENT = 'account_statement';

    /**
     * Billed for invoicing because payment tracking is switched off; the
     * client still owes it until the invoice is marked paid.
     */
    public const METHOD_INVOICE = 'invoice';

    protected $fillable = [
        'application_id', 'amount_cents', 'method', 'reference', 'proof_document_id',
        'verified_by', 'verified_at', 'override_reason',
        'on_account', 'statement_settled_at', 'settled_by',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /**
     * On-account payments carry the "to be settled on monthly statement" flag.
     * Settling clears the per-account outstanding balance.
     */
    public function scopeOutstandingOnStatement(Builder $query): Builder
    {
        return $query->where('on_account', true)->whereNull('statement_settled_at');
    }

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'statement_settled_at' => 'datetime',
            'on_account' => 'boolean',
        ];
    }
}
