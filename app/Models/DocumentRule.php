<?php

namespace App\Models;

use App\Enums\OwnerType;
use App\Enums\Province;
use App\Enums\RequestType;
use App\Enums\VehicleCategory;
use Database\Factories\DocumentRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRule extends Model
{
    /** @use HasFactory<DocumentRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'document_type_id', 'request_type', 'vehicle_category', 'owner_type', 'province',
        'is_financed', 'party_role', 'requirement', 'sort_order', 'active',
    ];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_type' => RequestType::class,
            'vehicle_category' => VehicleCategory::class,
            'owner_type' => OwnerType::class,
            'province' => Province::class,
            'active' => 'boolean',
        ];
    }
}
