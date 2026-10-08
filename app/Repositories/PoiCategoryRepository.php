<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\Admin\StorePoiCategoryRequest;
use App\Http\Requests\Admin\UpdatePoiCategoryRequest;
use App\Http\Resources\Admin\PoiCategoryResource;
use App\Models\PoiCategory;
use App\Repositories\Contracts\PoiCategoryInterface;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Taxonomía de puntos de interés: dos niveles y nunca más. Las reglas que
 * dependen del estado actual (no convertir en hoja a una raíz con hijas, no
 * dejar puntos colgando de una raíz) van acá y no en el FormRequest.
 */
class PoiCategoryRepository implements PoiCategoryInterface
{
    public function tree(): AnonymousResourceCollection
    {
        $roots = PoiCategory::roots()
            ->with(['children' => fn ($q) => $q->withCount('pointsOfInterest')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return PoiCategoryResource::collection($roots);
    }

    public function store(StorePoiCategoryRequest $request): PoiCategoryResource
    {
        $category = PoiCategory::create($request->validated());

        return new PoiCategoryResource($category->loadCount('pointsOfInterest'));
    }

    public function update(PoiCategory $category, UpdatePoiCategoryRequest $request): PoiCategoryResource
    {
        $data = $request->validated();

        if (array_key_exists('parent_id', $data)) {
            $this->assertCanMove($category, $data['parent_id']);
        }

        $category->update($data);

        return new PoiCategoryResource($category->fresh()->loadCount('pointsOfInterest'));
    }

    public function remove(PoiCategory $category): void
    {
        abort_if(
            $category->children()->exists(),
            409,
            'La categoría tiene subcategorías. Borralas o movelas antes.'
        );

        // withTrashed: la FK es RESTRICT y también la frenan los puntos dados de baja.
        abort_if(
            $category->pointsOfInterest()->withTrashed()->exists(),
            409,
            'La categoría tiene puntos de interés. Movelos a otra antes de borrarla.'
        );

        $category->delete();
    }

    private function assertCanMove(PoiCategory $category, ?int $parentId): void
    {
        if ($parentId !== null && $category->children()->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => 'Una categoría con subcategorías no puede pasar a ser subcategoría.',
            ]);
        }

        if ($parentId === null && $category->pointsOfInterest()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => 'La categoría tiene puntos de interés: no puede pasar a ser raíz.',
            ]);
        }
    }
}
