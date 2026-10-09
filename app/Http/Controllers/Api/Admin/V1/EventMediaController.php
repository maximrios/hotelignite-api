<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\ReorderMediaRequest;
use App\Http\Requests\Admin\StoreMediaRequest;
use App\Models\Event;
use App\Models\Media;
use App\Repositories\Contracts\MediaInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller as BaseController;

/**
 * Galería de un evento. La comparten `admin/v1` (staff) y `client-panel/v1`
 * (client): en los dos casos la policy decide, así que un client sólo toca la
 * galería de sus eventos.
 */
class EventMediaController extends BaseController
{
    use AuthorizesRequests;

    public function __construct(private MediaInterface $media) {}

    public function store(StoreMediaRequest $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        return $this->media->add($event, $request->validated())->response()->setStatusCode(201);
    }

    public function reorder(ReorderMediaRequest $request, Event $event): AnonymousResourceCollection
    {
        $this->authorize('update', $event);

        return $this->media->reorder($event, $request->validated('ids'));
    }

    public function destroy(Event $event, Media $media): JsonResponse
    {
        $this->authorize('update', $event);

        $this->media->remove($event, $media);

        return response()->json(null, 204);
    }
}
