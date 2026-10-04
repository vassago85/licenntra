<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrandingSetting extends Model
{
    protected $fillable = [
        'company_name', 'logo_path', 'primary_colour', 'support_email', 'support_phone',
        'address', 'reference_prefix',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'company_name' => 'Licentra',
            'primary_colour' => '#1F47B8',
            'reference_prefix' => 'LIC',
        ]);
    }
}
