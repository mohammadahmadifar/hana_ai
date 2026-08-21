<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OcrRun extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'engine_version', 'params',
        'raw_text', 'extra', 'duration_ms', 'status', 'error',
    ];

    protected $casts = [
        'params' => 'array',
        'extra' => 'array',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
