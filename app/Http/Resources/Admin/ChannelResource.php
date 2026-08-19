<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista de un Channel para el CRM y el PMS. El catálogo es global y lo
 * administra la plataforma: ningún hotel crea canales propios (si no,
 * "Booking.com" se duplica una vez por alojamiento y el reporting cruzado
 * deja de existir).
 *
 * `is_client_backed` es la distinción que necesita la UI: un canal con client
 * detrás no se conecta por autoservicio —se entra por invitación al padrón—,
 * así que no va en la vidriera de "disponibles para conectar".
 */
class ChannelResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'business_type' => $this->business_type,
            'connection_type' => $this->connection_type,
            'code' => $this->code,
            // Las tres columnas son `NOT NULL DEFAULT '0'` en el dump legacy,
            // así que "sin dato" está guardado como el literal "0". Sin esto el
            // formulario del CRM abre con un 0 en el campo de email.
            'email' => $this->blankIfLegacyZero($this->email),
            'phone' => $this->blankIfLegacyZero($this->phone),
            'web' => $this->blankIfLegacyZero($this->web),
            'ota' => (bool) $this->ota,
            'commission_rate' => $this->commission_rate,
            'enabled' => (bool) $this->enabled,

            'accommodations_count' => $this->whenCounted('accommodations'),
            'clients_count' => $this->whenCounted('clients'),
            'is_client_backed' => $this->when(
                $this->clients_count !== null,
                fn () => $this->clients_count > 0
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function blankIfLegacyZero(?string $value): ?string
    {
        return ($value === null || $value === '' || $value === '0') ? null : $value;
    }
}
