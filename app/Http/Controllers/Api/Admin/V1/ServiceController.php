<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\SearchServiceRequest;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Http\Resources\V1\ServiceResource;
use App\Models\Service;
use App\Repositories\Contracts\ServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * Catálogo global de servicios para el CRM (`docs/services-admin-crud-plan.md`).
 *
 * `index` y `show` son abiertos a cualquier usuario del panel —los necesita
 * quien edita un alojamiento—; la escritura está detrás de `platform`, porque
 * el catálogo es compartido por todas las cuentas.
 *
 * Las escrituras devuelven el Resource sin `response()->json()` a propósito: así
 * pasa por `toResponse()` y sale envuelto en `data`, que es lo que consume el
 * CRM (`res.data.id`).
 */
class ServiceController extends BaseController
{
    protected ServiceInterface $serviceInterface;

    public function __construct(ServiceInterface $serviceInterface)
    {
        $this->serviceInterface = $serviceInterface;
    }

    public function index(SearchServiceRequest $request)
    {
        $services = $this->serviceInterface->search($request);

        return response()->json($services, 200);
    }

    public function show(Service $service)
    {
        return new ServiceResource($service->loadCount('accommodations'));
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        return $this->serviceInterface->store($request)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateServiceRequest $request, Service $service)
    {
        return $this->serviceInterface->update($service, $request);
    }

    public function destroy(Service $service): JsonResponse
    {
        $this->serviceInterface->remove($service);

        return response()->json(null, 204);
    }
}
