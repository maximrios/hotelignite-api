<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Http\Requests\ClientPanel\InviteAssociatesRequest;
use App\Models\Client;
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
    public function __construct(private InvitationInterface $invitations) {}

    /**
     * Una agencia que se registró sola no invita hasta que el staff la verifica:
     * el mail sale con nuestro dominio y a nombre de alguien que nadie revisó.
     */
    public static function assertCanInvite(?Client $client): void
    {
        abort_if(
            $client?->needsVerification() ?? false,
            403,
            'Tu agencia todavía no está verificada. Vas a poder invitar alojamientos cuando revisemos tus datos.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->invitations->associates($request->user()->client_id, [
                'state' => $request->string('state')->toString() ?: null,
                'search' => $request->string('search')->trim()->toString() ?: null,
                'city_id' => $request->integer('city_id') ?: null,
                'page' => $request->integer('page', 1),
                'per_page' => $request->integer('per_page', 50),
            ])
        );
    }

    /**
     * Invita a alojamientos de su padrón: a tomar la ficha si son borradores, a
     * aceptar el vínculo si ya tienen dueño. Mismo mail en los dos casos.
     */
    public function invite(InviteAssociatesRequest $request): JsonResponse
    {
        self::assertCanInvite($request->user()->client);
        $data = $request->validated();

        $result = $this->invitations->emitForAccommodations(
            $request->user()->client_id,
            $request->user(),
            $data['accommodation_ids'],
            $data['email'] ?? null,
        );

        return response()->json($result, 201);
    }
}
