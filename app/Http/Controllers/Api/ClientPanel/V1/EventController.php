<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Http\Requests\Admin\SearchEventRequest;
use App\Http\Requests\ClientPanel\StoreEventRequest;
use App\Http\Requests\ClientPanel\UpdateEventRequest;
use App\Http\Resources\Admin\EventResource;
use App\Http\Resources\Admin\EventResourceCollection;
use App\Models\Event;
use App\Repositories\Contracts\EventInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * Eventos que carga un client B2B (docs/events-plan.md). Mismo criterio que
 * `PointOfInterestController`: lista lo propio, edita lo propio, `client_id`
 * desde el token.
 */
class EventController extends BaseController
{
    use AuthorizesRequests;

    public function __construct(private EventInterface $events) {}

    public function index(SearchEventRequest $request): EventResourceCollection
    {
        return $this->events->search($request, (int) $request->user()->client_id);
    }

    public function show(Event $event): EventResource
    {
        $this->authorize('update', $event);

        return $this->events->show($event);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $this->authorize('create', Event::class);

        return $this->events->store($request->validated(), (int) $request->user()->client_id)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateEventRequest $request, Event $event): EventResource
    {
        $this->authorize('update', $event);

        return $this->events->update($event, $request->validated());
    }

    public function destroy(Event $event): JsonResponse
    {
        $this->authorize('delete', $event);

        $this->events->remove($event);

        return response()->json(null, 204);
    }
}
