<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\Admin\SearchPointOfInterestRequest;
use App\Http\Resources\Admin\PoiCategoryResource;
use App\Http\Resources\Admin\PointOfInterestResource;
use App\Http\Resources\Admin\PointOfInterestResourceCollection;
use App\Models\Accommodation;
use App\Models\PoiCategory;
use App\Models\PointOfInterest;
use App\Repositories\Contracts\PointOfInterestInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo global de puntos de interés y la búsqueda de cercanos a un
 * alojamiento (docs/points-of-interest-plan.md).
 */
class PointOfInterestRepository implements PointOfInterestInterface
{
    /**
     * Tope para las hojas que se buscan por cercanía sin radio (aeropuerto):
     * más allá de esto no es "cercano" por más que sea el más próximo.
     */
    private const NEAREST_MAX_M = 300000;

    /** A pie: 80 m/min, y la distancia en línea recta subestima las calles ~30 %. */
    private const WALK_M_PER_MIN = 80;

    private const STREET_FACTOR = 1.3;

    /** Sólo se informa el tiempo a pie por debajo de esto. */
    private const WALK_MAX_M = 1500;

    public function search(SearchPointOfInterestRequest $request, ?int $ownerClientId = null): PointOfInterestResourceCollection
    {
        $perPage = min($request->integer('per_page', 20), 100);

        $points = PointOfInterest::query()
            ->with(['city.state', 'category.parent', 'client'])
            ->when($ownerClientId !== null, fn ($q) => $q->ownedByClient($ownerClientId))
            ->when($request->filled('city_id'), fn ($q) => $q->where('city_id', $request->integer('city_id')))
            ->when($request->filled('category_id'), function ($q) use ($request) {
                $id = $request->integer('category_id');
                // Con una raíz, todas sus hojas.
                $q->whereIn('poi_category_id', PoiCategory::query()
                    ->select('id')
                    ->where('id', $id)
                    ->orWhere('parent_id', $id));
            })
            ->when($request->has('featured'), fn ($q) => $q->where('is_featured', $request->boolean('featured')))
            ->when($request->has('enabled'), fn ($q) => $q->where('enabled', $request->boolean('enabled')))
            ->when($request->has('has_location'), fn ($q) => $request->boolean('has_location')
                ? $q->whereNotNull('latitude')
                : $q->whereNull('latitude'))
            ->when($request->filled('q'), function ($q) use ($request) {
                // Sin acentos y sin mayúsculas: "cafe" encuentra "Café".
                $term = '%'.addcslashes((string) $request->input('q'), '%_\\').'%';
                $q->whereRaw('unaccent(points_of_interest.name) ILIKE unaccent(?)', [$term]);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);

        return new PointOfInterestResourceCollection($points);
    }

    public function store(array $data, ?int $clientId = null): PointOfInterestResource
    {
        $poi = new PointOfInterest($data);
        $poi->client_id = $clientId;
        $poi->save();

        return new PointOfInterestResource($poi->load(['city.state', 'category.parent', 'client']));
    }

    public function update(PointOfInterest $poi, array $data): PointOfInterestResource
    {
        $poi->update($data);

        return new PointOfInterestResource($poi->fresh()->load(['city.state', 'category.parent', 'client']));
    }

    public function remove(PointOfInterest $poi): void
    {
        $poi->delete();
    }

    public function nearby(Accommodation $accommodation, int $limitPerGroup): array
    {
        // El origen sale de `location` y no de `latitude`/`longitude`: son varchar
        // legacy, y la columna generada es la versión ya validada que usa la búsqueda.
        $origin = DB::table('accommodations')
            ->where('id', $accommodation->id)
            ->whereNotNull('location')
            ->selectRaw('ST_Y(location::geometry) AS latitude, ST_X(location::geometry) AS longitude')
            ->first();

        if ($origin === null) {
            return [
                'accommodation_id' => (int) $accommodation->id,
                'has_location' => false,
                'origin' => null,
                'groups' => [],
            ];
        }

        /** @var Collection<int, float> $distances id del POI → distancia en metros */
        $distances = $this->withinRadius((int) $accommodation->id, $limitPerGroup)
            ->concat($this->nearestByLeaf((int) $accommodation->id))
            ->mapWithKeys(fn (object $row) => [(int) $row->id => (float) $row->distance_m]);

        $points = PointOfInterest::query()
            ->with('category.parent')
            ->whereIn('id', $distances->keys())
            ->get()
            ->each(function (PointOfInterest $poi) use ($distances) {
                $meters = $distances[$poi->id];
                $poi->setAttribute('distance_m', $meters);
                $poi->setAttribute('walk_min', $meters < self::WALK_MAX_M
                    ? (int) max(1, ceil($meters * self::STREET_FACTOR / self::WALK_M_PER_MIN))
                    : null);
            });

        $groups = $points
            ->groupBy(fn (PointOfInterest $poi) => $poi->category->parent_id)
            ->map(function (Collection $items) {
                /** @var PoiCategory $root */
                $root = $items->first()->category->parent;

                return [
                    'root' => $root,
                    'items' => $items
                        ->sortBy([
                            fn (PointOfInterest $a, PointOfInterest $b) => $b->is_featured <=> $a->is_featured,
                            fn (PointOfInterest $a, PointOfInterest $b) => $a->distance_m <=> $b->distance_m,
                        ])
                        ->values(),
                ];
            })
            ->sortBy(fn (array $group) => [$group['root']->sort_order, $group['root']->name])
            ->map(fn (array $group) => [
                'category' => new PoiCategoryResource($group['root']),
                'items' => PointOfInterestResource::collection($group['items'])->all(),
            ])
            ->values()
            ->all();

        return [
            'accommodation_id' => (int) $accommodation->id,
            'has_location' => true,
            'origin' => ['latitude' => (float) $origin->latitude, 'longitude' => (float) $origin->longitude],
            'groups' => $groups,
        ];
    }

    /**
     * Los N mejores de cada categoría raíz dentro del radio de su hoja (o, si la
     * hoja no lo pisa, el de la raíz). Destacados primero, después por distancia.
     * `ST_DWithin` sobre `geography` usa el índice GiST y mide en metros.
     *
     * @return Collection<int, object{id: int, distance_m: float}>
     */
    private function withinRadius(int $accommodationId, int $limitPerGroup): Collection
    {
        $rows = DB::select(<<<'SQL'
            WITH acc AS (SELECT location FROM accommodations WHERE id = ?),
            ranked AS (
                SELECT p.id,
                       ST_Distance(p.location, acc.location) AS distance_m,
                       ROW_NUMBER() OVER (
                           PARTITION BY root.id
                           ORDER BY p.is_featured DESC, ST_Distance(p.location, acc.location), p.id
                       ) AS rn
                FROM points_of_interest p
                JOIN poi_categories leaf ON leaf.id = p.poi_category_id
                JOIN poi_categories root ON root.id = leaf.parent_id
                CROSS JOIN acc
                WHERE p.deleted_at IS NULL
                  AND p.enabled
                  AND p.location IS NOT NULL
                  AND leaf.enabled AND root.enabled
                  AND leaf.nearest_limit IS NULL
                  AND ST_DWithin(p.location, acc.location, COALESCE(leaf.default_radius_m, root.default_radius_m))
            )
            SELECT id, distance_m FROM ranked WHERE rn <= ?
            SQL, [$accommodationId, $limitPerGroup]);

        return collect($rows);
    }

    /**
     * Hojas con `nearest_limit` (el aeropuerto): los N más cercanos sin radio,
     * por KNN (`<->` usa el índice GiST), con un tope de distancia.
     *
     * @return Collection<int, object{id: int, distance_m: float}>
     */
    private function nearestByLeaf(int $accommodationId): Collection
    {
        $rows = DB::select(<<<'SQL'
            WITH acc AS (SELECT location FROM accommodations WHERE id = ?)
            SELECT n.id, n.distance_m
            FROM poi_categories leaf
            JOIN poi_categories root ON root.id = leaf.parent_id
            CROSS JOIN acc
            CROSS JOIN LATERAL (
                SELECT p.id, ST_Distance(p.location, acc.location) AS distance_m
                FROM points_of_interest p
                WHERE p.poi_category_id = leaf.id
                  AND p.deleted_at IS NULL
                  AND p.enabled
                  AND p.location IS NOT NULL
                  AND ST_DWithin(p.location, acc.location, ?)
                ORDER BY p.location <-> acc.location
                LIMIT leaf.nearest_limit
            ) n
            WHERE leaf.nearest_limit IS NOT NULL
              AND leaf.enabled AND root.enabled
            SQL, [$accommodationId, self::NEAREST_MAX_M]);

        return collect($rows);
    }
}
