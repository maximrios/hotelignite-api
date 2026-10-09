<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use App\Models\Concerns\OwnedByClient;
use App\Models\Contracts\HasMedia;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Evento de la agenda turística (docs/events-plan.md).
 *
 * Las fechas son `date` + `time` en hora local del destino. Dos formas:
 *
 * - `none`: ocurre de `start_date` a `end_date` (null = un solo día).
 * - `weekly`: ocurre los días ISO de `weekdays` (1 = lunes) entre `start_date` y
 *   `end_date` (null = sin fin).
 *
 * `location` es generada desde `latitude`/`longitude`. Si el evento no tiene
 * coordenadas propias, la búsqueda por distancia usa las de su sede.
 */
class Event extends Model implements HasMedia
{
    use HasUniqueSlug;
    use OwnedByClient;
    use SoftDeletes;

    public const RECURRENCES = ['none', 'weekly'];

    public const STATUSES = ['scheduled', 'postponed', 'cancelled', 'sold_out'];

    public const PRICE_TYPES = ['free', 'paid', 'unknown'];

    public const SOURCES = ['manual', 'osm', 'google'];

    /**
     * Redes admitidas en `links` y los dominios contra los que se valida cada
     * URL. `website` acepta cualquiera.
     *
     * @var array<string, list<string>>
     */
    public const LINK_DOMAINS = [
        'website' => [],
        'instagram' => ['instagram.com'],
        'facebook' => ['facebook.com', 'fb.com', 'fb.me'],
        'tiktok' => ['tiktok.com'],
        'x' => ['x.com', 'twitter.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'whatsapp' => ['wa.me', 'whatsapp.com'],
        'spotify' => ['spotify.com'],
    ];

    protected $fillable = [
        'city_id',
        'event_category_id',
        'point_of_interest_id',
        'name',
        'slug',
        'summary',
        'description',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'recurrence',
        'weekdays',
        'status',
        'venue_name',
        'address',
        'latitude',
        'longitude',
        'price_type',
        'price_from',
        'price_to',
        'currency',
        'ticket_url',
        'organizer_name',
        'organizer_email',
        'organizer_phone',
        'links',
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
        'recurrence' => 'none',
        'status' => 'scheduled',
        'price_type' => 'unknown',
        'currency' => 'ARS',
        'is_featured' => false,
        'enabled' => true,
        'source' => 'manual',
    ];

    protected $casts = [
        'city_id' => 'integer',
        'event_category_id' => 'integer',
        'point_of_interest_id' => 'integer',
        'client_id' => 'integer',
        'start_date' => 'immutable_date',
        'end_date' => 'immutable_date',
        'weekdays' => 'array',
        'links' => 'array',
        'latitude' => 'float',
        'longitude' => 'float',
        'price_from' => 'float',
        'price_to' => 'float',
        'is_featured' => 'boolean',
        'enabled' => 'boolean',
    ];

    protected static function slugFallback(): string
    {
        return 'evento';
    }

    /** "Hoy" en el destino: las fechas de los eventos son locales, no UTC. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.destination_timezone'))->startOfDay();
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<EventCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id');
    }

    /** @return BelongsTo<PointOfInterest, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(PointOfInterest::class, 'point_of_interest_id');
    }

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('order')->orderBy('id');
    }

    /**
     * Eventos con alguna ocurrencia entre `$from` y `$to` (inclusive).
     *
     * Para los semanales no alcanza con que los rangos se crucen: además algún
     * día del cruce tiene que caer en `weekdays`. El `generate_series` corre sobre
     * el cruce, así que la ventana se acota en el FormRequest.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeOccursBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $f = $from->toDateString();
        $t = $to->toDateString();

        $query->whereRaw(<<<'SQL'
            events.start_date <= ?::date
            AND COALESCE(events.end_date, CASE WHEN events.recurrence = 'weekly' THEN ?::date ELSE events.start_date END) >= ?::date
            AND (
                events.recurrence <> 'weekly'
                OR EXISTS (
                    SELECT 1
                    FROM generate_series(
                        GREATEST(events.start_date, ?::date),
                        LEAST(COALESCE(events.end_date, ?::date), ?::date),
                        interval '1 day'
                    ) AS d
                    WHERE events.weekdays @> to_jsonb(EXTRACT(ISODOW FROM d)::int)
                )
            )
            SQL, [$t, $t, $f, $f, $t, $t]);
    }

    /**
     * Eventos que todavía no terminaron (los semanales sin fin, siempre).
     *
     * @param  Builder<Event>  $query
     */
    public function scopeUpcoming(Builder $query, CarbonInterface $today): void
    {
        $query->where(function (Builder $q) use ($today) {
            $q->whereRaw('COALESCE(events.end_date, events.start_date) >= ?::date', [$today->toDateString()])
                ->orWhere(fn (Builder $w) => $w->where('events.recurrence', 'weekly')->whereNull('events.end_date'));
        });
    }

    /**
     * Próxima fecha en la que el evento ocurre, desde `$from` inclusive. Un evento
     * de varios días en curso devuelve `$from`. `null` si ya terminó.
     */
    public function nextOccurrence(CarbonInterface $from): ?CarbonImmutable
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $start = $this->start_date;
        $end = $this->end_date;

        if ($start === null) {
            return null;
        }

        if ($this->recurrence !== 'weekly') {
            $last = $end ?? $start;

            if ($last->lt($from)) {
                return null;
            }

            return $start->gte($from) ? $start : $from;
        }

        $days = array_map('intval', $this->weekdays ?? []);
        $cursor = $start->gte($from) ? $start : $from;

        for ($i = 0; $i < 7; $i++) {
            $day = $cursor->addDays($i);
            if ($end !== null && $day->gt($end)) {
                return null;
            }
            if (in_array($day->isoWeekday(), $days, true)) {
                return $day;
            }
        }

        return null;
    }
}
