<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\StoreAccommodationApiKeyRequest;
use App\Http\Resources\Admin\AccommodationApiKeyResource;
use App\Models\Accommodation;
use App\Models\AccommodationApiKey;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
// La de Illuminate, no `Carbon\Carbon`: `generateFor()` tipa el parámetro como
// `?Illuminate\Support\Carbon`.
use Illuminate\Support\Carbon;

/**
 * API keys del alojamiento para el asistente (docs/accommodation-mcp-plan.md,
 * fase 1). Lado dueño (account/platform): listar, generar (muestra el secreto UNA
 * vez) y revocar. Todo exige `update`: aunque no exponga el secreto, la lista
 * dice qué agentes hablan por el hotel. Los clients no llegan: el grupo está bajo
 * `client.readonly`.
 */
class AccommodationApiKeyController extends BaseController
{
    use AuthorizesRequests;

    public function index(Accommodation $accommodation): JsonResponse
    {
        $this->authorize('update', $accommodation);

        $keys = AccommodationApiKey::query()
            ->where('accommodation_id', $accommodation->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => AccommodationApiKeyResource::collection($keys),
            'meta' => ['ai_assistant' => $accommodation->hasFeature(AccommodationApiKey::FEATURE)],
        ]);
    }

    public function store(StoreAccommodationApiKeyRequest $request, Accommodation $accommodation): JsonResponse
    {
        $this->authorize('update', $accommodation);

        // Una key sin la feature no serviría: el middleware la rechaza igual.
        if (! $accommodation->hasFeature(AccommodationApiKey::FEATURE)) {
            return response()->json([
                'message' => 'El plan del alojamiento no incluye el asistente.',
            ], 403);
        }

        // Un alojamiento suelto (borrador del padrón) no tiene dueño a quien atar la key.
        if ($accommodation->account_id === null) {
            return response()->json([
                'message' => 'El alojamiento no tiene una cuenta asociada.',
            ], 422);
        }

        $data = $request->validated();

        $result = AccommodationApiKey::generateFor(
            $accommodation,
            $data['name'] ?? null,
            $data['abilities'] ?? AccommodationApiKey::ABILITIES,
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
        );

        return response()->json([
            // El secreto en claro se muestra UNA sola vez; después sólo vive el hash.
            'secret' => $result['plain'],
            // La misma credencial partida, para los clientes MCP que piden un
            // par client_id / client_secret (Colmexa los une con un punto).
            'client_id' => $result['model']->prefix,
            'client_secret' => substr($result['plain'], strlen($result['model']->prefix) + 1),
            'warning' => 'Guardá este secreto ahora: no se puede volver a ver.',
            'key' => new AccommodationApiKeyResource($result['model']),
        ], 201);
    }

    public function destroy(Accommodation $accommodation, AccommodationApiKey $key): JsonResponse
    {
        $this->authorize('update', $accommodation);

        // Lo ajeno es 404: no se confirma que la key exista.
        abort_if((int) $key->accommodation_id !== (int) $accommodation->id, 404);

        if (! $key->isRevoked()) {
            $key->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json([
            'message' => 'Key revocada.',
            'key' => new AccommodationApiKeyResource($key->fresh()),
        ]);
    }
}
