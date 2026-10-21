<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Resources\Admin\ExperienceCategoryResource;
use App\Models\ExperienceCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller as BaseController;

/**
 * Categorías de experiencias, para los selectores. Se cargan por seeder
 * (`ExperienceCategorySeeder`); el CRUD desde el CRM es fase 2.
 */
class ExperienceCategoryController extends BaseController
{
    public function index(): AnonymousResourceCollection
    {
        return ExperienceCategoryResource::collection(
            ExperienceCategory::query()->orderBy('sort_order')->orderBy('name')->get()
        );
    }
}
