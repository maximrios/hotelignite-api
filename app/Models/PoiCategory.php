<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de punto de interés, dos niveles (docs/points-of-interest-plan.md).
 *
 * - Raíz (`parent_id` null): agrupa en "En los alrededores" y define el radio de
 *   búsqueda (`default_radius_m`).
 * - Hoja: de la que cuelga cada POI. Puede pisar el radio de su raíz, o tener
 *   `nearest_limit` para buscarse por cercanía sin radio (el aeropuerto).
 */
class PoiCategory extends Model
{
    protected $fillable = [
        'parent_id',
        'slug',
        'name',
        'icon',
        'sort_order',
        'default_radius_m',
        'nearest_limit',
        'enabled',
    ];

    /** Los defaults de la tabla, para que el modelo recién creado los tenga sin `fresh()`. */
    protected $attributes = [
        'sort_order' => 0,
        'enabled' => true,
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'sort_order' => 'integer',
        'default_radius_m' => 'integer',
        'nearest_limit' => 'integer',
        'enabled' => 'boolean',
    ];

    /** @return BelongsTo<PoiCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<PoiCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /** @return HasMany<PointOfInterest, $this> */
    public function pointsOfInterest(): HasMany
    {
        return $this->hasMany(PointOfInterest::class);
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /** @param  Builder<PoiCategory>  $query */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }
}
