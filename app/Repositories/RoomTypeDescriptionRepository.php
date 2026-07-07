<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\RoomType;
use App\Models\RoomTypeDescription;
use App\Http\Requests\StoreRoomTypeDescriptionRequest;
use App\Http\Resources\V1\RoomTypeDescriptionResource;
use App\Http\Requests\DestroyRoomTypeDescriptionRequest;
use App\Http\Requests\UpdateRoomTypeDescriptionRequest;
use App\Repositories\Contracts\RoomTypeDescriptionInterface;
use App\Http\Resources\V1\RoomTypeDescriptionResourceCollection;

class RoomTypeDescriptionRepository implements RoomTypeDescriptionInterface
{
    public function all(Request $request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $roomTypeDescriptions = RoomTypeDescription::when($request->room_type_id, function ($q, $room_type_id) {
                                return $q->where('room_type_id', $room_type_id);
                            })
                            ->when($request->language_id, function ($q, $language_id) {
                                return $q->where('language_id', $language_id);
                            })
                            ->offset($offset)
                            ->limit($limit)
                            ->get();

        return new RoomTypeDescriptionResourceCollection($roomTypeDescriptions);
    }

    public function find($id)
    {
        $roomTypeDescription = RoomTypeDescription::with('roomType')->find($id);
        return new RoomTypeDescriptionResource($roomTypeDescription);
    }

    public function search($request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $roomTypeDescriptions = RoomTypeDescription::when($request->room_type_id, function ($q, $room_type_id) {
                                return $q->where('room_type_id', $room_type_id);
                            })
                            ->when($request->language_id, function ($q, $language_id) {
                                return $q->where('language_id', $language_id);
                            })
                            ->offset($offset)
                            ->limit($limit)
                            ->paginate();

        return new RoomTypeDescriptionResourceCollection($roomTypeDescriptions);
    }

    public function update($id, UpdateRoomTypeDescriptionRequest $request): RoomTypeDescriptionResourceCollection
    {
        $roomType = RoomType::find($id);
        
        if (!$roomType) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Room type not found');
        }

        // Procesar cada descripción del array
        $updatedDescriptions = [];
        foreach ($request->descriptions as $descriptionData) {
            // Buscar si ya existe una descripción con el mismo room_type_id y language_id
            $roomTypeDescription = RoomTypeDescription::where('room_type_id', $id)
                ->where('language_id', $descriptionData['language_id'])
                ->first();

            if ($roomTypeDescription) {
                // Actualizar la descripción si existe
                $roomTypeDescription->update([
                    'name' => $descriptionData['name'],
                    'description' => $descriptionData['description']
                ]);
                $updatedDescriptions[] = $roomTypeDescription;
            } else {
                // Crear nueva descripción si no existe
                $newDescription = RoomTypeDescription::create([
                    'room_type_id' => $id,
                    'language_id' => $descriptionData['language_id'],
                    'name' => $descriptionData['name'],
                    'description' => $descriptionData['description'],
                ]);
                $updatedDescriptions[] = $newDescription;
            }
        }

        // Retornar las descripciones actualizadas
        return new RoomTypeDescriptionResourceCollection(collect($updatedDescriptions));
    }

    public function store(StoreRoomTypeDescriptionRequest $request): RoomTypeDescriptionResourceCollection
    {
        $roomType = RoomType::find($request->room_type_id);
        
        if (!$roomType) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Room type not found');
        }

        // Crear las descripciones
        $createdDescriptions = [];
        foreach ($request->descriptions as $descriptionData) {
            // Verificar si ya existe para evitar duplicados
            $existingDescription = RoomTypeDescription::where('room_type_id', $request->room_type_id)
                ->where('language_id', $descriptionData['language_id'])
                ->first();

            if (!$existingDescription) {
                $newDescription = RoomTypeDescription::create([
                    'room_type_id' => $request->room_type_id,
                    'language_id' => $descriptionData['language_id'],
                    'name' => $descriptionData['name'],
                    'description' => $descriptionData['description'],
                ]);
                $createdDescriptions[] = $newDescription;
            } else {
                // Si existe, actualizar la descripción
                $existingDescription->update([
                    'name' => $descriptionData['name'],
                    'description' => $descriptionData['description']
                ]);
                $createdDescriptions[] = $existingDescription;
            }
        }

        // Retornar las descripciones creadas/actualizadas
        return new RoomTypeDescriptionResourceCollection(collect($createdDescriptions));
    }

    public function destroy(DestroyRoomTypeDescriptionRequest $request): RoomTypeDescriptionResource
    {
        $roomTypeDescription = RoomTypeDescription::find($request->room_type_description_id);
        $roomTypeDescription->delete();
        return new RoomTypeDescriptionResource($roomTypeDescription);
    }
}

