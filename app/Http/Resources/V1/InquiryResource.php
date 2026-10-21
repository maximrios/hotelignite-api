<?php

namespace App\Http\Resources\V1;

use App\Models\Accommodation;
use App\Models\Experience;
use App\Models\Inquiry;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Consulta de un viajero. `accommodation_id` / `experience_id` se derivan del
 * morph para que el PMS, que filtra y lee por `accommodation_id`, no cambie.
 * `inquirable` resume sobre qué es (nombre y slug) cuando viene cargado.
 *
 * @mixin Inquiry
 */
class InquiryResource extends JsonResource
{
    public function toArray($request)
    {
        $type = $this->typeAlias();

        return [
            'id' => $this->id,
            'type' => $type,
            'inquirable_id' => $this->inquirable_id,
            'accommodation_id' => $type === 'accommodation' ? $this->inquirable_id : null,
            'experience_id' => $type === 'experience' ? $this->inquirable_id : null,
            'inquirable' => $this->whenLoaded('inquirable', fn () => $this->inquirable instanceof Accommodation
                || $this->inquirable instanceof Experience
                ? ['id' => $this->inquirable->id, 'name' => $this->inquirable->name, 'slug' => $this->inquirable->slug]
                : null),
            'client_id' => $this->client_id,
            'source' => $this->source ?? 'web',
            'name' => $this->name,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'phone' => $this->phone,
            'adults' => $this->adults,
            'childrens' => $this->childrens,
            'checkin' => $this->checkin,
            'checkout' => $this->checkout,
            'message' => $this->message,
            'status' => $this->status ?? 'new',
            'created_at' => $this->created_at,
        ];
    }
}
