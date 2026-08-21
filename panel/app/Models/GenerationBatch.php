<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationBatch extends Model
{
    protected $fillable = [
        'user_id', 'document_type_ids', 'count_requested', 'count_done',
        'count_failed', 'augmentations', 'status', 'error', 'finished_at',
    ];

    protected $casts = [
        'document_type_ids' => 'array',
        'augmentations' => 'array',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progressPercent(): int
    {
        if ($this->count_requested < 1) {
            return 0;
        }

        return (int) round(($this->count_done + $this->count_failed) / $this->count_requested * 100);
    }
}
