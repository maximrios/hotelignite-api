<?php

namespace App\Support;

use App\Models\Accommodation;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Vencimiento de una solicitud del motor. Con horario de atención cargado, el
 * reloj sólo corre dentro de esa ventana: lo que entra de madrugada vence a la
 * mañana. Nunca pasa del fin del día de llegada.
 */
class BookingDeadline
{
    public const MIN_MINUTES = 30;

    public const MAX_MINUTES = 720;

    public static function for(Accommodation $accommodation, CarbonInterface $checkin, ?CarbonInterface $now = null): Carbon
    {
        $timezone = (string) config('app.destination_timezone');
        $start = Carbon::parse($now ?? now())->setTimezone($timezone);
        $minutes = max(self::MIN_MINUTES, min(self::MAX_MINUTES, (int) $accommodation->booking_request_minutes));

        $limit = Carbon::parse($checkin->toDateString(), $timezone)->endOfDay();
        $deadline = self::afterAttention($start, $minutes, $accommodation->booking_attention_from, $accommodation->booking_attention_to);

        return ($start->lt($limit) && $deadline->gt($limit) ? $limit : $deadline)->setTimezone('UTC');
    }

    private static function afterAttention(Carbon $start, int $minutes, ?string $from, ?string $to): Carbon
    {
        if ($from === null || $to === null || $from >= $to) {
            return $start->copy()->addMinutes($minutes);
        }

        $cursor = $start->copy();

        while (true) {
            $open = $cursor->copy()->setTimeFromTimeString($from);
            $close = $cursor->copy()->setTimeFromTimeString($to);

            if ($cursor->lt($open)) {
                $cursor = $open;
            }

            if ($cursor->lt($close)) {
                $available = (int) ceil($cursor->diffInMinutes($close));

                if ($minutes <= $available) {
                    return $cursor->addMinutes($minutes);
                }

                $minutes -= $available;
            }

            $cursor = $open->addDay();
        }
    }
}
