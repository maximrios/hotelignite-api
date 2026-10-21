<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * El client del usuario logueado: quién es, con qué plan y si todavía espera la
 * verificación del staff. Lo usa el portal para el aviso de verificación.
 */
class ProfileController extends BaseController
{
    public function show(Request $request): JsonResponse
    {
        $client = $request->user()->client()->with('plan')->firstOrFail();

        return response()->json([
            'id' => $client->id,
            'name' => $client->name,
            'type' => $client->type,
            'origin' => $client->origin,
            'needs_verification' => $client->needsVerification(),
            'verified_at' => $client->verified_at,
            'plan' => $client->plan ? [
                'slug' => $client->plan->slug,
                'name' => $client->plan->name,
                'free' => $client->plan->isFree(),
            ] : null,
        ]);
    }
}
