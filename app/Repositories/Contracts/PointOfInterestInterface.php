<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\Admin\SearchPointOfInterestRequest;
use App\Http\Requests\Admin\StorePointOfInterestRequest;
use App\Http\Requests\Admin\UpdatePointOfInterestRequest;
use App\Http\Resources\Admin\PoiCategoryResource;
use App\Http\Resources\Admin\PointOfInterestResource;
use App\Http\Resources\Admin\PointOfInterestResourceCollection;
use App\Models\Accommodation;
use App\Models\PointOfInterest;

interface PointOfInterestInterface
{
    public function search(SearchPointOfInterestRequest $request): PointOfInterestResourceCollection;

    public function store(StorePointOfInterestRequest $request): PointOfInterestResource;

    public function update(PointOfInterest $poi, UpdatePointOfInterestRequest $request): PointOfInterestResource;

    public function remove(PointOfInterest $poi): void;

    /**
     * Puntos cercanos a un alojamiento, agrupados por categoría raíz.
     *
     * @return array{accommodation_id: int, has_location: bool, origin: array{latitude: float, longitude: float}|null, groups: list<array{category: PoiCategoryResource, items: list<PointOfInterestResource>}>}
     */
    public function nearby(Accommodation $accommodation, int $limitPerGroup): array;
}
