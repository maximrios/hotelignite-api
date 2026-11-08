<?php

namespace App\Support;

/**
 * Lo que el huésped agrega a su solicitud en el motor y no tiene columna propia: hora de
 * llegada, edades de los menores y cómo prefiere pagar (los extras son líneas propias,
 * ver ReservationExtra). Se guarda como texto en
 * `special_requests`, armado acá para que el hotel lo lea siempre igual.
 */
class BookingRequestDetails
{
    public const PAYMENT_PLANS = ['deposit' => 'seña', 'full' => 'el total'];

    public const PAYMENT_METHODS = ['card' => 'tarjeta', 'mercadopago' => 'Mercado Pago', 'transfer' => 'transferencia'];

    /** @param  array<string, mixed>  $data */
    public static function notes(array $data): ?string
    {
        $lines = [];

        if (! empty($data['arrival_time'])) {
            $lines[] = 'Llegada estimada: '.$data['arrival_time'];
        }

        if (! empty($data['children_ages'])) {
            $ages = array_map(fn ($age) => (int) $age === 0 ? 'menos de 1 año' : $age.' años', $data['children_ages']);
            $lines[] = 'Edades de los menores: '.implode(', ', $ages);
        }

        if (! empty($data['payment_plan']) || ! empty($data['payment_method'])) {
            $plan = self::PAYMENT_PLANS[$data['payment_plan'] ?? ''] ?? null;
            $method = self::PAYMENT_METHODS[$data['payment_method'] ?? ''] ?? null;
            $lines[] = 'Prefiere pagar '.trim(($plan ?? '').($method ? ' por '.$method : ''));
        }

        if (! empty($data['newsletter'])) {
            $lines[] = 'Quiere recibir promociones del hotel por email';
        }

        if (! empty($data['special_requests'])) {
            $lines[] = trim((string) $data['special_requests']);
        }

        return $lines === [] ? null : implode("\n", $lines);
    }
}
