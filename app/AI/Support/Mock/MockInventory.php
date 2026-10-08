<?php

namespace App\AI\Support\Mock;

use App\Models\Accommodation;
use Carbon\CarbonImmutable;

/**
 * Datos simulados para lo que todavía no está cargado: inventario por noche
 * (M1), precio "desde" (M2), capacidad de un room type (M3) y tipos de
 * habitación de un alojamiento sin room types (M4). Ver docs/mcp-mocks-todo.md.
 *
 * Determinístico: se deriva de `crc32` sobre el id del alojamiento (y la fecha),
 * así la misma pregunta da siempre la misma respuesta. Solo se usa donde falta
 * el dato real y si `enabled()`.
 */
final class MockInventory
{
    // Porcentaje de noches que salen disponibles (M1). Alto a propósito: se
    // aplica por noche, y con 80% una estadía de 4 noches quedaba sin lugar más
    // de la mitad de las veces (0.8^4 ≈ 0.41).
    private const OPEN_NIGHTS_PERCENT = 95;

    // Capacidad que se asume para un room type sin `max_occupancy` (M3).
    public const DEFAULT_OCCUPANCY = 2;

    // Precio base por noche en ARS para una habitación doble, por tipo (M2).
    // Las claves son `accommodation_types.id`.
    private const BASE_PRICE_BY_TYPE = [
        1 => 35000,  // Hotel 1 estrella
        2 => 45000,  // Hotel 2 estrellas
        3 => 60000,  // Hotel 3 estrellas
        4 => 90000,  // Hotel 4 estrellas
        5 => 130000, // Hotel 5 estrellas
        6 => 140000, // Hotel Boutique
        7 => 40000,  // Hostal
        8 => 70000,  // Apart hotel
        9 => 80000,  // Cabañas
        10 => 25000, // Hostel
        11 => 65000, // Alquiler temporario
    ];

    private const DEFAULT_BASE_PRICE = 60000;

    // Tipos que se alquilan como unidad completa (cabañas, alquiler temporario).
    private const WHOLE_UNIT_TYPES = [9, 11];

    // Tipos con camas en habitación compartida.
    private const SHARED_ROOM_TYPES = [10];

    public static function enabled(): bool
    {
        return (bool) config('ai.mock_missing_data') && ! app()->environment('production');
    }

    /**
     * M1 — ¿Hay lugar esa noche? Para noches sin filas en `room_availabilities`.
     */
    public static function isNightOpen(int $accommodationId, CarbonImmutable $date): bool
    {
        return self::hash("{$accommodationId}|{$date->toDateString()}") % 100 < self::OPEN_NIGHTS_PERCENT;
    }

    /**
     * M4 — Tipos de habitación simulados para un alojamiento sin room types.
     *
     * @return list<array{name: string, max_occupancy: int}>
     */
    public static function roomTypes(Accommodation $accommodation): array
    {
        $typeId = (int) $accommodation->type_id;

        if (in_array($typeId, self::WHOLE_UNIT_TYPES, true)) {
            return [
                ['name' => 'Unidad completa', 'max_occupancy' => 4 + self::hash((string) $accommodation->id) % 3],
            ];
        }

        if (in_array($typeId, self::SHARED_ROOM_TYPES, true)) {
            return [
                ['name' => 'Cama en habitación compartida', 'max_occupancy' => 1],
                ['name' => 'Habitación privada', 'max_occupancy' => 2],
            ];
        }

        return [
            ['name' => 'Habitación doble', 'max_occupancy' => 2],
            ['name' => 'Habitación triple', 'max_occupancy' => 3],
            ['name' => 'Habitación familiar', 'max_occupancy' => 4],
        ];
    }

    /**
     * M2 — Precio por noche, en ARS, de la habitación más chica que alcanza para
     * `$occupancy` personas. Varía ±15% por alojamiento y +10% viernes y sábado;
     * redondeado a 500.
     */
    public static function nightlyPrice(Accommodation $accommodation, int $occupancy, CarbonImmutable $checkin): int
    {
        $base = self::BASE_PRICE_BY_TYPE[(int) $accommodation->type_id] ?? self::DEFAULT_BASE_PRICE;

        // Una doble es la referencia; cada plaza extra suma un 30%.
        $byOccupancy = $base * (1 + 0.3 * max(0, $occupancy - 2));

        $variation = 0.85 + (self::hash((string) $accommodation->id) % 31) / 100;
        $weekend = in_array($checkin->dayOfWeekIso, [5, 6], true) ? 1.1 : 1.0;

        return (int) (round($byOccupancy * $variation * $weekend / 500) * 500);
    }

    private static function hash(string $value): int
    {
        return crc32($value);
    }
}
