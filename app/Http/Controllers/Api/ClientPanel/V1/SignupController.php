<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Http\Requests\ClientPanel\AgencySignupRequest;
use App\Mail\AgencySignupVerificationMail;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Registro de agencias en el portal de clients. Público.
 *
 * Crea el client (tipo agencia, origen `signup`, plan free) y su primer user.
 * Dos candados distintos, en este orden:
 *   1. Confirmar el email: sin eso no puede iniciar sesión (lo corta el login).
 *   2. La verificación del staff (CUIT y legajo): sin eso entra al portal pero
 *      no puede invitar hoteleros.
 */
class SignupController extends BaseController
{
    public function register(AgencySignupRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $client = new Client([
                'name' => $data['agency_name'],
                'type' => Client::TYPE_AGENCY,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                // "Si es distinta del nombre": vacía, la razón social es el nombre.
                'legal_name' => ($data['legal_name'] ?? null) ?: $data['agency_name'],
                'tax_id' => $data['tax_id'],
                'license_number' => $data['license_number'] ?? null,
                'active' => true,
            ]);
            $client->origin = Client::ORIGIN_SIGNUP;
            $client->save();

            return User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'user_type' => User::TYPE_CLIENT,
                'client_id' => $client->id,
            ]);
        });

        $this->sendVerification($user);

        return response()->json([
            'message' => 'Te mandamos un mail para confirmar tu email.',
            'email' => $user->email,
        ], 201);
    }

    /**
     * El portal reenvía acá los parámetros del link del mail. La firma es
     * relativa (`signed:relative`): la valida la API aunque el link lo haya
     * abierto el portal, con otro host.
     */
    public function verify(Request $request, User $user, string $hash): JsonResponse
    {
        abort_unless(hash_equals(sha1(strtolower($user->email)), $hash), 403, 'El enlace no es válido.');

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return response()->json(['message' => 'Email confirmado. Ya podés iniciar sesión.']);
    }

    /**
     * Reenvía el mail de confirmación. Responde lo mismo exista o no la cuenta:
     * no sirve para averiguar qué emails están registrados.
     */
    public function resend(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        $user = User::whereRaw('LOWER(email) = ?', [strtolower($request->string('email')->toString())])
            ->where('user_type', User::TYPE_CLIENT)
            ->whereNull('email_verified_at')
            ->first();

        if ($user?->client?->origin === Client::ORIGIN_SIGNUP) {
            $this->sendVerification($user);
        }

        return response()->json(['message' => 'Si el email está pendiente de confirmación, te mandamos el enlace de nuevo.']);
    }

    private function sendVerification(User $user): void
    {
        $path = URL::temporarySignedRoute(
            'client-panel.signup.verify',
            now()->addHours((int) config('api.signup_verification_hours', 48)),
            ['user' => $user->id, 'hash' => sha1(strtolower($user->email))],
            absolute: false,
        );

        // `/api/client-panel/v1/signup/verify/{user}/{hash}?expires=…&signature=…`
        // → `{portal}/verificar?user=…&hash=…&expires=…&signature=…`
        $parts = parse_url($path);
        parse_str($parts['query'] ?? '', $query);

        $url = config('api.clients_portal_url').'/verificar?'.http_build_query([
            'user' => $user->id,
            'hash' => sha1(strtolower($user->email)),
        ] + $query);

        Mail::to($user->email)->queue(new AgencySignupVerificationMail($user, $url));
    }
}
