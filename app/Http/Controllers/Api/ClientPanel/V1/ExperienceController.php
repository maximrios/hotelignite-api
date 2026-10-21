<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Http\Requests\Admin\SearchExperienceRequest;
use App\Http\Requests\ClientPanel\StoreExperienceRequest;
use App\Http\Requests\ClientPanel\UpdateExperienceRequest;
use App\Http\Resources\Admin\ExperienceResource;
use App\Http\Resources\Admin\ExperienceResourceCollection;
use App\Models\Experience;
use App\Repositories\Contracts\ExperienceInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * Experiencias que carga un client para su portal (docs/experiences-plan.md).
 * Lista, ve y edita sólo lo suyo; `client_id` sale del token. Puede publicar:
 * lo que publica sale únicamente en su propio portal.
 */
class ExperienceController extends BaseController
{
    use AuthorizesRequests;

    public function __construct(private ExperienceInterface $experiences) {}

    public function index(SearchExperienceRequest $request): ExperienceResourceCollection
    {
        return $this->experiences->search($request, (int) $request->user()->client_id);
    }

    public function show(Experience $experience): ExperienceResource
    {
        $this->authorize('update', $experience);

        return $this->experiences->show($experience);
    }

    public function store(StoreExperienceRequest $request): JsonResponse
    {
        $this->authorize('create', Experience::class);

        return $this->experiences->store($request->validated(), (int) $request->user()->client_id)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateExperienceRequest $request, Experience $experience): ExperienceResource
    {
        $this->authorize('update', $experience);

        return $this->experiences->update($experience, $request->validated());
    }

    public function destroy(Experience $experience): JsonResponse
    {
        $this->authorize('delete', $experience);

        $this->experiences->remove($experience);

        return response()->json(null, 204);
    }
}
