<?php

namespace App\Support;

use App\Models\Extra;
use App\Models\Reservation;
use App\Models\ReservationExtra;
use Carbon\CarbonInterface;

/** Lo que comparten los mails del motor: el grupo de habitaciones y las fechas, ya formateados. */
class BookingMailData
{
    /** @return array<string, mixed> */
    public static function for(Reservation $reservation): array
    {
        $reservation->loadMissing(['guest', 'accommodation']);
        $group = $reservation->bookingGroup()->load(['roomType.descriptions', 'extras']);
        $currency = (string) $reservation->currency;
        $extras = $group->flatMap(fn (Reservation $room) => $room->extras);

        return [
            'code' => $reservation->confirmation_number,
            'guestName' => trim((string) $reservation->guest?->full_name),
            'guestFirstName' => trim((string) $reservation->guest?->first_name),
            'checkin' => self::longDate($reservation->checkin_date),
            'checkout' => self::longDate($reservation->checkout_date),
            'nights' => (int) $reservation->checkin_date->diffInDays($reservation->checkout_date),
            'rooms' => $group->values()->map(fn (Reservation $room, int $i) => [
                'label' => 'Habitación '.($i + 1).' · '.($room->roomType?->spanishName() ?? 'Habitación'),
                'guests' => self::guests((int) $room->adults, (int) $room->children),
                'total' => self::money((int) $room->total_amount, $currency),
                'code' => $room->confirmation_number,
            ])->all(),
            'extras' => $extras->map(fn (ReservationExtra $line) => [
                'label' => $line->name.($line->quantity > 1 ? ' × '.$line->quantity : ''),
                'detail' => self::chargeMode($line->charge_mode),
                'total' => $line->total_amount > 0 ? self::money((int) $line->total_amount, $currency) : 'Sin cargo',
            ])->values()->all(),
            'total' => self::money((int) $group->sum('total_amount') + (int) $extras->sum('total_amount'), $currency),
            'deadline' => $reservation->request_expires_at
                ?->setTimezone(config('app.destination_timezone'))->locale('es')->isoFormat('dddd D [de] MMMM, HH:mm'),
            // Viene armado por BookingRequestDetails: una línea por dato.
            'notes' => $reservation->special_requests ? preg_split('/\R/', (string) $reservation->special_requests) : [],
        ];
    }

    public static function longDate(CarbonInterface $date): string
    {
        return $date->copy()->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
    }

    public static function money(int $cents, string $currency): string
    {
        $prefix = $currency === 'ARS' ? '$' : $currency;

        // Espacio duro: que "$" no quede sola al final de una línea.
        return $prefix."\u{00A0}".number_format($cents / 100, 0, ',', '.');
    }

    public static function chargeMode(string $mode): string
    {
        return match ($mode) {
            Extra::MODE_NIGHT => 'por noche',
            Extra::MODE_PERSON => 'por persona',
            Extra::MODE_PERSON_NIGHT => 'por persona y noche',
            default => 'por estadía',
        };
    }

    private static function guests(int $adults, int $children): string
    {
        $text = $adults.' '.($adults === 1 ? 'adulto' : 'adultos');

        return $children > 0 ? $text.' · '.$children.' '.($children === 1 ? 'menor' : 'menores') : $text;
    }
}
