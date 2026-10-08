<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\StorePoiCategoryRequest;
use App\Http\Requests\Admin\UpdatePoiCategoryRequest;
use App\Http\Resources\Admin\PoiCategoryResource;
use App\Models\PoiCategory;
use App\Repositories\Contracts\PoiCategoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller as BaseController;

/**
 * Taxonomía de puntos de interés (docs/points-of-interest-plan.md).
 *
 * `index` y `show` son abiertos a cualquier usuario del panel —alimentan los
 * selectores—; la escritura está detrás de `platform`.
 */
class PoiCategoryController extends BaseController
{
    public function __construct(private PoiCategoryInterface $categories) {}

    public function index(): AnonymousResourceCollection
    {
        return $this->categories->tree();
    }

    public function show(PoiCategory $category): PoiCategoryResource
    {
        return new PoiCategoryResource(
            $category->load(['parent', 'children' => fn ($q) => $q->withCount('pointsOfInterest')])
                ->loadCount('pointsOfInterest')
        );
    }

    public function store(StorePoiCategoryRequest $request): JsonResponse
    {
        return $this->categories->store($request)->response()->setStatusCode(201);
    }

    public function update(UpdatePoiCategoryRequest $request, PoiCategory $category): PoiCategoryResource
    {
        return $this->categories->update($category, $request);
    }

    public function destroy(PoiCategory $category): JsonResponse
    {
        $this->categories->remove($category);

        return response()->json(null, 204);
    }
}
