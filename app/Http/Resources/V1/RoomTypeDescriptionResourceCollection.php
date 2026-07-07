<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class RoomTypeDescriptionResourceCollection extends ResourceCollection
{
    
    public $collects = RoomTypeDescriptionResource::class;

    public function toArray($request)
    {
        return parent::toArray($request);
    }
}

