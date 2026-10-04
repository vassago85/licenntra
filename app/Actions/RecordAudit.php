<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Mask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class RecordAudit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function handle(
        ?User $actor,
        ?Model $subject,
        string $action,
        string $summary,
        ?array $before = null,
        ?array $after = null,
        bool $isSystem = false,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'occurred_at' => now(),
            'actor_user_id' => $actor?->id,
            'actor_role' => $actor?->getRoleNames()->first(),
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'action' => $action,
            'summary' => $summary,
            'before' => Mask::sensitive($before),
            'after' => Mask::sensitive($after),
            'is_system' => $isSystem,
        ]);
    }
}
