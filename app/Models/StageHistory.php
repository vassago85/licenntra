<?php

namespace App\Models;

use App\Enums\ApplicationStage;
use App\Models\Concerns\ScopesThroughApplication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageHistory extends Model
{
    use ScopesThroughApplication;

    protected $fillable = ['application_id', 'from_stage', 'to_stage', 'user_id', 'reason'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    protected function casts(): array
    {
        return [
            'from_stage' => ApplicationStage::class,
            'to_stage' => ApplicationStage::class,
        ];
    }
}
