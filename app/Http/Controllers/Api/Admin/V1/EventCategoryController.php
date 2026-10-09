<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Resources\Admin\EventCategoryResource;
use App\Models\EventCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller as BaseController;

/**
 * Categorías de eventos, para los selectores. Se cargan por seeder
 * (`EventCategorySeeder`); el CRUD desde el CRM es fase 2.
 */
class EventCategoryController extends BaseController
{
    public function index(): AnonymousResourceCollection
    {
        return EventCategoryResource::collection(
            EventCategory::query()->orderBy('sort_order')->orderBy('name')->get()
        );
    }
}
