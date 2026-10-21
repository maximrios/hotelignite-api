<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Categoría de experiencia, un nivel (docs/experiences-plan.md). */
class ExperienceCategory extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'icon',
        'sort_order',
        'enabled',
    ];

    protected $attributes = [
        'sort_order' => 0,
        'enabled' => true,
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'enabled' => 'boolean',
    ];

    /** @return HasMany<Experience, $this> */
    public function experiences(): HasMany
    {
        return $this->hasMany(Experience::class);
    }
}
