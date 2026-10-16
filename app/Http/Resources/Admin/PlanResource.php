<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan para el selector y el editor de precios del panel.
 *
 * `price` está en centavos en la base; se expone tal cual y lo formatea quien
 * lo muestre. `enabled` viaja para poder marcar en la UI un plan discontinuado
 * que alguna cuenta todavía tiene asignado.
 */
class PlanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'subtitle' => $this->subtitle,
            'audience' => $this->audience,
            'price' => $this->price,
            'currency' => $this->currency,
            'billing_period' => $this->billing_period,
            'price_provisional' => $this->price_provisional,
            'is_public' => $this->is_public,
            'enabled' => $this->enabled,
        ];
    }
}
