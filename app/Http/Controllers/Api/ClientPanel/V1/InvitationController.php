<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Http\Requests\ClientPanel\StoreInvitationRequest;
use App\Models\Invitation;
use App\Repositories\Contracts\InvitationInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Emisión y reenvío de invitaciones desde el panel de client (B2B). La tenencia
 * sale del token (`$request->user()->client_id`), nunca de la URL (§12).
 */
class InvitationController extends BaseController
{
    public function __construct(private InvitationInterface $invitations)
    {
    }

    public function store(StoreInvitationRequest $request): JsonResponse
    {
        $result = $this->invitations->emit(
            $request->user()->client_id,
            $request->user(),
            $request->validated()['invitations'],
        );

        return response()->json($result, 201);
    }

    public function resend(Request $request, Invitation $invitation): JsonResponse
    {
        // Un client solo puede reenviar sus propias invitaciones.
        abort_if($invitation->client_id !== $request->user()->client_id, 403, 'No autorizado.');

        $this->invitations->resend($invitation, $request->user());

        return response()->json(['message' => 'Invitación reenviada.']);
    }
}
