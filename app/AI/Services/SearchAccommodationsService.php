<?php

namespace App\AI\Services;

use App\AI\Exceptions\AiToolException;
use App\AI\Mappers\AccommodationMapper;
use App\AI\Support\AiContext;
use App\Models\Accommodation;
use App\Models\City;
use Illuminate\Database\Eloquent\Builder;

/**
 * Herramienta `search_accommodations`: alojamientos visibles para el client,
 * filtrados por destino, tipo y texto libre. Sin fechas: para disponibilidad
 * está `search_availability`.
 */
final class SearchAccommodationsService
{
    public const MAX_LIMIT = 10;

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function handle(AiContext $ctx, ?string $destination = null, ?string $type = null, ?string $query = null, int $limit = self::MAX_LIMIT): array
    {
        $accommodations = $this->baseQuery($ctx, $destination, $type, $query)
            ->with(AccommodationMapper::EAGER)
            ->orderBy('name')
            ->limit(min(max($limit, 1), self::MAX_LIMIT))
            ->get();

        return [
            'items' => $accommodations
                ->map(fn (Accommodation $a) => AccommodationMapper::summary($a, $ctx->language))
                ->values()
                ->all(),
        ];
    }

    /**
     * Query de alojamientos visibles y habilitados con los filtros aplicados.
     * La reutiliza `SearchAvailabilityService` para elegir candidatos.
     *
     * @return Builder<Accommodation>
     */
    public function baseQuery(AiContext $ctx, ?string $destination, ?string $type, ?string $query): Builder
    {
        $builder = Accommodation::visibleTo($ctx->actor)->where('enabled', 1);

        if ($destination !== null && $destination !== '') {
            $cityId = City::where('slug', $destination)->value('id');

            if ($cityId === null) {
                throw new AiToolException("No existe el destino \"{$destination}\". Usá list_destinations para obtener el slug correcto.");
            }

            $builder->where('city_id', $cityId);
        }

        if ($type !== null && $type !== '') {
            $builder->whereHas('type', fn ($q) => $q->whereLike('name', '%'.$type.'%'));
        }

        if ($query !== null && $query !== '') {
            $like = '%'.$query.'%';

            $builder->where(fn ($q) => $q
                ->whereLike('name', $like)
                ->orWhereHas('type', fn ($t) => $t->whereLike('name', $like))
                ->orWhereHas('services', fn ($s) => $s->whereLike('name', $like))
                ->orWhereHas('descriptions', fn ($d) => $d
                    ->whereLike('introduction', $like)
                    ->orWhereLike('description', $like)));
        }

        return $builder;
    }
}
