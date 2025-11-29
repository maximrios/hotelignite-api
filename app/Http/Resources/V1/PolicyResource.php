<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class PolicyResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'policy_id' => $this->policy_id,
            'name' => $this->policy?->name,
            'description' => $this->description,
            'language_id' => $this->language_id,
        ];
    }
}





