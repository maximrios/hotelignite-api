<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class ReservationResourceCollection extends ResourceCollection
{

    public $collects = ReservationResource::class;

    public function toArray($request)
    {
        return ['data' => $this->collection];
    }
}
