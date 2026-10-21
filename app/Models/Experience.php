<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use App\Models\Concerns\OwnedByClient;
use App\Models\Contracts\HasMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Experiencia del portal de un client (docs/experiences-plan.md): excursión,
 * actividad o circuito permanente. No tiene fechas: opera ciertos días de la
 * semana (`operating_days`) en ciertos meses (`season_months`); null = siempre.
 *
 * `client_id` es el portal donde sale, no quién la presta (`operator_*`).
 *
 * `location` es generada desde `latitude`/`longitude`: el punto de encuentro. Si
 * no hay coordenadas propias, vale la del POI (`meetingPoint`).
 */
class Experience extends Model implements HasMedia
{
    use HasUniqueSlug;
    use OwnedByClient;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];

    public const DURATION_UNITS = ['hours', 'days'];

    /** Lo que agrupa turinorte: medio día, día completo, varios días. */
    public const DURATION_KINDS = ['medio', 'dia', 'varios'];

    /** Tope en horas de una experiencia de "medio día". */
    public const HALF_DAY_MAX_HOURS = 5;

    public const DIFFICULTIES = ['easy', 'moderate', 'hard'];

    public const PRICE_TYPES = ['free', 'paid', 'unknown'];

    public const PRICE_UNITS = ['person', 'group'];

    public const SOURCES = ['manual', 'osm', 'google'];

    /** Las mismas redes que los eventos, validadas igual (`EventRules::linkMatchesNetwork`). */
    public const LINK_DOMAINS = Event::LINK_DOMAINS;

    protected $fillable = [
        'experience_category_id',
        'departure_city_id',
        'point_of_interest_id',
        'name',
        'slug',
        'summary',
        'description',
        'duration_value',
        'duration_unit',
        'difficulty',
        'min_age',
        'group_min',
        'group_max',
        'languages',
        'includes',
        'excludes',
        'what_to_bring',
        'meeting_point_name',
        'address',
        'latitude',
        'longitude',
        'price_type',
        'price_from',
        'currency',
        'price_unit',
        'operating_days',
        'season_months',
        'operator_name',
        'operator_email',
        'operator_phone',
        'booking_url',
        'links',
        'status',
        'is_featured',
        'source',
        'external_id',
    ];

    protected $hidden = [
        'location',
    ];

    /** Los defaults de la tabla, para que el modelo recién creado los tenga sin `fresh()`. */
    protected $attributes = [
        'price_type' => 'unknown',
        'currency' => 'ARS',
        'price_unit' => 'person',
        'status' => self::STATUS_DRAFT,
        'is_featured' => false,
        'source' => 'manual',
    ];

    protected $casts = [
        'experience_category_id' => 'integer',
        'departure_city_id' => 'integer',
        'point_of_interest_id' => 'integer',
        'client_id' => 'integer',
        'duration_value' => 'float',
        'min_age' => 'integer',
        'group_min' => 'integer',
        'group_max' => 'integer',
        'languages' => 'array',
        'includes' => 'array',
        'excludes' => 'array',
        'latitude' => 'float',
        'longitude' => 'float',
        'price_from' => 'float',
        'operating_days' => 'array',
        'season_months' => 'array',
        'links' => 'array',
        'is_featured' => 'boolean',
    ];

    protected static function slugFallback(): string
    {
        return 'experiencia';
    }

    /** @return BelongsTo<ExperienceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExperienceCategory::class, 'experience_category_id');
    }

    /** @return BelongsTo<City, $this> */
    public function departureCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'departure_city_id');
    }

    /**
     * Destinos que recorre, además de la ciudad de salida.
     *
     * @return BelongsToMany<City, $this>
     */
    public function cities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'city_experience')->orderBy('cities.name');
    }

    /** @return BelongsTo<PointOfInterest, $this> */
    public function meetingPoint(): BelongsTo
    {
        return $this->belongsTo(PointOfInterest::class, 'point_of_interest_id');
    }

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('order')->orderBy('id');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Medio día (≤ 5 h), día completo (> 5 h y ≤ 24 h, o 1 día) o varios días.
     * `null` si no tiene duración cargada. Misma regla que `scopeDurationKind`.
     */
    public function durationKind(): ?string
    {
        $value = $this->duration_value;

        if ($value === null || $this->duration_unit === null) {
            return null;
        }

        $hours = $this->duration_unit === 'days' ? $value * 24 : $value;

        return match (true) {
            $hours <= self::HALF_DAY_MAX_HOURS => 'medio',
            $hours <= 24 => 'dia',
            default => 'varios',
        };
    }

    /**
     * Experiencias que salen de `$cityId` o la recorren.
     *
     * @param  Builder<Experience>  $query
     */
    public function scopeInCity(Builder $query, int $cityId): void
    {
        $query->where(function (Builder $q) use ($cityId) {
            $q->where('experiences.departure_city_id', $cityId)
                ->orWhereHas('cities', fn (Builder $c) => $c->where('cities.id', $cityId));
        });
    }

    /** @param  Builder<Experience>  $query */
    public function scopeDurationKind(Builder $query, string $kind): void
    {
        $hours = "(CASE WHEN experiences.duration_unit = 'days' THEN experiences.duration_value * 24 ELSE experiences.duration_value END)";

        $query->whereNotNull('experiences.duration_value')->whereNotNull('experiences.duration_unit');

        match ($kind) {
            'medio' => $query->whereRaw("{$hours} <= ?", [self::HALF_DAY_MAX_HOURS]),
            'dia' => $query->whereRaw("{$hours} > ? AND {$hours} <= 24", [self::HALF_DAY_MAX_HOURS]),
            default => $query->whereRaw("{$hours} > 24"),
        };
    }

    /** @param  Builder<Experience>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('experiences.status', self::STATUS_PUBLISHED);
    }
}
