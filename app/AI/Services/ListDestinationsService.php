<?php

namespace App\AI\Services;

use App\AI\Support\AiContext;
use App\Models\Accommodation;
use App\Models\City;

/**
 * Herramienta `list_destinations`: ciudades donde el client tiene alojamientos
 * habilitados. Sirve para pasar de "Cafayate" al slug que piden las demás
 * herramientas.
 */
final class ListDestinationsService
{
    private const MAX_ITEMS = 30;

    /**
     * @return array{items: list<array{slug: string, name: string, state: ?string, accommodations_count: int}>}
     */
    public function handle(AiContext $ctx, ?string $search = null): array
    {
        $counts = Accommodation::visibleTo($ctx->actor)
            ->where('enabled', 1)
            ->whereNotNull('city_id')
            ->selectRaw('city_id, count(*) as total')
            ->groupBy('city_id')
            ->pluck('total', 'city_id');

        if ($counts->isEmpty()) {
            return ['items' => []];
        }

        $cities = City::query()
            ->with('state')
            ->whereIn('id', $counts->keys())
            ->when($search !== null && $search !== '', fn ($q) => $q->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')
            ->limit(self::MAX_ITEMS)
            ->get();

        return [
            'items' => $cities->map(fn (City $city) => [
                'slug' => (string) $city->slug,
                'name' => (string) $city->name,
                'state' => $city->state?->name,
                'accommodations_count' => (int) $counts[$city->id],
            ])->values()->all(),
        ];
    }
}
