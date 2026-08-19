<?php

namespace App\Http\Controllers\Api\Invitations;

use App\Http\Requests\Invitations\AcceptInvitationRequest;
use App\Repositories\Contracts\InvitationInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Endpoints públicos del flujo de invitación (§10, items 6-8). Sin auth: el
 * token prueba que recibiste el mail en esa casilla, nada más (§6).
 *
 * En los casos B y C hay que estar logueado como ese email — como la ruta es
 * pública, resolvemos el usuario del token de Sanctum si viene, y las guardas
 * de tenencia viven en el repositorio.
 */
class PublicInvitationController extends BaseController
{
    public function __construct(private InvitationInterface $invitations)
    {
    }

    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->resolveByToken($token);

        return response()->json($this->invitations->describe($invitation));
    }

    public function accept(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $invitation = $this->invitations->resolveByToken($token);

        $result = $this->invitations->accept($invitation, auth('sanctum')->user(), $request->validated());

        // Emitimos un token de Sanctum para el usuario resultante: así la landing
        // deja al hotelero logueado y lo lleva directo a completar su ficha, en
        // vez de rebotarlo al login. Mismas abilities que UserAuthController.
        $user = $result['user'];
        $abilities = $user->isClient() ? ['read'] : ['*'];
        $accessToken = $user->createToken('api_token', $abilities)->plainTextToken;

        return response()->json([
            'case' => $result['case'],
            'accommodation_id' => $result['accommodation_id'],
            'token' => $accessToken,
            'token_type' => 'Bearer',
        ]);
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $invitation = $this->invitations->resolveByToken($token);

        $this->invitations->decline($invitation, auth('sanctum')->user());

        return response()->json(['message' => 'Invitación rechazada.']);
    }
}
