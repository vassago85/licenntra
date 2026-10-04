<?php

namespace App\Models;

use App\Enums\Province;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeTable extends Model
{
    protected $fillable = ['province', 'name'];

    public function versions(): HasMany
    {
        return $this->hasMany(FeeTableVersion::class);
    }

    protected function casts(): array
    {
        return ['province' => Province::class];
    }
}
