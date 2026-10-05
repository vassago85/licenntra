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
        'original_received_at',
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
     * Whether this document type can be lodged as a copy but must have
     * the physical original collected before the pack can be shipped to
     * the licensing authority. Driven by `document_types.requires_original`.
     */
    public function requiresOriginal(): bool
    {
        return (bool) $this->documentType?->requires_original;
    }

    /**
     * Whether the dealer has confirmed they have the physical original in
     * hand. False both when the slot is still empty and when a copy has
     * been uploaded but the physical hand-over hasn't happened yet.
     */
    public function isOriginalReceived(): bool
    {
        return $this->original_received_at !== null;
    }

    /**
     * Whether this document is blocking the pack from being released to
     * the licensing authority because the physical original hasn't been
     * collected from the seller yet.
     */
    public function isOriginalOutstanding(): bool
    {
        return $this->required && $this->requiresOriginal() && ! $this->isOriginalReceived();
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
            'original_received_at' => 'datetime',
        ];
    }
}
