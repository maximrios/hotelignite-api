<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Resources\Public\PublicExperienceResource;
use App\Models\Experience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\Rule;

/**
 * Experiencias del portal (docs/experiences-plan.md). El catálogo es por
 * client: la key sólo ve las suyas (`client_id` = el client de la key) y
 * publicadas. Lo de otro client o en borrador es un 404, no un 403.
 */
class ExperienceController extends BaseController
{
    private const RELATIONS = ['category', 'departureCity', 'cities', 'meetingPoint', 'media'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'city' => ['sometimes', 'integer'],
            'category' => ['sometimes', 'string', 'max:100'],
            'duration_kind' => ['sometimes', Rule::in(Experience::DURATION_KINDS)],
            'featured' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $experiences = $this->visible($request)
            ->with(self::RELATIONS)
            ->when($request->filled('city'), fn ($q) => $q->inCity($request->integer('city')))
            ->when($request->filled('category'), fn ($q) => $q->whereHas(
                'category',
                fn (Builder $c) => $c->where('slug', $request->query('category')),
            ))
            ->when($request->filled('duration_kind'), fn ($q) => $q->durationKind((string) $request->query('duration_kind')))
            ->when($request->has('featured'), fn ($q) => $q->where('is_featured', $request->boolean('featured')))
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(min($request->integer('per_page', 50), 100));

        return PublicExperienceResource::collection($experiences);
    }

    public function show(Request $request, string $slug): PublicExperienceResource
    {
        $experience = $this->visible($request)->with(self::RELATIONS)->where('slug', $slug)->firstOrFail();

        return new PublicExperienceResource($experience);
    }

    /** @return Builder<Experience> */
    private function visible(Request $request): Builder
    {
        return Experience::query()
            ->ownedByClient((int) $request->user()?->client_id)
            ->published();
    }
}
