<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\DestroyServiceRequest;
use App\Http\Requests\SearchServiceRequest;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Http\Resources\V1\ServiceResource;
use App\Http\Resources\V1\ServiceResourceCollection;
use App\Models\Service;
use App\Repositories\Contracts\ServiceInterface;
use Illuminate\Http\Request;

class ServiceRepository implements ServiceInterface
{
    public function all(Request $request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $services = Service::when($request->enabled !== null, function ($q) use ($request) {
            return $q->where('enabled', $request->enabled);
        })
            ->withCount('accommodations')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return new ServiceResourceCollection($services);
    }

    public function find($id)
    {
        $service = Service::withCount('accommodations')->find($id);

        return new ServiceResource($service);
    }

    public function search(SearchServiceRequest $request)
    {
        $perPage = min($request->integer('per_page', 10), 100);

        $services = Service::query()
            ->when($request->has('enabled'), function ($q) use ($request) {
                $q->where('enabled', $request->boolean('enabled'));
            })
            ->when($request->filled('name'), function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->name.'%');
            })
            // `withCount` en vez de contar por fila desde el Resource: era una
            // query por servicio.
            ->withCount('accommodations')
            ->orderBy('name')
            ->paginate($perPage);

        return new ServiceResourceCollection($services);
    }

    /**
     * @param  Service|int|string  $service  modelo ya resuelto (admin/v1, con
     *                                       route-model binding) o id crudo (el /api/v1 legacy)
     */
    public function update($service, UpdateServiceRequest $request): ServiceResource
    {
        $service = $service instanceof Service ? $service : Service::findOrFail($service);

        // `validated()` y no `all()`: con `all()` cualquier campo presente en
        // `$fillable` entra al UPDATE aunque no lo valide ninguna regla.
        $service->update($request->validated());

        return new ServiceResource($service->fresh()->loadCount('accommodations'));
    }

    public function store(StoreServiceRequest $request): ServiceResource
    {
        $service = Service::create($request->validated());

        return new ServiceResource($service->loadCount('accommodations'));
    }

    /**
     * Baja legacy de `/api/v1/services`: el id viaja en el cuerpo. Se conserva
     * porque la ruta sigue publicada; el camino nuevo es `remove()`.
     */
    public function destroy(DestroyServiceRequest $request): ServiceResource
    {
        $service = Service::find($request->service_id);
        $service->delete();

        return new ServiceResource($service);
    }

    /**
     * Baja de `admin/v1`, con el id en la ruta.
     *
     * `accommodation_services` no tiene FK con `ON DELETE CASCADE`, así que
     * borrar un servicio en uso deja filas huérfanas en el pivote apuntando a un
     * `service_id` inexistente. No falla en el momento: reaparece más tarde como
     * servicios fantasma en la ficha de un alojamiento. Por eso, 409.
     */
    public function remove(Service $service): void
    {
        abort_if(
            $service->accommodations()->exists(),
            409,
            'El servicio está asociado a alojamientos. Desasocialo antes de eliminarlo.'
        );

        $service->delete();
    }
}
