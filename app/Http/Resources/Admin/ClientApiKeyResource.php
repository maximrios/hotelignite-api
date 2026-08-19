<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadata de una API key para el panel de plataforma. NUNCA expone el secreto
 * en claro ni el hash — sólo el prefijo visible y el estado.
 */
class ClientApiKeyResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'abilities' => $this->abilities ?? [],
            'last_used_at' => $this->last_used_at,
            'expires_at' => $this->expires_at,
            'revoked_at' => $this->revoked_at,
            'active' => $this->isActive(),
            'created_at' => $this->created_at,
        ];
    }
}
