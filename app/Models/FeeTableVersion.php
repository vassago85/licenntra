<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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

    /**
     * Lines the other version charges that this one has no line for, by fee
     * code. A flat 'licence' line counts as covered when this version prices
     * licences by gazette band instead.
     *
     * @return Collection<int, FeeLine>
     */
    public function linesMissingFrom(FeeTableVersion $other): Collection
    {
        $codes = $this->lines()->distinct()->pluck('code')->all();
        $pricesLicenceByBand = $this->lines()->where('code', 'licence')->whereNotNull('licence_category')->exists();

        return $other->lines()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->reject(fn (FeeLine $line): bool => in_array($line->code, $codes, true)
                || ($line->code === 'licence' && $pricesLicenceByBand))
            ->values();
    }
}
