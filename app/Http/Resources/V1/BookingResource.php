<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    
    public function toArray($request)
    {
        return [
            'id' => $this->id,
        ];
    }
}
