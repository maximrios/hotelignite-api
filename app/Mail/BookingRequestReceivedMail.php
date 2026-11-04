<?php

namespace App\Mail;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Aviso al hotel: entró una solicitud por el motor y espera su confirmación. */
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
        $this->reservation->loadMissing(['guest', 'accommodation']);

        $code = $this->reservation->confirmation_number;

        return $this->subject($this->expired ? "Venció la solicitud {$code}" : "Nueva solicitud de reserva {$code}")
            ->markdown('emails.booking-request-received', [
                'reservation' => $this->reservation,
                'rooms' => $this->reservation->bookingGroup(),
                'deadline' => $this->reservation->request_expires_at
                    ?->setTimezone(config('app.destination_timezone'))->format('d/m/Y H:i'),
                'expired' => $this->expired,
                'hotel' => (string) $this->reservation->accommodation->name,
                'url' => config('api.pms_url').'/reservas',
            ]);
    }
}
