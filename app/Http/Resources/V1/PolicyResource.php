<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class PolicyResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'enabled' => $this->enabled,
            'qty' => $this->whenLoaded('accommodations', function () {
                return $this->accommodations->count();
            }, function () {
                return $this->accommodations()->count();
            }),
        ];
    }
}



