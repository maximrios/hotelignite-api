<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Bienvenida a un user dado de alta por el staff: le da la bienvenida y lo lleva
 * a elegir su contraseña en el portal de clients (`/activar/{token}`). Con
 * `reminder` es el recordatorio de los 3 días, con el token ya rotado.
 *
 * No usa el componente markdown de Laravel: el diseño sigue la marca (ver
 * `web/`) y va en tablas con estilos inline, que es lo que respetan Gmail y
 * Outlook. El token viaja en claro sólo acá.
 */
class UserWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /** Mismo criterio que InvitationMail: sin el mail, el user no tiene cómo entrar. */
    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(
        public UserInvitation $invitation,
        public string $token,
        public bool $reminder = false,
    ) {
        // Si se emite dentro de la transacción del alta, que no salga un mail
        // de un user que terminó en rollback.
        $this->afterCommit();
    }

    public function build(): self
    {
        $this->invitation->loadMissing(['user.client', 'createdBy']);

        $user = $this->invitation->user;
        $client = $user->client;
        $portal = (string) config('api.clients_portal_url');
        $firstName = strtok(trim($user->name), ' ') ?: $user->name;
        $support = (string) config('api.support_email');

        $subject = $this->reminder
            ? "{$firstName}, tu acceso a HotelIgnite te está esperando"
            : "{$firstName}, tu acceso a HotelIgnite está listo";

        if ($support !== '') {
            $this->replyTo($support, 'HotelIgnite');
        }

        $data = [
            'reminder' => $this->reminder,
            'firstName' => $firstName,
            'email' => $user->email,
            'clientName' => $client?->name,
            'pitch' => $this->pitchFor($client),
            'url' => $portal.'/activar/'.$this->token,
            'portalHost' => parse_url($portal, PHP_URL_HOST) ?: $portal,
            'expiresOn' => $this->formatDate($this->invitation->expires_at),
            'createdByName' => $this->invitation->createdBy?->isPlatform() ? $this->invitation->createdBy->name : null,
            'supportEmail' => $support !== '' ? $support : null,
            'logoUrl' => rtrim((string) config('app.url'), '/').'/brand/mail-mark.png',
        ];

        return $this->subject($subject)
            ->view('emails.user-welcome', $data)
            ->text('emails.user-welcome-text', $data);
    }

    /** Qué va a poder hacer en el panel, en el idioma de cada tipo de client. */
    private function pitchFor(?Client $client): string
    {
        return match ($client?->type) {
            Client::TYPE_GOVERNMENT => 'Desde ahí vas a ver el padrón de alojamientos de tu destino, invitar a los que faltan y seguir el estado de cada adhesión.',
            Client::TYPE_CHAMBER => 'Desde ahí vas a ver los alojamientos de la cámara, invitar a nuevos socios y seguir el estado de cada uno.',
            default => 'Desde ahí vas a ver los alojamientos con los que trabajan, su disponibilidad y el estado de cada convenio, todo en un mismo lugar.',
        };
    }

    /** "5 de noviembre", en la hora del destino (la app guarda en UTC). */
    private function formatDate(Carbon $date): string
    {
        return $date->copy()
            ->setTimezone((string) config('app.destination_timezone', 'America/Argentina/Salta'))
            ->locale('es')
            ->translatedFormat('j \d\e F');
    }
}
