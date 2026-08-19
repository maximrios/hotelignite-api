<?php

namespace App\Http\Resources\Admin;

use App\Repositories\AccommodationChannelRepository;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una fila del menú Channels del PMS. El recurso subyacente es un
 * `AccommodationChannel` que puede NO estar persistido: un canal de convenio
 * está conectado aunque el hotel todavía no le haya puesto condiciones propias.
 *
 * `source` decide qué puede hacer la UI: los de `convenio` se muestran sin
 * botón de desconectar, porque se sale del canal saliendo del padrón.
 */
class AccommodationChannelResource extends JsonResource
{
    public function toArray($request): array
    {
        $isAgreement = $this->source === AccommodationChannelRepository::SOURCE_AGREEMENT;

        return [
            'channel' => $this->whenLoaded('channel', fn () => [
                'id' => $this->channel->id,
                'name' => $this->channel->name,
                'business_type' => $this->channel->business_type,
                'connection_type' => $this->channel->connection_type,
                'code' => $this->channel->code,
                'ota' => (bool) $this->channel->ota,
            ]),

            'source' => $this->source,
            // El hotel sólo desconecta lo que conectó él.
            'can_disconnect' => ! $isAgreement,
            // `false` = conectado pero sin condiciones propias cargadas.
            'is_configured' => $this->exists,

            'enabled' => (bool) $this->enabled,
            'external_code' => $this->external_code,

            // La comisión efectiva y de dónde sale: `propia` es la negociada por
            // este hotel, `catalogo` el default del canal.
            'commission_rate' => $this->effectiveCommissionRate(),
            'commission_source' => match (true) {
                $this->commission_rate !== null => 'propia',
                $this->channel?->commission_rate !== null => 'catalogo',
                default => null,
            },
            'own_commission_rate' => $this->commission_rate,

            'client' => $this->when($this->client_id !== null, fn () => [
                'id' => $this->client_id,
                'name' => $this->client_name,
            ]),

            'connected_at' => $this->created_at,
        ];
    }
}
