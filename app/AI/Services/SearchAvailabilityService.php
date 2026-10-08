<?php

namespace App\AI\Services;

use App\AI\Mappers\AccommodationMapper;
use App\AI\Support\AiContext;
use App\Models\Accommodation;

/**
 * Herramienta `search_availability`: "¿qué hay en Cafayate del 10 al 15 para
 * 2?" en una sola llamada, en lugar de que el agente consulte alojamiento por
 * alojamiento.
 *
 * Evalúa hasta `MAX_CANDIDATES` alojamientos del destino con la misma lógica de
 * `check_availability` y devuelve los que no están `unavailable`, primero los
 * confirmados (`available`) y después los `unknown`.
 */
final class SearchAvailabilityService
{
    private const MAX_CANDIDATES = 30;

    public function __construct(
        private readonly SearchAccommodationsService $search,
        private readonly CheckAvailabilityService $availability,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, evaluated: int}
     */
    public function handle(AiContext $ctx, string $destination, string $checkin, string $checkout, int $adults, int $children = 0, int $limit = SearchAccommodationsService::MAX_LIMIT): array
    {
        [$from, $to] = CheckAvailabilityService::parseStay($checkin, $checkout);

        $candidates = $this->search->baseQuery($ctx, $destination, null, null)
            ->with([...AccommodationMapper::EAGER, 'roomTypes.descriptions'])
            ->orderBy('name')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        $items = $candidates
            ->map(fn (Accommodation $a) => [
                ...AccommodationMapper::summary($a, $ctx->language),
                ...$this->availability->evaluate($a, $from, $to, $adults + $children, $ctx->language),
            ])
            ->reject(fn (array $item) => $item['status'] === 'unavailable')
            ->sortBy(fn (array $item) => [$item['status'] === 'available' ? 0 : 1, $item['price_from']['amount'] ?? PHP_INT_MAX])
            ->take(min(max($limit, 1), SearchAccommodationsService::MAX_LIMIT))
            // `rooms` ya está en la ficha; acá solo engorda la respuesta.
            ->map(fn (array $item) => array_diff_key($item, ['rooms' => true]))
            ->values()
            ->all();

        return ['items' => $items, 'evaluated' => $candidates->count()];
    }
}
