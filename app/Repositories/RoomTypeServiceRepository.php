<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\RoomType;
use App\Models\RoomTypeService;
use App\Http\Requests\StoreRoomTypeServiceRequest;
use App\Http\Resources\V1\RoomTypeServiceResource;
use App\Http\Requests\DestroyRoomTypeServiceRequest;
use App\Http\Requests\UpdateRoomTypeServiceRequest;
use App\Repositories\Contracts\RoomTypeServiceInterface;
use App\Http\Resources\V1\RoomTypeServiceResourceCollection;
use App\Http\Resources\V1\ServiceResourceCollection;

class RoomTypeServiceRepository implements RoomTypeServiceInterface
{
    public function all(Request $request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $roomTypeServices = RoomTypeService::when($request->room_type_id, function ($q, $room_type_id) {
                                return $q->where('room_type_id', $room_type_id);
                            })
                            ->when($request->service_id, function ($q, $service_id) {
                                return $q->where('service_id', $service_id);
                            })
                            ->offset($offset)
                            ->limit($limit)
                            ->get();

        return new RoomTypeServiceResourceCollection($roomTypeServices);
    }

    public function find($id)
    {
        $roomTypeService = RoomTypeService::with(['roomType', 'service'])->find($id);
        return new RoomTypeServiceResource($roomTypeService);
    }

    public function search($request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $roomTypeServices = RoomTypeService::when($request->room_type_id, function ($q, $room_type_id) {
                                return $q->where('room_type_id', $room_type_id);
                            })
                            ->when($request->service_id, function ($q, $service_id) {
                                return $q->where('service_id', $service_id);
                            })
                            ->offset($offset)
                            ->limit($limit)
                            ->paginate();

        return new RoomTypeServiceResourceCollection($roomTypeServices);
    }

    public function update($id, UpdateRoomTypeServiceRequest $request): ServiceResourceCollection
    {
        $roomType = RoomType::find($id);
        
        if (!$roomType) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Room type not found');
        }

        // Sincronizar servicios: elimina los que no están en el array y agrega los nuevos
        $roomType->services()->sync($request->service_ids);
        
        // Recargar la relación para obtener los servicios actualizados
        $roomType->load('services');
        
        // Retornar los servicios sincronizados usando ServiceResourceCollection
        return new ServiceResourceCollection($roomType->services);
    }

    public function store(StoreRoomTypeServiceRequest $request): ServiceResourceCollection
    {
        $roomType = RoomType::find($request->room_type_id);
        
        if (!$roomType) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Room type not found');
        }

        // Agregar servicios sin eliminar los existentes (attach sin detach)
        // Usamos syncWithoutDetaching para evitar duplicados
        $roomType->services()->syncWithoutDetaching($request->service_ids);
        
        // Recargar la relación para obtener los servicios actualizados
        $roomType->load('services');
        
        // Retornar todos los servicios del room type usando ServiceResourceCollection
        return new ServiceResourceCollection($roomType->services);
    }

    public function destroy(DestroyRoomTypeServiceRequest $request): RoomTypeServiceResource
    {
        $roomTypeService = RoomTypeService::find($request->room_type_service_id);
        $roomTypeService->delete();
        return new RoomTypeServiceResource($roomTypeService);
    }
}

