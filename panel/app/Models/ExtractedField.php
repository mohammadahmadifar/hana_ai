<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtractedField extends Model
{
    protected $fillable = [
        'case_id', 'case_document_id', 'field_key', 'raw_value', 'normalized_value',
        'confidence', 'source', 'corrected_by', 'corrected_at',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'corrected_at' => 'datetime',
    ];

    public function permitCase(): BelongsTo
    {
        return $this->belongsTo(PermitCase::class, 'case_id');
    }

    public function caseDocument(): BelongsTo
    {
        return $this->belongsTo(CaseDocument::class);
    }
}
