<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class TourResourceCollection extends ResourceCollection
{
    
    public $collects = TourResource::class;

    public function toArray($request)
    {
        return parent::toArray($request);
        //return ['data' => $this->collection];
    }
}
