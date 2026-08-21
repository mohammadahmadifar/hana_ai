<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTypeField extends Model
{
    protected $fillable = [
        'document_type_id', 'key', 'label_fa', 'value_type',
        'is_required', 'is_cross_checked', 'sort',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_cross_checked' => 'boolean',
    ];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
