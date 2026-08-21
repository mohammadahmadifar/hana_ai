<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValidationResult extends Model
{
    protected $fillable = [
        'case_id', 'case_document_id', 'rule_key', 'scope', 'status', 'message_fa', 'details',
    ];

    protected $casts = ['details' => 'array'];

    public function permitCase(): BelongsTo
    {
        return $this->belongsTo(PermitCase::class, 'case_id');
    }
}
