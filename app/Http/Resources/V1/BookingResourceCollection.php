<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class BookingResourceCollection extends ResourceCollection
{

    public $collects = BookingResource::class;

    public function toArray($request)
    {
        //return parent::toArray($request);
        return ['data' => $this->collection];
    }
}
