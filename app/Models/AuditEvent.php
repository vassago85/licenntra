<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'occurred_at', 'actor_user_id', 'actor_role', 'ip', 'user_agent', 'subject_type',
        'subject_id', 'action', 'summary', 'before', 'after', 'is_system',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('audit_events is append-only');
        });

        static::deleting(function (): void {
            throw new RuntimeException('audit_events is append-only');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'before' => 'array',
            'after' => 'array',
            'is_system' => 'boolean',
        ];
    }
}
