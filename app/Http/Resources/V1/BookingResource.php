<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'accommodation' => $this->accommodation->name,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
        ];
    }
}
