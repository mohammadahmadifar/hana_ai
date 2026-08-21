<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DatasetSample extends Model
{
    protected $fillable = [
        'document_type_id', 'created_by', 'source', 'disk', 'path', 'clean_path',
        'original_name', 'width', 'height', 'augmentation', 'augmentation_params',
        'generation_payload', 'split', 'is_verified', 'verified_by', 'verified_at', 'notes',
    ];

    protected $casts = [
        'augmentation_params' => 'array',
        'generation_payload' => 'array',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(DatasetAnnotation::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(DatasetTag::class, 'dataset_sample_tag');
    }

    public function ocrRuns(): MorphMany
    {
        return $this->morphMany(OcrRun::class, 'subject');
    }

    public function absolutePath(): string
    {
        return \Illuminate\Support\Facades\Storage::disk($this->disk)->path($this->path);
    }
}
