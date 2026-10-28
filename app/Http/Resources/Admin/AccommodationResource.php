<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class AccommodationResource extends JsonResource
{
    /**
     * Datos internos de la cuenta del hotelero: nunca son de un client.
     */
    private const INTERNAL_FIELDS = [
        'account_id', 'plan_id', 'legal_name', 'file_number', 'tax_identification',
        'channel_code', 'bank_data', 'comment', 'test', 'expiration',
    ];

    /**
     * El contacto de un alojamiento con dueño es para quien tiene el vínculo
     * aceptado (convenio, socio, adhesión). El de un borrador viene del registro
     * provincial, que es público.
     */
    private const CONTACT_FIELDS = ['email', 'phone', 'email_reservations', 'phone_reservations'];

    public function toArray($request): array
    {
        $data = $this->fields();
        $user = $request->user();

        if ($user === null || ! $user->isClient()) {
            return $data;
        }

        $data = array_diff_key($data, array_flip(self::INTERNAL_FIELDS));

        if ($this->account_id !== null && ! $this->agreedWith($user->client_id)) {
            $data = array_diff_key($data, array_flip(self::CONTACT_FIELDS));
        }

        return $data;
    }

    private function agreedWith(?int $clientId): bool
    {
        if ($clientId === null) {
            return false;
        }

        // El controller precarga sólo el pivote de este client; si no vino, se consulta.
        $client = $this->relationLoaded('clients')
            ? $this->clients->firstWhere('id', $clientId)
            : $this->clients()->where('clients.id', $clientId)->first();

        return $client?->pivot?->agreed_at !== null;
    }

    private function fields(): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'plan_id' => $this->plan_id,
            // Plan y features: el PMS decide qué módulos mostrar (la API vuelve a validar).
            'plan' => $this->whenLoaded('plan', fn () => $this->plan ? ['slug' => $this->plan->slug, 'name' => $this->plan->name] : null),
            'features' => $this->whenLoaded('plan', fn () => $this->plan?->relationLoaded('features') ? $this->plan->features->pluck('slug')->values() : []),
            'slug' => $this->slug,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'stars' => $this->stars,
            'logo' => $this->logo,
            'email' => $this->email,
            'phone' => $this->phone,
            'email_reservations' => $this->email_reservations,
            'phone_reservations' => $this->phone_reservations,
            'web' => $this->web,
            'address' => $this->address,
            'address2' => $this->address2,
            'landmark' => $this->landmark,
            'postal_code' => $this->postal_code,
            'city_id' => $this->city_id,
            'city' => $this->whenLoaded('city', fn () => $this->city?->name),
            'state_id' => $this->state_id,
            'state' => $this->whenLoaded('state', fn () => $this->state?->name),
            'type_id' => $this->type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type?->name),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'file_number' => $this->file_number,
            'tax_identification' => $this->tax_identification,
            'channel_code' => $this->channel_code,
            'currency_id' => $this->currency_id,
            'language_id' => $this->language_id,
            'timezone' => $this->timezone,
            'bank_data' => $this->bank_data,
            'comment' => $this->comment,
            'enabled' => $this->enabled,
            'active' => $this->active,
            'test' => $this->test,
            'expiration' => $this->expiration,
            'images' => $this->whenLoaded('images', fn () => $this->images),
            'room_types' => $this->whenLoaded('roomTypes', fn () => $this->roomTypes),
            'services' => $this->whenLoaded('services', fn () => $this->services),
        ];
    }
}
