<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    protected $fillable = [
        'application_document_id', 'business_client_document_id', 'storage_path',
        'original_filename', 'mime', 'size', 'sha256', 'scan_status',
        'page_count', 'has_text_layer', 'inspection_status', 'inspection_notes', 'text_excerpt',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'has_text_layer' => 'boolean',
            'page_count' => 'integer',
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('client_account', function (Builder $query): void {
            $user = auth()->user();

            if (! $user?->client_account_id) {
                return;
            }

            $accountId = $user->client_account_id;

            $query->where(function (Builder $versions) use ($accountId): void {
                $versions->whereHas('applicationDocument.application', function (Builder $application) use ($accountId): void {
                    $application->where('client_account_id', $accountId);
                })->orWhereHas('businessClientDocument.businessClient', function (Builder $business) use ($accountId): void {
                    $business->where('client_account_id', $accountId);
                });
            });
        });
    }

    public function applicationDocument(): BelongsTo
    {
        return $this->belongsTo(ApplicationDocument::class);
    }

    public function businessClientDocument(): BelongsTo
    {
        return $this->belongsTo(BusinessClientDocument::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
