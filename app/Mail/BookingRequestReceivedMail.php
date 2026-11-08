<?php

namespace App\Mail;

use App\Models\Reservation;
use App\Support\BookingMailData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Aviso al hotel, con la marca de HotelIgnite: entró una solicitud por el motor o venció una. */
class BookingRequestReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public Reservation $reservation, public bool $expired = false) {}

    public function build(): self
    {
        $data = BookingMailData::for($this->reservation);
        $guest = $this->reservation->guest;
        $code = $this->reservation->confirmation_number;
        $attribution = array_filter((array) ($this->reservation->attribution ?? []));

        // Responder le escribe directo al huésped.
        if ($guest !== null && filter_var($guest->email, FILTER_VALIDATE_EMAIL)) {
            $this->replyTo($guest->email, $data['guestName']);
        }

        $data += [
            'expired' => $this->expired,
            'hotel' => (string) $this->reservation->accommodation->name,
            'guestEmail' => $guest?->email,
            'guestPhone' => $guest?->phone,
            'origin' => $attribution === [] ? null : implode(' · ', $attribution),
            'url' => config('api.pms_url').'/calendario?'.http_build_query([
                'date' => $this->reservation->checkin_date->toDateString(),
                'reservation' => $this->reservation->id,
            ]),
            'logoUrl' => rtrim((string) config('app.url'), '/').'/brand/mail-mark.png',
        ];

        return $this->subject($this->expired ? "Venció la solicitud {$code}" : "Nueva solicitud de reserva {$code} · {$data['guestName']}")
            ->view('emails.booking-request-received', $data)
            ->text('emails.booking-request-received-text', $data);
    }
}
