<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista de un Client para el panel de plataforma. Incluye tier, keys (metadata)
 * y accommodations relacionados cuando están cargados.
 */
class ClientResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'email' => $this->email,
            'phone' => $this->phone,
            'active' => (bool) $this->active,
            'rate_limit_per_minute' => $this->rate_limit_per_minute,
            'effective_rate_limit' => $this->rateLimit(),
            'accommodations_count' => $this->whenCounted('accommodations'),
            'accommodations' => $this->whenLoaded('accommodations', fn () => $this->accommodations->map(fn ($a) => [
                'id' => $a->id,
                'slug' => $a->slug,
                'name' => $a->name,
            ])),
            'api_keys' => ClientApiKeyResource::collection($this->whenLoaded('apiKeys')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
