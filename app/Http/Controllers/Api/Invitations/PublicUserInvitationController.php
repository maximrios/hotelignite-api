<?php

namespace App\Http\Controllers\Api\Invitations;

use App\Http\Requests\UserInvitations\AcceptUserInvitationRequest;
use App\Repositories\Contracts\UserInvitationInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * Activación pública del acceso: la pantalla `/activar/{token}` del portal de
 * clients. Sin auth: el token prueba que el user recibió el email de bienvenida.
 */
class PublicUserInvitationController extends BaseController
{
    public function __construct(private UserInvitationInterface $invitations) {}

    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->resolveByToken($token);

        return response()->json($this->invitations->describe($invitation));
    }

    /**
     * Apertura del link, para la métrica. Separada del GET porque Outlook y
     * Gmail escanean los links del correo antes que el user: la página la
     * dispara desde el navegador, y los escáneres no ejecutan JS.
     */
    public function open(string $token): JsonResponse
    {
        $this->invitations->markOpened($this->invitations->resolveByToken($token));

        return response()->json(null, 204);
    }

    public function accept(AcceptUserInvitationRequest $request, string $token): JsonResponse
    {
        $invitation = $this->invitations->resolveByToken($token);

        $user = $this->invitations->accept($invitation, $request->validated()['password']);

        // Lo deja logueado, como PublicInvitationController::accept: acaba de
        // elegir la contraseña, rebotarlo al login es un paso de más. Mismas
        // abilities que UserAuthController.
        $abilities = $user->isClient() ? ['read'] : ['*'];
        $accessToken = $user->createToken('api_token', $abilities)->plainTextToken;

        return response()->json([
            'token' => $accessToken,
            'token_type' => 'Bearer',
        ]);
    }
}
