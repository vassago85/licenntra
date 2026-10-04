<?php

namespace App\Models;

use App\Models\Concerns\ScopesThroughApplication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportStep extends Model
{
    use ScopesThroughApplication;

    protected $fillable = ['application_id', 'step', 'status', 'completed_at'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
