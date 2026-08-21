<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CaseDocument extends Model
{
    /** @use HasFactory<\Database\Factories\CaseDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'case_id', 'document_type_id', 'disk', 'path', 'original_name', 'mime',
        'size_bytes', 'width', 'height', 'checksum',
        'precheck_status', 'precheck_issues', 'blur_score', 'brightness_score', 'ocr_status',
    ];

    protected $casts = [
        'precheck_issues' => 'array',
        'blur_score' => 'float',
        'brightness_score' => 'float',
    ];

    public function permitCase(): BelongsTo
    {
        return $this->belongsTo(PermitCase::class, 'case_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function ocrRuns(): MorphMany
    {
        return $this->morphMany(OcrRun::class, 'subject');
    }

    public function latestOcrRun()
    {
        return $this->morphOne(OcrRun::class, 'subject')->latestOfMany();
    }

    public function extractedFields(): HasMany
    {
        return $this->hasMany(ExtractedField::class);
    }

    public function absolutePath(): string
    {
        return \Illuminate\Support\Facades\Storage::disk($this->disk)->path($this->path);
    }
}
