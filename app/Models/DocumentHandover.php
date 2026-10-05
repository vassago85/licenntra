<?php

namespace App\Models;

use App\Enums\HandoverDirection;
use App\Enums\HandoverStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DocumentHandover extends Model
{
    protected $fillable = [
        'client_account_id',
        'direction',
        'status',
        'counterparty_name',
        'counterparty_identifier',
        'counterparty_company',
        'dealer_person_name',
        'items_summary',
        'notes',
        'created_by_id',
        'confirmed_by_id',
        'confirmed_at',
        'signed_file_path',
        'signed_file_original_name',
        'signed_file_mime',
        'signed_file_size',
        'signed_file_sha256',
        'signed_file_uploaded_at',
    ];

    /**
     * Scope every query to the current dealer's account. Reviewers and
     * super admins can see every account's hand-overs by using
     * `withoutGlobalScope(...)` where justified.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('dealer_account', function (Builder $query): void {
            $user = auth()->user();

            if (! $user || ! $user->client_account_id) {
                return;
            }

            $query->where($query->getModel()->getTable().'.client_account_id', $user->client_account_id);
        });
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function hasSignedScan(): bool
    {
        return $this->signed_file_path !== null;
    }

    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'document_handover_application')
            ->using(DocumentHandoverApplication::class)
            ->withPivot('item_description')
            ->withTimestamps();
    }

    public function isPending(): bool
    {
        return $this->status === HandoverStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === HandoverStatus::Completed;
    }

    public function title(): string
    {
        return $this->direction->documentTitle();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => HandoverDirection::class,
            'status' => HandoverStatus::class,
            'confirmed_at' => 'datetime',
            'signed_file_uploaded_at' => 'datetime',
        ];
    }
}
