<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'accommodation' => new AccommodationResource( $this->accommodation ),
            'tour' => new TourResource( $this->tour ),
            'checkin' => $this->checkin,
            'checkout' => $this->checkout,
            'adults' => $this->adults,
            'childrens' => $this->childrens,
        ];
    }
}
