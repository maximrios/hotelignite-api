<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Http\Requests\ClientPanel\UpdateBrandingRequest;
use App\Models\Client;
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
        return response()->json($this->payload($request->user()->client()->with('plan')->firstOrFail()));
    }

    /**
     * El client cambia su logo. Una agencia sin verificar también puede: no
     * invita hasta que el staff la revisa, así que su logo no sale en ningún mail.
     */
    public function updateBranding(UpdateBrandingRequest $request): JsonResponse
    {
        $client = $request->user()->client()->with('plan')->firstOrFail();
        $data = $request->validated();
        $client->update([
            'logo_url' => $data['logo_url'],
            'logo_public_id' => $data['logo_url'] === null ? null : ($data['logo_public_id'] ?? null),
        ]);

        return response()->json($this->payload($client));
    }

    private function payload(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'type' => $client->type,
            'origin' => $client->origin,
            'needs_verification' => $client->needsVerification(),
            'verified_at' => $client->verified_at,
            // Original para el portal (puede ser SVG) y la marca derivada.
            'logo_url' => $client->logo_url,
            'brand' => $client->brand(),
            'plan' => $client->plan ? [
                'slug' => $client->plan->slug,
                'name' => $client->plan->name,
                'free' => $client->plan->isFree(),
            ] : null,
        ];
    }
}
