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
            // Invitación de acceso más reciente (email de bienvenida). Null si el
            // user nunca fue invitado (alta con contraseña manual).
            'invitation' => $this->whenLoaded('latestInvitation', fn () => $this->latestInvitation ? [
                'status' => $this->latestInvitation->status(),
                'sent_at' => $this->latestInvitation->sent_at,
                'opened_at' => $this->latestInvitation->opened_at,
                'reminded_at' => $this->latestInvitation->reminded_at,
                'accepted_at' => $this->latestInvitation->accepted_at,
                'expires_at' => $this->latestInvitation->expires_at,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
