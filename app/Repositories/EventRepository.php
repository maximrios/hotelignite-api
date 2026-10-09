<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\Admin\SearchEventRequest;
use App\Http\Resources\Admin\EventResource;
use App\Http\Resources\Admin\EventResourceCollection;
use App\Models\Accommodation;
use App\Models\Event;
use App\Repositories\Contracts\EventInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Agenda de eventos (docs/events-plan.md). */
class EventRepository implements EventInterface
{
    private const RELATIONS = ['city.state', 'category', 'venue', 'client', 'media'];

    public function search(SearchEventRequest $request, ?int $ownerClientId = null): EventResourceCollection
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $today = Event::today();
        $when = $request->input('when', 'upcoming');

        $events = Event::query()
            ->with(self::RELATIONS)
            ->when($ownerClientId !== null, fn ($q) => $q->ownedByClient($ownerClientId))
            ->when($request->filled('city_id'), fn ($q) => $q->where('city_id', $request->integer('city_id')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('event_category_id', $request->integer('category_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->has('enabled'), fn ($q) => $q->where('enabled', $request->boolean('enabled')))
            ->when($request->has('featured'), fn ($q) => $q->where('is_featured', $request->boolean('featured')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.addcslashes((string) $request->input('q'), '%_\\').'%';
                $q->whereRaw('unaccent(events.name) ILIKE unaccent(?)', [$term]);
            })
            ->when($request->filled('from'), fn ($q) => $q->occursBetween(
                CarbonImmutable::parse((string) $request->input('from')),
                CarbonImmutable::parse((string) $request->input('to')),
            ))
            ->when(! $request->filled('from') && $when === 'upcoming', fn ($q) => $q->upcoming($today))
            ->when(! $request->filled('from') && $when === 'past', fn ($q) => $q->whereNot(fn ($w) => $w->upcoming($today)))
            // Lo que viene, de lo más próximo a lo más lejano; lo pasado, al revés.
            ->orderBy('start_date', $when === 'upcoming' || $request->filled('from') ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate($perPage);

        return new EventResourceCollection($events);
    }

    public function show(Event $event): EventResource
    {
        return new EventResource($event->load(self::RELATIONS));
    }

    public function store(array $data, ?int $clientId = null): EventResource
    {
        $event = new Event($this->normalize($data, null));
        $event->client_id = $clientId;
        $event->save();

        return $this->show($event);
    }

    public function update(Event $event, array $data): EventResource
    {
        $event->update($this->normalize($data, $event));

        return $this->show($event->fresh());
    }

    public function remove(Event $event): void
    {
        $event->delete();
    }

    public function nearby(Accommodation $accommodation, CarbonImmutable $from, CarbonImmutable $to, int $radiusM, int $limit): array
    {
        $base = [
            'accommodation_id' => (int) $accommodation->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'radius_m' => $radiusM,
        ];

        $hasLocation = DB::table('accommodations')
            ->where('id', $accommodation->id)
            ->whereNotNull('location')
            ->exists();

        if (! $hasLocation) {
            return [...$base, 'has_location' => false, 'items' => []];
        }

        // Ubicación efectiva: la propia o la de la sede. No se copian coordenadas
        // del POI al evento: se desincronizarían.
        $origin = '(SELECT location FROM accommodations WHERE id = ?)';
        $point = 'COALESCE(events.location, venue.location)';

        $events = Event::query()
            ->select('events.*')
            ->selectRaw("ST_Distance({$point}, {$origin}) AS distance_m", [$accommodation->id])
            ->leftJoin('points_of_interest as venue', function ($join) {
                $join->on('venue.id', '=', 'events.point_of_interest_id')->whereNull('venue.deleted_at');
            })
            ->with(self::RELATIONS)
            ->where('events.enabled', true)
            ->where('events.status', '!=', 'cancelled')
            ->occursBetween($from, $to)
            ->whereRaw("ST_DWithin({$point}, {$origin}, ?)", [$accommodation->id, $radiusM])
            ->limit(200)
            ->get();

        $items = $events
            ->each(fn (Event $e) => $e->setAttribute('occurs_on', $e->nextOccurrence($from)?->toDateString()))
            ->sortBy([
                fn (Event $a, Event $b) => $b->is_featured <=> $a->is_featured,
                fn (Event $a, Event $b) => strcmp((string) $a->getAttribute('occurs_on'), (string) $b->getAttribute('occurs_on')),
                fn (Event $a, Event $b) => (float) $a->getAttribute('distance_m') <=> (float) $b->getAttribute('distance_m'),
            ])
            ->take($limit)
            ->values()
            ->map(fn (Event $e) => new EventResource($e))
            ->all();

        return [...$base, 'has_location' => true, 'items' => $items];
    }

    /**
     * `weekdays` sólo existe en los semanales: al pasar a `none` se limpia, para
     * que no quede un dato muerto que confunda al volver a `weekly`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?Event $event): array
    {
        $recurrence = $data['recurrence'] ?? $event?->recurrence ?? 'none';

        if ($recurrence !== 'weekly') {
            $data['weekdays'] = null;
        } elseif (isset($data['weekdays']) && is_array($data['weekdays'])) {
            $days = array_values(array_unique(array_map('intval', $data['weekdays'])));
            sort($days);
            $data['weekdays'] = $days;
        }

        if (array_key_exists('links', $data) && is_array($data['links'])) {
            $data['links'] = array_values(array_map(
                fn (array $link) => ['type' => (string) $link['type'], 'url' => (string) $link['url']],
                $data['links'],
            ));
        }

        return $data;
    }
}
