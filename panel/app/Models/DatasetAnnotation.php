<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetAnnotation extends Model
{
    protected $fillable = [
        'dataset_sample_id', 'field_key', 'value',
        'bbox_x', 'bbox_y', 'bbox_w', 'bbox_h', 'source', 'created_by',
    ];

    protected $casts = [
        'bbox_x' => 'float',
        'bbox_y' => 'float',
        'bbox_w' => 'float',
        'bbox_h' => 'float',
    ];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(DatasetSample::class, 'dataset_sample_id');
    }

    public function hasBox(): bool
    {
        return $this->bbox_w !== null && $this->bbox_h !== null;
    }
}
