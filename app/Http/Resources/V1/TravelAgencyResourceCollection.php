<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class TravelAgencyResourceCollection extends ResourceCollection
{
    
    public $collects = TravelAgencyResource::class;

    public function toArray($request)
    {
        return parent::toArray($request);
        //return ['data' => $this->collection];
    }
}
