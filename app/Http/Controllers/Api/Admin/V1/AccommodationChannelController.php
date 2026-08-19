<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\StoreAccommodationChannelRequest;
use App\Http\Requests\Admin\UpdateAccommodationChannelRequest;
use App\Http\Resources\Admin\AccommodationChannelResource;
use App\Http\Resources\Admin\ChannelResource;
use App\Models\Accommodation;
use App\Models\Channel;
use App\Repositories\Contracts\AccommodationChannelInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Menú Channels del PMS: a qué canales está conectado este alojamiento y con
 * qué condiciones.
 *
 * La lista es la unión de los canales propios y los que llegan por el padrón de
 * un client — ver `AccommodationChannelRepository`. Tenencia vía
 * `AccommodationPolicy`; los clients B2B no llegan acá porque el grupo entero
 * está bajo `client.readonly`.
 */
class AccommodationChannelController extends BaseController
{
    use AuthorizesRequests;

    public function __construct(private AccommodationChannelInterface $channels)
    {
    }

    public function index(Request $request, Accommodation $accommodation): JsonResponse
    {
        $this->authorize('view', $accommodation);
        $this->denyToClients($request);

        return response()->json([
            'data' => AccommodationChannelResource::collection($this->channels->connected($accommodation)),
        ]);
    }

    /** Vidriera de canales que el hotel todavía puede conectar. */
    public function available(Request $request, Accommodation $accommodation): JsonResponse
    {
        $this->authorize('view', $accommodation);
        $this->denyToClients($request);

        return response()->json([
            'data' => ChannelResource::collection($this->channels->available($accommodation)),
        ]);
    }

    /**
     * Un client B2B ve la ficha de sus asociados, pero no su mezcla de canales:
     * ahí está con quién distribuye y qué comisión paga. `AccommodationPolicy`
     * no alcanza para cortarlo —le da `view` sobre todo su padrón—, así que el
     * corte va acá, que es donde el dato es comercialmente sensible.
     */
    private function denyToClients(Request $request): void
    {
        abort_if(
            $request->user()?->isClient() ?? false,
            403,
            'Los canales de distribución de un alojamiento no son visibles para un client.'
        );
    }

    public function store(StoreAccommodationChannelRequest $request, Accommodation $accommodation): JsonResponse
    {
        $this->authorize('update', $accommodation);

        $row = $this->channels->connect($accommodation, $request);

        return (new AccommodationChannelResource($row))->response()->setStatusCode(201);
    }

    public function update(UpdateAccommodationChannelRequest $request, Accommodation $accommodation, Channel $channel): AccommodationChannelResource
    {
        $this->authorize('update', $accommodation);

        return new AccommodationChannelResource(
            $this->channels->configure($accommodation, $channel, $request)
        );
    }

    public function destroy(Accommodation $accommodation, Channel $channel): JsonResponse
    {
        $this->authorize('update', $accommodation);

        $this->channels->disconnect($accommodation, $channel);

        return response()->json(null, 204);
    }
}
