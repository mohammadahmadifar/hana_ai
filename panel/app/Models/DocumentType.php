<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DocumentType extends Model
{
    protected $fillable = [
        'key', 'label_fa', 'template_path', 'is_generatable', 'is_active', 'sort',
    ];

    protected $casts = [
        'is_generatable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function fields(): HasMany
    {
        return $this->hasMany(DocumentTypeField::class)->orderBy('sort');
    }

    public function serviceTypes(): BelongsToMany
    {
        return $this->belongsToMany(ServiceType::class, 'service_type_document_type');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
