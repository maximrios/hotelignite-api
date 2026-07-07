<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AccommodationPolicyResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'               => $this->id,
            'accommodation_id' => $this->accommodation_id,
            'checkin_from'     => $this->checkin_from,
            'checkin_to'       => $this->checkin_to,
            'checkout_from'    => $this->checkout_from,
            'checkout_to'      => $this->checkout_to,
            'min_age'          => $this->min_age,
            'allow_children'   => $this->allow_children,
            'children_max_age' => $this->children_max_age,
            'allow_pets'       => $this->allow_pets,
            'allow_smoking'    => $this->allow_smoking,
            'allow_parties'    => $this->allow_parties,
            'payment_card'     => $this->payment_card,
            'payment_cash'     => $this->payment_cash,
            'payment_transfer' => $this->payment_transfer,
            'payment_crypto'   => $this->payment_crypto,
            'accommodation'    => $this->whenLoaded('accommodation', fn() => [
                'id'   => $this->accommodation->id,
                'name' => $this->accommodation->name,
                'slug' => $this->accommodation->slug,
            ]),
            'translations' => $this->whenLoaded('translations', fn() =>
                AccommodationPolicyTranslationResource::collection($this->translations)
            ),
        ];
    }
}
