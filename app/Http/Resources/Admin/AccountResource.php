<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista de una Account para el panel de plataforma.
 *
 * Lista blanca explícita, y **sin `token`**: `accounts.token` es un secreto de
 * 40 caracteres que `V1\AccountResource` sí devuelve, en el índice y todo. Hoy
 * está en null en las 78 filas, así que no hay nada filtrado todavía — pero el
 * día que se empiece a usar, un resource que lo incluya lo publica solo. Si
 * alguna vez hace falta exponerlo, que sea en un endpoint dedicado y de a uno,
 * como se hizo con las API keys de client.
 */
class AccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'first_name'           => $this->first_name,
            'last_name'            => $this->last_name,
            'email'                => $this->email,
            'phone'                => $this->phone,
            'active'               => $this->active,
            'test'                 => $this->test,
            'agreement'            => $this->agreement,
            'expiration_date'      => $this->expiration_date?->toDateString(),
            'comments'             => $this->comments,
            'plan_id'              => $this->plan_id,
            'plan'                 => $this->whenLoaded('plan', fn () => $this->plan?->name),
            'account_type_id'      => $this->account_type_id,
            'account_type'         => $this->whenLoaded('accountType', fn () => $this->accountType?->name),
            // Cuánto cuelga de la cuenta. Es lo que decide si se puede dar de
            // baja, así que la UI lo necesita antes de ofrecer el botón.
            'accommodations_count' => $this->whenCounted('accommodations'),
            'users_count'          => $this->whenCounted('users'),
            'created_at'           => $this->created_at,
        ];
    }
}
