<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot class so the belongsToMany relationship can carry the extra
 * `item_description` column + timestamps per hand-over line.
 */
class DocumentHandoverApplication extends Pivot
{
    protected $table = 'document_handover_application';

    public $incrementing = true;

    public $timestamps = true;

    protected $fillable = [
        'document_handover_id',
        'application_id',
        'item_description',
    ];
}
