<?php

namespace App\Models;

use App\Enums\DeliverableKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $application_id
 * @property DeliverableKind $kind
 * @property string|null $label
 * @property string $storage_path
 * @property string $original_filename
 * @property string $mime
 * @property int $size_bytes
 * @property string|null $sha256
 * @property int|null $uploaded_by_id
 * @property Carbon $uploaded_at
 * @property string|null $handover_notes
 */
class DeliverableDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'kind',
        'label',
        'storage_path',
        'original_filename',
        'mime',
        'size_bytes',
        'sha256',
        'uploaded_by_id',
        'uploaded_at',
        'handover_notes',
    ];

    protected $casts = [
        'kind' => DeliverableKind::class,
        'size_bytes' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function displayLabel(): string
    {
        return $this->label !== null && $this->label !== ''
            ? $this->label
            : $this->kind->label();
    }
}
