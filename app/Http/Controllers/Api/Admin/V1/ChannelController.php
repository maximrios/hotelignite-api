<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\SearchChannelRequest;
use App\Http\Requests\StoreChannelRequest;
use App\Http\Requests\UpdateChannelRequest;
use App\Http\Resources\Admin\ChannelResource;
use App\Models\Channel;
use App\Repositories\Contracts\ChannelInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * Catálogo global de canales de distribución.
 *
 * `index` y `show` quedan abiertos a cualquier usuario del panel: el PMS los
 * necesita para armar la vidriera de canales disponibles. La escritura está
 * detrás de `platform` porque el catálogo es compartido — si cada hotel se
 * creara su propio "Booking.com", el reporting cruzado por canal desaparece.
 *
 * Mismo criterio que el CRUD de servicios (`docs/services-admin-crud-plan.md`).
 */
class ChannelController extends BaseController
{
    protected ChannelInterface $channelInterface;

    public function __construct(ChannelInterface $channelInterface)
    {
        $this->channelInterface = $channelInterface;
    }

    public function index(SearchChannelRequest $request)
    {
        return $this->channelInterface->search($request);
    }

    public function show(Channel $channel)
    {
        return new ChannelResource($channel->loadCount(['accommodations', 'clients']));
    }

    public function store(StoreChannelRequest $request): JsonResponse
    {
        return $this->channelInterface->store($request)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateChannelRequest $request, Channel $channel)
    {
        return $this->channelInterface->update($channel, $request);
    }

    public function destroy(Channel $channel): JsonResponse
    {
        $this->channelInterface->remove($channel);

        return response()->json(null, 204);
    }
}
