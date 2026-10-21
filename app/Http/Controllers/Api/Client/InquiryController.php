<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Requests\Client\StorePortalInquiryRequest;
use App\Models\Accommodation;
use App\Models\Experience;
use App\Repositories\Contracts\InquiryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * Consultas que un portal manda en nombre de un viajero (ability
 * `inquiry:create`). Tenencia: el alojamiento tiene que estar en el padrón del
 * client, y la experiencia ser suya y estar publicada. Lo ajeno es 404, igual
 * que en la lectura: no se confirma que exista.
 */
class InquiryController extends BaseController
{
    public function __construct(private InquiryInterface $inquiries) {}

    public function store(StorePortalInquiryRequest $request): JsonResponse
    {
        $clientId = (int) $request->user()->client_id;
        $data = $request->validated();

        $inquirable = isset($data['experience_id'])
            ? Experience::query()->ownedByClient($clientId)->published()->find($data['experience_id'])
            : Accommodation::visibleTo($request->user())->find($data['accommodation_id']);

        abort_if($inquirable === null, 404);

        return $this->inquiries->storeFromPortal($inquirable, $clientId, $data)
            ->response()
            ->setStatusCode(201);
    }
}
