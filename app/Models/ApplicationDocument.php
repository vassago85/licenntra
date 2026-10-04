<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\RejectionReason;
use App\Models\Concerns\ScopesThroughApplication;
use Database\Factories\ApplicationDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationDocument extends Model
{
    /** @use HasFactory<ApplicationDocumentFactory> */
    use HasFactory, ScopesThroughApplication;

    protected $fillable = [
        'application_id', 'document_type_id', 'party_role', 'required', 'status', 'source',
        'rejection_reason', 'reviewer_comment', 'dangerous_goods_stamped', 'business_client_document_id', 'linked_version_id',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'linked_version_id');
    }

    public function label(): string
    {
        $name = $this->documentType?->name ?? 'Document';

        return $this->party_role === 'title_holder' ? 'Title holder '.$name : $name;
    }

    public function requiresDangerousGoodsStamp(): bool
    {
        return $this->documentType?->code === 'cof' && (bool) $this->application?->dangerous_goods;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'dangerous_goods_stamped' => 'boolean',
            'status' => DocumentStatus::class,
            'rejection_reason' => RejectionReason::class,
        ];
    }
}
