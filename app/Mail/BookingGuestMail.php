<?php

namespace App\Mail;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Mail al huésped según en qué quedó su solicitud. */
class BookingGuestMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public const RECEIVED = 'received';

    public const CONFIRMED = 'confirmed';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public Reservation $reservation, public string $kind) {}

    public function build(): self
    {
        $this->reservation->loadMissing(['guest', 'accommodation']);
        $hotel = (string) $this->reservation->accommodation->name;

        $subject = match ($this->kind) {
            self::CONFIRMED => "Tu reserva en {$hotel} está confirmada",
            self::DECLINED => "Tu solicitud en {$hotel} no pudo confirmarse",
            self::EXPIRED => "Tu solicitud en {$hotel} venció",
            default => "Recibimos tu solicitud en {$hotel}",
        };

        return $this->subject($subject)->markdown('emails.booking-guest', [
            'reservation' => $this->reservation,
            'rooms' => $this->reservation->bookingGroup(),
            'deadline' => $this->reservation->request_expires_at
                ?->setTimezone(config('app.destination_timezone'))->format('d/m/Y H:i'),
            'hotel' => $hotel,
            'kind' => $this->kind,
            'contact' => $this->reservation->accommodation->phone ?: $this->reservation->accommodation->email,
        ]);
    }
}
