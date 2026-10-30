<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Olvidé mi contraseña": link a `{app}/restablecer/{token}`. Mismo diseño que
 * UserWelcomeMail (tablas con estilos inline). Ver PasswordResetController.
 */
class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(
        public User $user,
        public string $url,
        public string $appUrl,
    ) {}

    public function build(): self
    {
        $firstName = strtok(trim($this->user->name), ' ') ?: $this->user->name;
        $support = (string) config('api.support_email');

        if ($support !== '') {
            $this->replyTo($support, 'HotelIgnite');
        }

        $data = [
            'firstName' => $firstName,
            'email' => $this->user->email,
            'url' => $this->url,
            'appHost' => parse_url($this->appUrl, PHP_URL_HOST) ?: $this->appUrl,
            'minutes' => (int) config('auth.passwords.users.expire', 60),
            'supportEmail' => $support !== '' ? $support : null,
            'logoUrl' => rtrim((string) config('app.url'), '/').'/brand/mail-mark.png',
        ];

        return $this->subject('Elegí una contraseña nueva para HotelIgnite')
            ->view('emails.password-reset', $data)
            ->text('emails.password-reset-text', $data);
    }
}
