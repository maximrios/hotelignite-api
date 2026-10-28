<?php

namespace App\Http\Resources\V1;

use App\Models\Accommodation;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'floor' => $this->floor,
            'status' => $this->status,
            'notes' => $this->notes,
            'housekeeping_status' => $this->housekeeping_status,
            'maintenance_note' => $this->maintenance_note,
            'inspected_at' => $this->inspected_at?->toIso8601String(),
            'requires_inspection' => $this->requiresInspection($request),
            'room_type_id' => $this->room_type_id,
            'accommodation_id' => $this->accommodation_id,
            'room_type' => $this->whenLoaded('roomType', function () {
                return [
                    'id' => $this->roomType->id,
                    // Nombre en español; el tipo no tiene nombre propio, vive en
                    // room_type_descriptions (ver onboarding de inventario).
                    'name' => $this->roomType->relationLoaded('descriptions')
                        ? ($this->roomType->descriptions->firstWhere('language_id', 'es') ?? $this->roomType->descriptions->first())?->name
                        : null,
                    'max_occupancy' => $this->roomType->max_occupancy,
                    'category_id' => $this->roomType->category_id,
                ];
            }),
            'accommodation' => $this->whenLoaded('accommodation', function () {
                return [
                    'id' => $this->accommodation->id,
                    'name' => $this->accommodation->name,
                ];
            }),
        ];
    }

    /** Setting del hotel, una consulta por alojamiento y request (el rack pide cientos de habitaciones). */
    private function requiresInspection($request): bool
    {
        $cache = $request->attributes->get('hk_requires_inspection', []);

        if (! array_key_exists($this->accommodation_id, $cache)) {
            $cache[$this->accommodation_id] = (bool) Accommodation::whereKey($this->accommodation_id)->value('housekeeping_requires_inspection');
            $request->attributes->set('hk_requires_inspection', $cache);
        }

        return $cache[$this->accommodation_id];
    }
}
