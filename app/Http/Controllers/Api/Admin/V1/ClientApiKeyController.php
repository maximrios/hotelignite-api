<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\StoreClientApiKeyRequest;
use App\Http\Resources\Admin\ClientApiKeyResource;
use App\Models\Client;
use App\Models\ClientApiKey;
use Illuminate\Routing\Controller as BaseController;
// La de Illuminate, no `Carbon\Carbon`: `generateFor()` tipa el parámetro como
// `?Illuminate\Support\Carbon` y la clase base no satisface ese type hint.
use Illuminate\Support\Carbon;

/**
 * Gestión de API keys de un client (solo plataforma). Generar (muestra el
 * secreto UNA vez) y revocar. Ver docs/api-clients-plan.md (F4).
 */
class ClientApiKeyController extends BaseController
{
    public function store(StoreClientApiKeyRequest $request, Client $client)
    {
        $data = $request->validated();

        $result = ClientApiKey::generateFor(
            $client,
            $data['name'] ?? null,
            $data['abilities'] ?? ['catalog:read'],
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
        );

        return response()->json([
            // El secreto en claro se muestra UNA sola vez; después sólo vive el hash.
            'secret' => $result['plain'],
            'warning' => 'Guardá este secreto ahora: no se puede volver a ver.',
            'key' => new ClientApiKeyResource($result['model']),
        ], 201);
    }

    public function destroy(Client $client, ClientApiKey $key)
    {
        // Asegura que la key pertenezca a este client.
        if ($key->client_id !== $client->id) {
            return response()->json(['message' => 'La key no pertenece a este client.'], 404);
        }

        if (! $key->isRevoked()) {
            $key->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json([
            'message' => 'Key revocada.',
            'key' => new ClientApiKeyResource($key->fresh()),
        ]);
    }
}
