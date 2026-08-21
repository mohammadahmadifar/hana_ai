<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DatasetTag extends Model
{
    protected $fillable = ['name', 'color', 'description_fa'];

    public function samples(): BelongsToMany
    {
        return $this->belongsToMany(DatasetSample::class, 'dataset_sample_tag');
    }
}
