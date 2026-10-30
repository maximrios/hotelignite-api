<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Qué puede ser un asociado del padrón de un client. Hoy sólo alojamientos;
 * los prestadores (restaurantes, operadores) entran acá con su propio pivote
 * (ver `clients/docs/providers-plan.md`).
 *
 * El contrato hacia afuera es siempre `entity_type` + `entity_id`: lo nuevo
 * (publicación, cuotas) no nombra `accommodation_id` y no consulta el pivote
 * de alojamientos directo, sino a través de este mapa.
 */
final class AssociateType
{
    public const ACCOMMODATION = 'accommodation';

    /** @var array<string, array{pivot: string, key: string, entity: string}> */
    private const MAP = [
        self::ACCOMMODATION => [
            'pivot' => 'accommodation_client',
            'key' => 'accommodation_id',
            'entity' => 'accommodations',
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::MAP);
    }

    public static function exists(string $type): bool
    {
        return isset(self::MAP[$type]);
    }

    /** @return array{pivot: string, key: string, entity: string} */
    public static function tables(string $type): array
    {
        abort_unless(self::exists($type), 404);

        return self::MAP[$type];
    }
}
