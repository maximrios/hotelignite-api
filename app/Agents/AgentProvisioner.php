<?php

namespace App\Agents;

use App\Models\Accommodation;
use App\Models\AccommodationAgent;
use App\Models\AccommodationApiKey;
use App\Models\User;
use App\Services\Colmexa\ColmexaException;
use App\Services\Colmexa\ColmexaPartner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Activa, sincroniza y apaga los agentes de un alojamiento en Colmexa.
 *
 * Colmexa es la fuente de verdad de "está andando"; esta tabla es el espejo
 * para el PMS. Cada sincronización manda el estado completo (los PUT de Colmexa
 * son idempotentes) y **rota la key**: el secreto en claro sólo existe durante
 * este request, y la anterior se revoca recién cuando Colmexa aceptó la nueva,
 * así un fallo a mitad de camino no deja al agente sin credencial.
 */
class AgentProvisioner
{
    public function __construct(protected ColmexaPartner $colmexa) {}

    /**
     * @param  array{greeting?: string|null, allowed_origins?: list<string>|null}  $settings
     * @param  User|null  $actor  quien activa: si es de la cuenta, queda como dueño de la bandeja
     *
     * @throws ColmexaException
     */
    public function activate(Accommodation $accommodation, AgentDefinition $definition, array $settings, ?User $actor = null): AccommodationAgent
    {
        $record = AccommodationAgent::query()->firstOrNew([
            'accommodation_id' => $accommodation->id,
            'agent' => $definition->slug,
        ]);
        $wasActive = $record->isActive();
        // A quién ya se invitó a la bandeja no lo elige el hotelero: se conserva.
        $inboxOwner = $record->settings['inbox_owner'] ?? null;
        $record->settings = array_filter([...$settings, 'inbox_owner' => $inboxOwner], fn (mixed $v): bool => $v !== null);

        $previousKeyId = $record->api_key_id;
        $key = AccommodationApiKey::generateFor($accommodation, "Colmexa · {$definition->name}", $definition->abilities);

        try {
            $this->colmexa->upsertTenant($accommodation->id, $this->tenantData($accommodation));

            // Sólo al activar (o reactivar), no en cada cambio de saludo: después
            // el equipo lo maneja el hotel desde la bandeja.
            if (! $wasActive) {
                $inboxOwner = $this->inviteInboxOwner($accommodation, $actor) ?? $inboxOwner;
            }

            $result = $this->colmexa->provisionAgent($accommodation->id, $definition->slug, $this->agentData($accommodation, $definition, $settings, $key));
        } catch (ColmexaException $e) {
            $key['model']->forceFill(['revoked_at' => now()])->save();

            // Si estaba andando, sigue andando con la key anterior: sólo falló
            // el cambio. Si era la primera vez, queda en error.
            $record->forceFill([
                'status' => $record->isActive() ? AccommodationAgent::STATUS_ACTIVE : AccommodationAgent::STATUS_ERROR,
                'last_error' => Str::limit($e->getMessage(), 490),
            ])->save();

            throw $e;
        }

        $this->revokeKey($previousKeyId);

        $record->forceFill([
            'status' => AccommodationAgent::STATUS_ACTIVE,
            'api_key_id' => $key['model']->id,
            'widget' => $this->widget($result),
            // Colmexa contesta 200 aunque el MCP no haya respondido: el error
            // viene en el cuerpo y es lo que el hotelero tiene que ver.
            'last_error' => $this->connectorError($result),
            'last_synced_at' => now(),
            'activated_at' => $record->activated_at ?? now(),
            'settings' => array_filter([...$settings, 'inbox_owner' => $inboxOwner], fn (mixed $v): bool => $v !== null),
        ])->save();

        $this->grantMonthlyCredits($accommodation);

        return $record;
    }

    /**
     * El usuario principal del hotel entra a la bandeja como owner: atiende y
     * suma a su equipo desde ahí (el resto no se sincroniza). Es quien activa,
     * si es de la cuenta; si lo activa alguien de plataforma, el usuario más
     * antiguo de la cuenta. Sin ninguno, no se invita a nadie.
     *
     * @return string|null el email invitado
     *
     * @throws ColmexaException
     */
    protected function inviteInboxOwner(Accommodation $accommodation, ?User $actor): ?string
    {
        $owner = $actor !== null && $actor->user_type === User::TYPE_ACCOUNT && (int) $actor->account_id === (int) $accommodation->account_id
            ? $actor
            : User::query()
                ->where('account_id', $accommodation->account_id)
                ->where('user_type', User::TYPE_ACCOUNT)
                ->orderBy('id')
                ->first();

        if ($owner === null || ! filled($owner->email)) {
            return null;
        }

        $this->colmexa->upsertOwner($accommodation->id, (string) $owner->email, $owner->name);

        return (string) $owner->email;
    }

    /**
     * @throws ColmexaException
     */
    public function deactivate(AccommodationAgent $record): AccommodationAgent
    {
        $this->colmexa->deactivateAgent($record->accommodation_id, $record->agent);
        $this->revokeKey($record->api_key_id);

        $record->forceFill([
            'status' => AccommodationAgent::STATUS_INACTIVE,
            'api_key_id' => null,
            'last_error' => null,
            'last_synced_at' => now(),
        ])->save();

        return $record;
    }

    /**
     * Los créditos del mes que trae el plan: el límite de `ai_assistant`, el
     * mismo número que AccommodationBillingRepository anota en el libro de
     * HotelIgnite (500 en Pro, 1000 en Premium). Idempotente por mes (la
     * referencia es `plan-YYYY-MM`): se puede llamar en cada activación y desde
     * el cron diario sin cargar dos veces.
     *
     * @throws ColmexaException
     */
    public function grantMonthlyCredits(Accommodation $accommodation): void
    {
        $amount = $accommodation->hasFeature(AccommodationApiKey::FEATURE)
            ? (int) $accommodation->featureLimit(AccommodationApiKey::FEATURE)
            : 0;

        if ($amount <= 0) {
            return;
        }

        $month = Carbon::now($this->timezone($accommodation))->format('Y-m');

        $this->colmexa->grantCredits($accommodation->id, $amount, "plan-{$month}", "Plan {$accommodation->plan?->name} · {$month}");
    }

    /**
     * @return array{name: string, timezone: string}
     */
    protected function tenantData(Accommodation $accommodation): array
    {
        return [
            'name' => (string) $accommodation->name,
            'timezone' => $this->timezone($accommodation),
        ];
    }

    /**
     * @param  array{greeting?: string|null, allowed_origins?: list<string>|null}  $settings
     * @param  array{model: AccommodationApiKey, plain: string}  $key
     * @return array<string, mixed>
     */
    protected function agentData(Accommodation $accommodation, AgentDefinition $definition, array $settings, array $key): array
    {
        $prefix = $key['model']->prefix;
        $origins = $settings['allowed_origins'] ?? [];

        return array_filter([
            'name' => $definition->name,
            'settings' => array_filter([
                'business_name' => (string) $accommodation->name,
                'greeting' => $settings['greeting'] ?? null,
            ], fn (mixed $v): bool => $v !== null && $v !== ''),
            'connector' => [
                'mcp_url' => rtrim((string) config('services.colmexa.mcp_base_url'), '/').$definition->mcpPath,
                // Colmexa arma el Bearer como `client_id.client_secret`, y el
                // middleware del MCP acepta la key partida por un punto.
                'client_id' => $prefix,
                'client_secret' => substr($key['plain'], strlen($prefix) + 1),
                'capabilities' => $definition->capabilities,
            ],
            'web' => $origins === [] ? null : ['allowed_origins' => array_values($origins)],
        ], fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{public_key: string, snippet: string, script_url: string}|null
     */
    protected function widget(array $result): ?array
    {
        $web = $result['web'] ?? null;

        if (! is_array($web) || ! is_string($web['public_key'] ?? null)) {
            return null;
        }

        return [
            'public_key' => $web['public_key'],
            'snippet' => (string) ($web['snippet'] ?? ''),
            'script_url' => (string) ($web['script_url'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function connectorError(array $result): ?string
    {
        $error = $result['connector']['last_error'] ?? null;

        return is_string($error) && $error !== ''
            ? Str::limit("El agente no pudo leer los datos del hotel: {$error}", 490)
            : null;
    }

    protected function revokeKey(?int $keyId): void
    {
        if ($keyId === null) {
            return;
        }

        AccommodationApiKey::query()
            ->whereKey($keyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    protected function timezone(Accommodation $accommodation): string
    {
        $timezone = (string) $accommodation->timezone;

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'America/Argentina/Buenos_Aires';
    }
}
