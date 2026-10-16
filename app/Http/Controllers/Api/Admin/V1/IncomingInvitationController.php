<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Repositories\Contracts\InvitationInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja del hotelero en el PMS: las solicitudes de vínculo (convenio, socio,
 * adhesión) que le mandaron clients por alojamientos que ya son suyos. Es el
 * mismo pedido que llega por mail, respondido con la sesión en vez del token.
 */
class IncomingInvitationController extends Controller
{
    public function __construct(private InvitationInterface $invitations) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->invitations->incomingFor($request->user())]);
    }

    public function accept(Request $request, Invitation $invitation): JsonResponse
    {
        $this->invitations->respondAsOwner($invitation, $request->user(), true);

        return response()->json(['message' => 'Invitación aceptada.']);
    }

    public function decline(Request $request, Invitation $invitation): JsonResponse
    {
        $this->invitations->respondAsOwner($invitation, $request->user(), false);

        return response()->json(['message' => 'Invitación rechazada.']);
    }
}
