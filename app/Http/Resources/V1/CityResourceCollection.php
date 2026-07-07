<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class CityResourceCollection extends ResourceCollection
{

    public $collects = CityResource::class;

    public function toArray($request)
    {
        return $this->collection;
    }
}
