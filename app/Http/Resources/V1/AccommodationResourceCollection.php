<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class AccommodationResourceCollection extends ResourceCollection
{

    public $collects = AccommodationResource::class;

    public function toArray($request)
    {
        return parent::toArray($request);
        //return ['data' => $this->collection];
    }
}
