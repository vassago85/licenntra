<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array{documents: list<array{document_id: int, label: string, required: bool, version_id: int, original_filename: string, mime: string, size: int, sha256: string, uploaded_at: ?string, requires_original: bool, original_received_at: ?string}>} $manifest
 * @property Carbon|null $submitted_at
 */
class SubmissionPack extends Model
{
    protected $fillable = [
        'application_id', 'prepared_by_id', 'manifest', 'submitted_at', 'authority_reference',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_id');
    }

    /**
     * @return list<int>
     */
    public function versionIds(): array
    {
        return array_map(fn (array $entry): int => (int) $entry['version_id'], $this->manifest['documents'] ?? []);
    }

    public function documentCount(): int
    {
        return count($this->manifest['documents'] ?? []);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'submitted_at' => 'datetime',
        ];
    }
}
