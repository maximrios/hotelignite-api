<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class TravelAgencyResource extends JsonResource
{
    
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'logo' => $this->logo,
            'name' => $this->name,
            'identity' => $this->identity
        ];
    }
}
