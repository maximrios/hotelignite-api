<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Repositories\Contracts\UserInvitationInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Reenvío de la invitación de acceso desde el CRM. Revoca la anterior y emite
 * una nueva con vencimiento renovado. Sirve también para invitar a un user que
 * se dio de alta con contraseña manual: al aceptar, la elige él.
 */
class UserInvitationController extends BaseController
{
    use AuthorizesRequests;

    public function __construct(private UserInvitationInterface $invitations) {}

    public function store(Request $request, User $user)
    {
        $this->authorize('update', $user);

        abort_if(
            $user->latestInvitation?->accepted_at !== null,
            409,
            'Este usuario ya activó su acceso.'
        );

        $this->invitations->issue($user, $request->user());

        return new UserResource($user->fresh()->load(['accommodations', 'latestInvitation']));
    }
}
