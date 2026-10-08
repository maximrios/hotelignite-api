<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\Admin\StorePoiCategoryRequest;
use App\Http\Requests\Admin\UpdatePoiCategoryRequest;
use App\Http\Resources\Admin\PoiCategoryResource;
use App\Models\PoiCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

interface PoiCategoryInterface
{
    /** El árbol completo: raíces con sus hojas y la cantidad de puntos de cada una. */
    public function tree(): AnonymousResourceCollection;

    public function store(StorePoiCategoryRequest $request): PoiCategoryResource;

    public function update(PoiCategory $category, UpdatePoiCategoryRequest $request): PoiCategoryResource;

    /** Aborta con 409 si tiene subcategorías o puntos. */
    public function remove(PoiCategory $category): void;
}
