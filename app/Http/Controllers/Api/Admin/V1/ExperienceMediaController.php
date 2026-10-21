<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\ReorderMediaRequest;
use App\Http\Requests\Admin\StoreMediaRequest;
use App\Models\Experience;
use App\Models\Media;
use App\Repositories\Contracts\MediaInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller as BaseController;

/**
 * Galería de una experiencia. La comparten `admin/v1` (staff) y `client-panel/v1`
 * (client): en los dos casos la policy decide, así que un client sólo toca la
 * galería de sus experiencias.
 */
class ExperienceMediaController extends BaseController
{
    use AuthorizesRequests;

    public function __construct(private MediaInterface $media) {}

    public function store(StoreMediaRequest $request, Experience $experience): JsonResponse
    {
        $this->authorize('update', $experience);

        return $this->media->add($experience, $request->validated())->response()->setStatusCode(201);
    }

    public function reorder(ReorderMediaRequest $request, Experience $experience): AnonymousResourceCollection
    {
        $this->authorize('update', $experience);

        return $this->media->reorder($experience, $request->validated('ids'));
    }

    public function destroy(Experience $experience, Media $media): JsonResponse
    {
        $this->authorize('update', $experience);

        $this->media->remove($experience, $media);

        return response()->json(null, 204);
    }
}
