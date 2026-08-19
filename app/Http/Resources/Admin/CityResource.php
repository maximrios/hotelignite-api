<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ciudad para selectores del panel.
 *
 * Deliberadamente más chico que `V1\CityResource`: ese incluye `images`, que en
 * un combobox que dispara una request por búsqueda es payload puro sin uso.
 *
 * `state` viene resuelto porque hay nombres de ciudad repetidos entre provincias
 * y sin la provincia el que elige no puede desambiguar.
 */
class CityResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'slug'     => $this->slug,
            'state_id' => $this->state_id ?: null,
            'state'    => $this->whenLoaded('state', fn () => $this->state?->name),
        ];
    }
}
