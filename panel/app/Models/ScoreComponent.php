<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScoreComponent extends Model
{
    protected $fillable = [
        'case_id', 'component_key', 'label_fa', 'weight', 'value', 'contribution', 'note_fa',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
        'value' => 'decimal:2',
        'contribution' => 'decimal:2',
    ];

    public function permitCase(): BelongsTo
    {
        return $this->belongsTo(PermitCase::class, 'case_id');
    }
}
