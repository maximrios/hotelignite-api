<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\Admin\SearchExperienceRequest;
use App\Http\Resources\Admin\ExperienceResource;
use App\Http\Resources\Admin\ExperienceResourceCollection;
use App\Models\Experience;
use App\Repositories\Contracts\ExperienceInterface;
use Illuminate\Support\Facades\DB;

/** Experiencias del portal de cada client (docs/experiences-plan.md). */
class ExperienceRepository implements ExperienceInterface
{
    private const RELATIONS = ['category', 'departureCity.state', 'cities', 'meetingPoint', 'client', 'media'];

    public function search(SearchExperienceRequest $request, ?int $ownerClientId = null): ExperienceResourceCollection
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $client = $request->input('client_id');

        $experiences = Experience::query()
            ->with(self::RELATIONS)
            ->when($ownerClientId !== null, fn ($q) => $q->ownedByClient($ownerClientId))
            ->when($client === 'none', fn ($q) => $q->whereNull('client_id'))
            ->when(is_string($client) && ctype_digit($client), fn ($q) => $q->ownedByClient((int) $client))
            ->when($request->filled('city_id'), fn ($q) => $q->inCity($request->integer('city_id')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('experience_category_id', $request->integer('category_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('difficulty'), fn ($q) => $q->where('difficulty', $request->input('difficulty')))
            ->when($request->filled('duration_kind'), fn ($q) => $q->durationKind((string) $request->input('duration_kind')))
            ->when($request->has('featured'), fn ($q) => $q->where('is_featured', $request->boolean('featured')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.addcslashes((string) $request->input('q'), '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('unaccent(experiences.name) ILIKE unaccent(?)', [$term])
                    ->orWhereRaw('unaccent(experiences.operator_name) ILIKE unaccent(?)', [$term]));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);

        return new ExperienceResourceCollection($experiences);
    }

    public function show(Experience $experience): ExperienceResource
    {
        return new ExperienceResource($experience->load(self::RELATIONS));
    }

    public function store(array $data, ?int $clientId = null): ExperienceResource
    {
        $experience = DB::transaction(function () use ($data, $clientId) {
            $cityIds = $data['city_ids'] ?? [];
            $owner = $clientId ?? $data['client_id'] ?? null;

            $experience = new Experience($this->normalize($data, null));
            $experience->client_id = $owner !== null ? (int) $owner : null;
            $experience->save();

            $experience->cities()->sync($this->destinations((array) $cityIds, $experience->departure_city_id));

            return $experience;
        });

        return $this->show($experience);
    }

    public function update(Experience $experience, array $data): ExperienceResource
    {
        DB::transaction(function () use ($experience, $data) {
            $experience->fill($this->normalize($data, $experience));

            if (array_key_exists('client_id', $data)) {
                $experience->client_id = $data['client_id'] !== null ? (int) $data['client_id'] : null;
            }

            $experience->save();

            // Al cambiar la salida, sale del pivote si estaba: no se lista dos veces.
            if (array_key_exists('city_ids', $data) || $experience->wasChanged('departure_city_id')) {
                $cityIds = array_key_exists('city_ids', $data)
                    ? (array) $data['city_ids']
                    : $experience->cities()->pluck('cities.id')->all();

                $experience->cities()->sync($this->destinations($cityIds, $experience->departure_city_id));
            }
        });

        return $this->show($experience->fresh());
    }

    public function remove(Experience $experience): void
    {
        $experience->delete();
    }

    /**
     * Destinos sin la ciudad de salida, que ya es implícita.
     *
     * @param  array<int, mixed>  $cityIds
     * @return list<int>
     */
    private function destinations(array $cityIds, int $departureCityId): array
    {
        return array_values(array_filter(
            array_unique(array_map('intval', $cityIds)),
            fn (int $id) => $id !== $departureCityId,
        ));
    }

    /**
     * Listas ordenadas y sin vacíos. Días y meses vacíos valen null (= siempre),
     * y el precio sólo existe si es pago.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?Experience $experience): array
    {
        unset($data['city_ids'], $data['client_id']);

        foreach (['operating_days', 'season_months'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $this->sortedInts($data[$key]);
            }
        }

        foreach (['includes', 'excludes', 'languages'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $this->strings($data[$key]);
            }
        }

        if (($data['price_type'] ?? $experience?->price_type ?? 'unknown') !== 'paid') {
            $data['price_from'] = null;
        }

        if (array_key_exists('links', $data) && is_array($data['links'])) {
            $data['links'] = array_values(array_map(
                fn (array $link) => ['type' => (string) $link['type'], 'url' => (string) $link['url']],
                $data['links'],
            ));
        }

        return $data;
    }

    /** @return list<int>|null */
    private function sortedInts(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $ints = array_values(array_unique(array_map('intval', $value)));
        sort($ints);

        return $ints;
    }

    /** @return list<string>|null */
    private function strings(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $items = array_values(array_filter(
            array_map(fn ($item) => trim((string) $item), $value),
            fn (string $item) => $item !== '',
        ));

        return $items === [] ? null : $items;
    }
}
