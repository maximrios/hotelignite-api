<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\Admin\SearchPointOfInterestRequest;
use App\Http\Resources\Admin\PoiCategoryResource;
use App\Http\Resources\Admin\PointOfInterestResource;
use App\Http\Resources\Admin\PointOfInterestResourceCollection;
use App\Models\Accommodation;
use App\Models\PointOfInterest;

interface PointOfInterestInterface
{
    /** `$ownerClientId`: sólo los cargados por ese client (el listado del portal). */
    public function search(SearchPointOfInterestRequest $request, ?int $ownerClientId = null): PointOfInterestResourceCollection;

    /**
     * @param  array<string, mixed>  $data  ya validado
     * @param  int|null  $clientId  quién lo carga; null = staff
     */
    public function store(array $data, ?int $clientId = null): PointOfInterestResource;

    /** @param  array<string, mixed>  $data  ya validado */
    public function update(PointOfInterest $poi, array $data): PointOfInterestResource;

    public function remove(PointOfInterest $poi): void;

    /**
     * Puntos cercanos a un alojamiento, agrupados por categoría raíz.
     *
     * @return array{accommodation_id: int, has_location: bool, origin: array{latitude: float, longitude: float}|null, groups: list<array{category: PoiCategoryResource, items: list<PointOfInterestResource>}>}
     */
    public function nearby(Accommodation $accommodation, int $limitPerGroup): array;
}
