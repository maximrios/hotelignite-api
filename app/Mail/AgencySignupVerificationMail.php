<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmación de email del registro de agencias. El link lleva al portal de
 * clients (`/verificar`), que llama a la API con la firma: la API nunca expone
 * una URL propia al usuario.
 */
class AgencySignupVerificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(
        public User $user,
        public string $url,
    ) {}

    public function build(): self
    {
        return $this->subject('Confirmá tu email para entrar a HotelIgnite')
            ->markdown('emails.signup-verification', [
                'name' => $this->user->name,
                'agencyName' => $this->user->client?->name,
                'url' => $this->url,
                'hours' => (int) config('api.signup_verification_hours', 48),
            ]);
    }
}
