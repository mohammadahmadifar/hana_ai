<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceType extends Model
{
    protected $fillable = ['key', 'label_fa', 'description_fa', 'is_active', 'sort'];

    protected $casts = ['is_active' => 'boolean'];

    public function documentTypes(): BelongsToMany
    {
        return $this->belongsToMany(DocumentType::class, 'service_type_document_type')
            ->withPivot(['is_required', 'sort'])
            ->orderBy('service_type_document_type.sort');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(PermitCase::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
