<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use App\Models\Concerns\OwnedByClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Punto de interés del catálogo global (docs/points-of-interest-plan.md).
 *
 * `location` es una columna generada desde `latitude`/`longitude`: se escriben
 * las coordenadas y Postgres deriva el `geography`. Va en `$hidden` porque sale
 * como WKB hexadecimal.
 */
class PointOfInterest extends Model
{
    use HasUniqueSlug;
    use OwnedByClient;
    use SoftDeletes;

    protected $table = 'points_of_interest';

    public const SOURCES = ['manual', 'osm', 'google'];

    protected $fillable = [
        'city_id',
        'poi_category_id',
        'name',
        'slug',
        'description',
        'address',
        'phone',
        'website',
        'latitude',
        'longitude',
        'is_featured',
        'enabled',
        'source',
        'external_id',
    ];

    protected $hidden = [
        'location',
    ];

    /** Los defaults de la tabla, para que el modelo recién creado los tenga sin `fresh()`. */
    protected $attributes = [
        'is_featured' => false,
        'enabled' => true,
        'source' => 'manual',
    ];

    protected $casts = [
        'city_id' => 'integer',
        'poi_category_id' => 'integer',
        'client_id' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'is_featured' => 'boolean',
        'enabled' => 'boolean',
    ];

    protected static function slugFallback(): string
    {
        return 'punto';
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<PoiCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PoiCategory::class, 'poi_category_id');
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
