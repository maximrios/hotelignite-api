<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AccommodationRatePolicyResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'               => $this->id,
            'accommodation_id' => $this->accommodation_id,
            'rate_type'        => $this->rate_type,
            'cancel_days'      => $this->cancel_days,
            'cancel_penalty'   => $this->cancel_penalty,
            'no_show_penalty'  => $this->no_show_penalty,
            'accommodation'    => $this->whenLoaded('accommodation', fn() => [
                'id'   => $this->accommodation->id,
                'name' => $this->accommodation->name,
            ]),
        ];
    }
}
