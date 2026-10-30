<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Repositories\Contracts\UserInvitationInterface;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * "Olvidé mi contraseña", una sola ruta para el PMS y el portal de clients: el
 * link del mail lleva a la app del tipo de user (`{app}/restablecer`).
 *
 * Usa el broker de Laravel (`password_reset_tokens`, 60 minutos, un token por
 * user y un envío por minuto). Busca el email sin distinguir mayúsculas, como
 * la landing de invitaciones. Los users `platform` no reciben link: el CRM no
 * tiene la pantalla y una cuenta de staff se recupera a mano.
 */
class PasswordResetController extends BaseController
{
    public function __construct(private UserInvitationInterface $invitations) {}

    /** Responde lo mismo exista o no la cuenta: no sirve para averiguar emails. */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $user = $this->findByEmail($request->validated()['email']);
        $url = $user ? $this->appUrlFor($user) : null;

        if ($user && $url && ! $this->broker()->getRepository()->recentlyCreatedToken($user)) {
            $token = $this->broker()->createToken($user);
            $link = $url.'/restablecer/'.$token.'?email='.rawurlencode($user->email);
            Mail::to($user->email)->send(new PasswordResetMail($user, $link, $url));
        }

        return response()->json([
            'message' => 'Si hay una cuenta con ese email, te mandamos un enlace para elegir una contraseña nueva.',
        ]);
    }

    /**
     * Guarda la contraseña nueva y lo deja logueado, como la activación. Cierra
     * las demás sesiones (el motivo más común para resetear es no confiar en la
     * vieja) y da de baja los links de bienvenida que quedaran sin usar.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $this->findByEmail($data['email']);

        if (! $user || ! $this->appUrlFor($user) || ! $this->broker()->tokenExists($user, $data['token'])) {
            throw ValidationException::withMessages([
                'token' => ['El enlace venció o ya se usó. Pedí uno nuevo.'],
            ]);
        }

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['password' => Hash::make($data['password'])]);
            // Abrir el link del mail prueba que la casilla es suya.
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()]);
            }
            $user->save();

            $user->tokens()->delete();
            $this->invitations->revokePending($user);
            $this->broker()->deleteToken($user);
        });

        $abilities = $user->isClient() ? ['read'] : ['*'];

        return response()->json([
            'token' => $user->createToken('api_token', $abilities)->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    private function findByEmail(string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();
    }

    /** Dónde aterriza el link según quién es. Null = no se le ofrece reset. */
    private function appUrlFor(User $user): ?string
    {
        return match (true) {
            $user->isAccount() => (string) config('api.pms_url'),
            $user->isClient() => (string) config('api.clients_portal_url'),
            default => null,
        };
    }

    private function broker(): PasswordBroker
    {
        /** @var PasswordBroker */
        return Password::broker();
    }
}
