<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Repositories\Contracts\InvitationInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Padrón del client: asociados con estado derivado + invitaciones pendientes.
 * Alimenta la pantalla del portal (§10, item 10). Tenencia desde el token.
 */
class AssociateController extends BaseController
{
    public function __construct(private InvitationInterface $invitations)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->invitations->associates($request->user()->client_id)
        );
    }
}
