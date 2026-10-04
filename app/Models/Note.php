<?php

namespace App\Models;

use App\Models\Concerns\ScopesThroughApplication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    use ScopesThroughApplication;

    protected $fillable = ['application_id', 'body', 'visibility', 'user_id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
