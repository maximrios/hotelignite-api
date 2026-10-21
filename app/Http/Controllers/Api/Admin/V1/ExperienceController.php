<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\SearchExperienceRequest;
use App\Http\Requests\Admin\StoreExperienceRequest;
use App\Http\Requests\Admin\UpdateExperienceRequest;
use App\Http\Resources\Admin\ExperienceResource;
use App\Http\Resources\Admin\ExperienceResourceCollection;
use App\Models\Experience;
use App\Repositories\Contracts\ExperienceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * Experiencias (docs/experiences-plan.md). A diferencia de eventos y POI, el
 * catálogo es por client: todo el recurso —lectura incluida— está detrás de
 * `platform`. El staff asigna cada experiencia a un client (`client_id`).
 */
class ExperienceController extends BaseController
{
    public function __construct(private ExperienceInterface $experiences) {}

    public function index(SearchExperienceRequest $request): ExperienceResourceCollection
    {
        return $this->experiences->search($request);
    }

    public function show(Experience $experience): ExperienceResource
    {
        return $this->experiences->show($experience);
    }

    public function store(StoreExperienceRequest $request): JsonResponse
    {
        return $this->experiences->store($request->validated())->response()->setStatusCode(201);
    }

    public function update(UpdateExperienceRequest $request, Experience $experience): ExperienceResource
    {
        return $this->experiences->update($experience, $request->validated());
    }

    public function destroy(Experience $experience): JsonResponse
    {
        $this->experiences->remove($experience);

        return response()->json(null, 204);
    }
}
