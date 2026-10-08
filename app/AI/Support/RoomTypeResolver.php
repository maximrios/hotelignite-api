<?php

namespace App\AI\Support;

use App\AI\Support\Mock\MockInventory;
use App\Models\Accommodation;
use App\Models\RoomType;

/**
 * Tipos de habitación de un alojamiento con su capacidad, completando con mocks
 * lo que falta (M3: capacidad vacía; M4: alojamiento sin room types).
 *
 * `id` es null en los room types simulados: no tienen inventario ni tarifas
 * reales, así que todo lo que se calcule sobre ellos también es mock.
 */
final class RoomTypeResolver
{
    /**
     * @return list<array{id: ?int, name: string, max_occupancy: ?int, source: 'real'|'mock'|'unknown'}>
     */
    public function forAccommodation(Accommodation $accommodation, string $language): array
    {
        $roomTypes = $accommodation->relationLoaded('roomTypes')
            ? $accommodation->roomTypes
            : $accommodation->roomTypes()->with('descriptions')->get();

        if ($roomTypes->isEmpty()) {
            if (! MockInventory::enabled()) {
                return [];
            }

            return array_map(fn (array $room) => [
                'id' => null,
                'name' => $room['name'],
                'max_occupancy' => $room['max_occupancy'],
                'source' => 'mock',
            ], MockInventory::roomTypes($accommodation));
        }

        return $roomTypes->map(function (RoomType $roomType) use ($language) {
            $occupancy = (int) $roomType->max_occupancy;
            [$maxOccupancy, $source] = match (true) {
                $occupancy > 0 => [$occupancy, 'real'],
                MockInventory::enabled() => [MockInventory::DEFAULT_OCCUPANCY, 'mock'],
                default => [null, 'unknown'],
            };

            $translation = $roomType->descriptions->firstWhere('language_id', $language)
                ?? $roomType->descriptions->first();

            return [
                'id' => (int) $roomType->id,
                'name' => (string) ($translation?->name ?: 'Habitación'),
                'max_occupancy' => $maxOccupancy,
                'source' => $source,
            ];
        })->values()->all();
    }
}
