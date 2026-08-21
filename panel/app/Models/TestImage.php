<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestImage extends Model
{
    protected $fillable = [
        'user_id', 'document_type_id', 'payload', 'augmentations',
        'disk', 'path', 'clean_path', 'width', 'height',
    ];

    protected $casts = [
        'payload' => 'array',
        'augmentations' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function absolutePath(): string
    {
        return \Illuminate\Support\Facades\Storage::disk($this->disk)->path($this->path);
    }
}
