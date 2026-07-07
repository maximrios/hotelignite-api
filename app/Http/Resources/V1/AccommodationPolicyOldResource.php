<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AccommodationPolicyOldResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'accommodation_id' => $this->accommodation_id,
            'policy_id' => $this->policy_id,
            'language_id' => $this->language_id,
            'description' => $this->description,
            'accommodation' => $this->whenLoaded('accommodation', function () {
                return [
                    'id' => $this->accommodation->id,
                    'name' => $this->accommodation->name,
                    'slug' => $this->accommodation->slug,
                ];
            }),
            'policy' => $this->whenLoaded('policy', function () {
                return [
                    'id' => $this->policy->id,
                    'name' => $this->policy->name ?? null,
                ];
            }),
        ];
    }
}
