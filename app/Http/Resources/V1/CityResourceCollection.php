<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class CityResourceCollection extends ResourceCollection
{

    public $collects = CityResource::class;

    public function toArray($request)
    {
        return parent::toArray($request);
        // return [
        //     'data' => $this->collection,
        //     'meta' => [
        //         'current_page' => $this->currentPage(),
        //         'per_page' => $this->perPage(),
        //         'total' => $this->total(),
        //         'last_page' => $this->lastPage(),
        //     ],
        // ];
    }
}
