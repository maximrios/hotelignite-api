<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mail de invitación al flujo de alta. El link lleva al PMS (§6), no al CRM.
 * Sale desde `../api` con cola y reintentos (§9). El token viaja en claro solo
 * dentro de este mail — no se persiste, solo su hash.
 *
 * Doble marca, no marca blanca: el hotelero conoce a su cámara o municipio, no
 * a HotelIgnite, así que el encabezado y el remitente son del client. Pero el
 * mail sale de nuestro dominio — mandarlo "desde" el del client lo tiraría a
 * spam por SPF/DMARC —: el remitente es "Cámara X vía HotelIgnite" y las
 * respuestas van al email del client.
 */
class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Reintentos ante fallos transitorios del proveedor (§9): un mail perdido es
     * una invitación que no llega, y la aceptación gatea todo el producto. Tras
     * agotar los 3 intentos el job cae en `failed_jobs` y se recupera con
     * `php artisan queue:retry`. El token viaja intacto en cada reintento (solo
     * se persiste su hash), así que reenviar es idempotente.
     */
    public int $tries = 3;

    /** Backoff escalonado entre reintentos, en segundos: 1 min, 5 min, 15 min. */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public Invitation $invitation,
        public string $token,
    ) {}

    public function build(): self
    {
        $base = rtrim((string) config('api.pms_invitation_url'), '/');
        $url = $base.'/'.$this->token;

        $this->invitation->loadMissing(['client', 'accommodation']);

        $client = $this->invitation->client;
        $accommodation = $this->invitation->accommodation;
        $accommodationName = $accommodation?->name ?? $this->invitation->accommodation_name;
        // Un único mail para municipio, cámara y agencia: lo que cambia es la
        // frase (`Client::relationshipVocabulary()`) y si hay que registrarse.
        $hasOwner = $accommodation?->account_id !== null;
        $vocabulary = $client?->relationshipVocabulary();

        $from = (string) config('mail.from.address');
        $this->from($from, $client ? "{$client->name} vía HotelIgnite" : 'HotelIgnite');
        if ($client?->email) {
            $this->replyTo($client->email, $client->name);
        }

        $subject = $client
            ? strtr($vocabulary['invite_subject'], [':client' => $client->name])
            : 'Te invitaron a HotelIgnite';

        $data = [
            'url' => $url,
            'brand' => $client?->brand(),
            'agreedLabel' => $vocabulary['agreed'] ?? null,
            'pitch' => $client?->invitationPitch($accommodationName),
            'contactName' => $this->invitation->contact_name,
            'accommodationName' => $accommodationName,
            'hasOwner' => $hasOwner,
            'replyTo' => $client?->email,
            'expiresOn' => $this->invitation->expires_at?->locale('es')->translatedFormat('j \d\e F'),
            'markUrl' => rtrim((string) config('app.url'), '/').'/brand/mail-mark.png',
        ];

        return $this->subject($subject)
            ->view('emails.invitation', $data)
            ->text('emails.invitation-text', $data);
    }
}
