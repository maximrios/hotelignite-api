<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista de un User para el panel de plataforma.
 *
 * Lista blanca explícita: nunca exponer `password`, `remember_token` ni tokens
 * de Sanctum. El `$hidden` del modelo ya los cubre, pero acá se enumera campo
 * por campo para que agregar una columna sensible a la tabla no la filtre sola.
 */
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'user_type' => $this->user_type,
            'account_id' => $this->account_id,
            'client_id' => $this->client_id,
            // Acotamiento por alojamiento (pivote accommodation_user). Sólo va
            // cuando se eager-loadeó (show): en el index no se carga, así que la
            // clave directamente no aparece en el listado.
            'accommodations' => $this->whenLoaded('accommodations', fn () => $this->accommodations->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => $a->slug,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
