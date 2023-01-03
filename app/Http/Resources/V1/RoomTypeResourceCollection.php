<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class RoomTypeResourceCollection extends ResourceCollection
{
    
    public $collects = RoomTypeResource::class;

    public function toArray($request)
    {
        return parent::toArray($request);
    }
}
