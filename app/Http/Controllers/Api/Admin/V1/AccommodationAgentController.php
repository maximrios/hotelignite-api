<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Agents\AgentCatalog;
use App\Agents\AgentDefinition;
use App\Agents\AgentProvisioner;
use App\Agents\AgentReadiness;
use App\Http\Requests\Admin\UpdateAccommodationAgentRequest;
use App\Models\Accommodation;
use App\Models\AccommodationAgent;
use App\Services\Colmexa\ColmexaException;
use App\Services\Colmexa\ColmexaPartner;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * La página "Agentes IA" del PMS: el catálogo con el estado de cada agente en
 * este alojamiento, qué le falta a la ficha, y activar / apagar. El motor es
 * Colmexa (docs/ai-agents-plan.md). Todo exige `update` sobre el alojamiento:
 * activar un agente emite una credencial que habla en nombre del hotel.
 */
class AccommodationAgentController extends BaseController
{
    use AuthorizesRequests;

    public function index(Accommodation $accommodation, ColmexaPartner $colmexa): JsonResponse
    {
        $this->authorize('update', $accommodation);

        $records = AccommodationAgent::query()
            ->where('accommodation_id', $accommodation->id)
            ->get()
            ->keyBy('agent');

        return response()->json([
            'data' => array_values(array_map(
                fn (AgentDefinition $definition) => $this->payload($accommodation, $definition, $records->get($definition->slug)),
                AgentCatalog::all(),
            )),
            'meta' => [
                'readiness' => AgentReadiness::for($accommodation),
                'service_available' => $colmexa->isConfigured(),
            ],
        ]);
    }

    /**
     * Activa o vuelve a sincronizar (cambio de saludo o de sitios del widget).
     */
    public function update(UpdateAccommodationAgentRequest $request, Accommodation $accommodation, string $agent, AgentProvisioner $provisioner): JsonResponse
    {
        $this->authorize('update', $accommodation);

        $definition = AgentCatalog::find($agent) ?? abort(404);

        if (! $definition->isAvailable()) {
            return response()->json(['message' => "{$definition->name} todavía no está disponible."], 422);
        }

        if (! $accommodation->hasFeature($definition->feature)) {
            return response()->json([
                'message' => "Tu plan no incluye {$definition->name}. Está disponible desde el plan {$definition->plan}.",
            ], 403);
        }

        // La key va atada a la cuenta: un borrador del padrón no tiene dueño.
        if ($accommodation->account_id === null) {
            return response()->json(['message' => 'El alojamiento no tiene una cuenta asociada.'], 422);
        }

        /** @var array{greeting?: string|null, allowed_origins?: list<string>|null} $settings */
        $settings = $request->validated();

        try {
            $record = $provisioner->activate($accommodation, $definition, $settings, $request->user());
        } catch (ColmexaException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['data' => $this->payload($accommodation, $definition, $record)]);
    }

    public function destroy(Accommodation $accommodation, string $agent, AgentProvisioner $provisioner): JsonResponse
    {
        $this->authorize('update', $accommodation);

        $definition = AgentCatalog::find($agent) ?? abort(404);
        $record = AccommodationAgent::query()
            ->where('accommodation_id', $accommodation->id)
            ->where('agent', $definition->slug)
            ->first() ?? abort(404);

        try {
            $record = $provisioner->deactivate($record);
        } catch (ColmexaException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['data' => $this->payload($accommodation, $definition, $record)]);
    }

    /**
     * "Abrir bandeja": un enlace de un solo uso a inbox.hotelignite.com para el
     * usuario logueado. La bandeja es una por alojamiento (el tenant de
     * Colmexa), no por agente; hace falta al menos un agente activo.
     */
    public function inboxLink(Request $request, Accommodation $accommodation, ColmexaPartner $colmexa): JsonResponse
    {
        $this->authorize('update', $accommodation);

        $active = AccommodationAgent::query()
            ->where('accommodation_id', $accommodation->id)
            ->where('status', AccommodationAgent::STATUS_ACTIVE)
            ->first();

        if ($active === null) {
            return response()->json(['message' => 'Activá un agente para tener la bandeja.'], 422);
        }

        $user = $request->user();

        try {
            $url = $colmexa->loginLink($accommodation->id, (string) $user?->email, '/conversaciones');
        } catch (ColmexaException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        if ($url === null) {
            $owner = $active->settings['inbox_owner'] ?? null;

            return response()->json([
                'message' => is_string($owner) && $owner !== $user?->email
                    ? "Tu usuario todavía no tiene acceso a la bandeja. Pedíselo a {$owner}: lo suma desde Equipo, en la bandeja."
                    : 'Tu usuario todavía no tiene acceso a la bandeja. Revisá el mail de invitación.',
            ], 403);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Accommodation $accommodation, AgentDefinition $definition, ?AccommodationAgent $record): array
    {
        return [
            'slug' => $definition->slug,
            'name' => $definition->name,
            'description' => $definition->description,
            'audience' => $definition->audience,
            'plan' => $definition->plan,
            'available' => $definition->isAvailable(),
            'included_in_plan' => $accommodation->hasFeature($definition->feature),
            'status' => $record?->status ?? AccommodationAgent::STATUS_INACTIVE,
            'settings' => [
                'greeting' => $record?->settings['greeting'] ?? null,
                'allowed_origins' => $record?->settings['allowed_origins'] ?? [],
            ],
            'widget' => $record?->isActive() ? $record->widget : null,
            // Quién recibió la invitación a la bandeja (inbox.hotelignite.com).
            'inbox_owner' => $record?->settings['inbox_owner'] ?? null,
            'last_error' => $record?->last_error,
            'last_synced_at' => $record?->last_synced_at?->toIso8601String(),
            'activated_at' => $record?->activated_at?->toIso8601String(),
        ];
    }
}
