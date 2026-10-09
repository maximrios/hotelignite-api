<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\SearchEventRequest;
use App\Http\Requests\Admin\StoreEventRequest;
use App\Http\Requests\Admin\UpdateEventRequest;
use App\Http\Resources\Admin\EventResource;
use App\Http\Resources\Admin\EventResourceCollection;
use App\Models\Event;
use App\Repositories\Contracts\EventInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * Agenda de eventos (docs/events-plan.md). Lectura abierta a cualquier usuario
 * del panel; la escritura está detrás de `platform`. Los clients escriben por
 * `ClientPanel\V1\EventController`.
 */
class EventController extends BaseController
{
    public function __construct(private EventInterface $events) {}

    public function index(SearchEventRequest $request): EventResourceCollection
    {
        return $this->events->search($request);
    }

    public function show(Event $event): EventResource
    {
        return $this->events->show($event);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        return $this->events->store($request->validated())->response()->setStatusCode(201);
    }

    public function update(UpdateEventRequest $request, Event $event): EventResource
    {
        return $this->events->update($event, $request->validated());
    }

    public function destroy(Event $event): JsonResponse
    {
        $this->events->remove($event);

        return response()->json(null, 204);
    }
}
