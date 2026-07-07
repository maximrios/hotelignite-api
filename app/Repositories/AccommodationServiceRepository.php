<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\Accommodation;
use App\Models\AccommodationService;
use App\Http\Requests\StoreAccommodationServiceRequest;
use App\Http\Resources\V1\AccommodationServiceResource;
use App\Http\Requests\DestroyAccommodationServiceRequest;
use App\Http\Requests\UpdateAccommodationServiceRequest;
use App\Repositories\Contracts\AccommodationServiceInterface;
use App\Http\Resources\V1\AccommodationServiceResourceCollection;
use App\Http\Resources\V1\ServiceResourceCollection;

class AccommodationServiceRepository implements AccommodationServiceInterface
{
    public function all(Request $request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $accommodationServices = AccommodationService::when($request->accommodation_id, function ($q, $accommodation_id) {
                                return $q->where('accommodation_id', $accommodation_id);
                            })
                            ->when($request->service_id, function ($q, $service_id) {
                                return $q->where('service_id', $service_id);
                            })
                            ->offset($offset)
                            ->limit($limit)
                            ->get();

        return new AccommodationServiceResourceCollection($accommodationServices);
    }

    public function find($id)
    {
        $accommodationService = AccommodationService::find($id);
        return new AccommodationServiceResource($accommodationService);
    }

    public function search($request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $accommodationServices = AccommodationService::when($request->accommodation_id, function ($q, $accommodation_id) {
                                return $q->where('accommodation_id', $accommodation_id);
                            })
                            ->when($request->service_id, function ($q, $service_id) {
                                return $q->where('service_id', $service_id);
                            })
                            ->offset($offset)
                            ->limit($limit)
                            ->paginate();

        return new AccommodationServiceResourceCollection($accommodationServices);
    }

    public function update($id, UpdateAccommodationServiceRequest $request): ServiceResourceCollection
    {
        $accommodation = Accommodation::find($id);
        
        if (!$accommodation) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Accommodation not found');
        }

        // Sincronizar servicios: elimina los que no están en el array y agrega los nuevos
        $accommodation->services()->sync($request->service_ids);
        
        // Recargar la relación para obtener los servicios actualizados
        $accommodation->load('services');
        
        // Retornar los servicios sincronizados usando ServiceResourceCollection
        return new ServiceResourceCollection($accommodation->services);
    }

    public function store(StoreAccommodationServiceRequest $request): ServiceResourceCollection
    {
        $accommodation = Accommodation::find($request->accommodation_id);
        
        if (!$accommodation) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Accommodation not found');
        }

        // Agregar servicios sin eliminar los existentes (attach sin detach)
        // Usamos syncWithoutDetaching para evitar duplicados
        $accommodation->services()->syncWithoutDetaching($request->service_ids);
        
        // Recargar la relación para obtener los servicios actualizados
        $accommodation->load('services');
        
        // Retornar todos los servicios del accommodation usando ServiceResourceCollection
        return new ServiceResourceCollection($accommodation->services);
    }

    public function destroy(DestroyAccommodationServiceRequest $request): AccommodationServiceResource
    {
        $accommodationService = AccommodationService::find($request->accommodation_service_id);
        $accommodationService->delete();
        return new AccommodationServiceResource($accommodationService);
    }
}

