<?php

namespace App\Mail;

use App\Models\Reservation;
use App\Support\BookingMailData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mail al huésped según en qué quedó su solicitud. Lleva la marca del hotel: el huésped le
 * reservó al hotel, no a HotelIgnite. Sale con el nombre del hotel y las respuestas van al hotel.
 */
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
        $data = BookingMailData::for($this->reservation);
        $accommodation = $this->reservation->accommodation;
        $hotel = (string) $accommodation->name;
        $email = filter_var($accommodation->email, FILTER_VALIDATE_EMAIL) ? (string) $accommodation->email : null;

        $subject = match ($this->kind) {
            self::CONFIRMED => "Tu reserva en {$hotel} está confirmada",
            self::DECLINED => "Tu solicitud en {$hotel} no pudo confirmarse",
            self::EXPIRED => "Tu solicitud en {$hotel} venció",
            default => "Recibimos tu solicitud en {$hotel}",
        };

        // Nuestra dirección (por entregabilidad) con el nombre del hotel; contestar le escribe al hotel.
        $this->from((string) config('mail.from.address'), $hotel);
        if ($email !== null) {
            $this->replyTo($email, $hotel);
        }

        // La preferencia de promociones es un dato para el hotel, no algo que el huésped pidió.
        $data['notes'] = array_values(array_filter($data['notes'], fn (string $line) => ! str_starts_with($line, 'Quiere recibir promociones')));

        $data += [
            'kind' => $this->kind,
            'hotel' => $hotel,
            'logo' => is_string($accommodation->logo) && str_starts_with($accommodation->logo, 'http') ? $accommodation->logo : null,
            'initial' => mb_strtoupper(mb_substr((string) preg_replace('/^Hotel\s+/i', '', $hotel), 0, 1)),
            'address' => $accommodation->address ?: null,
            'phone' => $accommodation->phone ?: null,
            'email' => $email,
            'web' => is_string($accommodation->web) && preg_match('#^https?://#', $accommodation->web) ? $accommodation->web : null,
            'subject' => $subject,
        ];

        return $this->subject($subject)
            ->view('emails.booking-guest', $data)
            ->text('emails.booking-guest-text', $data);
    }
}
