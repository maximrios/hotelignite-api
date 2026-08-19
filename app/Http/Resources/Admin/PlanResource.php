<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan para el selector del panel. Lo mínimo para elegir uno.
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
            'id'       => $this->id,
            'name'     => $this->name,
            'slug'     => $this->slug,
            'price'    => $this->price,
            'currency' => $this->currency,
            'enabled'  => $this->enabled,
        ];
    }
}
