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
    ) {
    }

    public function build(): self
    {
        $base = rtrim((string) config('api.pms_invitation_url'), '/');
        $url = $base.'/'.$this->token;

        $this->invitation->loadMissing(['client', 'accommodation']);

        return $this->subject('Te invitaron a HotelIgnite')
            ->markdown('emails.invitation', [
                'url' => $url,
                'clientName' => $this->invitation->client?->name,
                'contactName' => $this->invitation->contact_name,
                'accommodationName' => $this->invitation->accommodation?->name ?? $this->invitation->accommodation_name,
                'expirationDays' => (int) config('api.invitation_expiration_days', 30),
            ]);
    }
}
