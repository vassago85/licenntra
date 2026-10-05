<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeTableVersion extends Model
{
    protected $fillable = [
        'fee_table_id', 'version', 'status', 'created_by', 'approved_by', 'approved_at',
        'effective_from', 'effective_until', 'notes',
    ];

    public function feeTable(): BelongsTo
    {
        return $this->belongsTo(FeeTable::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FeeLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}
