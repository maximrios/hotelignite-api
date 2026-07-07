<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AccommodationDescriptionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'               => $this->id,
            'accommodation_id' => $this->accommodation_id,
            'language_id'      => $this->language_id,
            'introduction'     => $this->introduction,
            'description'      => $this->description,
            'enabled'          => $this->enabled,
        ];
    }
}
