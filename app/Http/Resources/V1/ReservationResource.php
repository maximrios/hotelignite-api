<?php

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\GuestResource;
use App\Http\Resources\V1\AccommodationResource;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'accommodation' => new AccommodationResource($this->accommodation),
            'guest' => $this->guest ? new GuestResource($this->guest) : null,
            'channel' => $this->channel ? $this->channel->name : null,
            'status' => $this->status ?? '',
            'arrival' => $this->arrival,
            'departure' => $this->departure,
            'pax' => $this->pax,
            'adults' => $this->adults,
            'childrens' => $this->childrens,
        ];
    }
}
