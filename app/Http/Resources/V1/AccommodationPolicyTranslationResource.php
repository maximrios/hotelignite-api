<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AccommodationPolicyTranslationResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'policy_id'   => $this->policy_id,
            'language_id' => $this->language_id,
            'house_rules' => $this->house_rules,
        ];
    }
}
